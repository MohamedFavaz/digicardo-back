<?php

namespace App\Services;

use App\Enums\AccountSecurityEventType;
use App\Enums\SessionRevocationReason;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AccountSecurityEvent;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthService
{
    public function __construct(
        protected ?AuthEmailService $authEmailService = null,
        protected ?AccountSessionService $accountSessionService = null
    ) {
        $this->authEmailService = $authEmailService ?? app(AuthEmailService::class);
        $this->accountSessionService = $accountSessionService ?? app(AccountSessionService::class);
    }

    /**
     * Register a new user account and initiate session.
     */
    public function register(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => strtolower(trim($data['email'])),
            'password' => Hash::make($data['password']),
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        Auth::guard('web')->login($user);

        $request = request();
        if ($request->hasSession()) {
            $request->session()->regenerate();
            $request->session()->put('auth.recent_authenticated_at', now()->timestamp);
            $this->accountSessionService->registerSession($user, $request);
        }

        // Record security audit event
        AccountSecurityEvent::create([
            'user_id' => $user->id,
            'event_type' => AccountSecurityEventType::LoginSuccess,
            'metadata' => ['action' => 'register'],
            'ip_hash' => hash_hmac('sha256', (string) $request->ip(), config('app.key', 'Digicardo-salt')),
            'created_at' => now(),
        ]);

        // Asynchronously queue verification & welcome notifications
        try {
            $this->authEmailService->sendVerificationNotification($user);
            $this->authEmailService->sendWelcomeNotification($user);
        } catch (\Throwable $e) {
            Log::warning('[AuthService] Failed to dispatch registration notifications', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $user;
    }

    /**
     * Authenticate user credentials and regenerate session to prevent session fixation.
     *
     * @throws AuthenticationException
     */
    public function login(array $credentials): User
    {
        $loginInput = strtolower(trim($credentials['email']));
        $request = request();

        // Support login by email OR username
        $targetEmail = $loginInput;
        if (!str_contains($loginInput, '@')) {
            $userByUsername = User::whereHas('profile', function ($query) use ($loginInput) {
                $query->where('username', $loginInput);
            })->first();

            if ($userByUsername) {
                $targetEmail = $userByUsername->email;
            }
        }

        $authenticated = Auth::guard('web')->attempt([
            'email' => $targetEmail,
            'password' => $credentials['password'],
        ]);

        if (!$authenticated) {
            // Check if user exists to record failed login security event safely
            $existingUser = User::where('email', $targetEmail)->first();
            if ($existingUser) {
                AccountSecurityEvent::create([
                    'user_id' => $existingUser->id,
                    'event_type' => AccountSecurityEventType::LoginFailed,
                    'metadata' => ['reason' => 'invalid_credentials'],
                    'ip_hash' => hash_hmac('sha256', (string) $request->ip(), config('app.key', 'Digicardo-salt')),
                    'created_at' => now(),
                ]);
            }

            throw new AuthenticationException('Invalid email, username, or password.');
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();

        // Enforce account status server-side
        $statusValue = $user->status instanceof UserStatus ? $user->status->value : (string) $user->status;
        if ($statusValue !== 'active') {
            Auth::guard('web')->logout();
            throw new AuthenticationException('Your account is currently inactive or suspended. Please contact your administrator.');
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
            $request->session()->put('auth.recent_authenticated_at', now()->timestamp);
            $this->accountSessionService->registerSession($user, $request);
        }

        // Record security audit event
        AccountSecurityEvent::create([
            'user_id' => $user->id,
            'event_type' => AccountSecurityEventType::LoginSuccess,
            'metadata' => ['action' => 'login'],
            'ip_hash' => hash_hmac('sha256', (string) $request->ip(), config('app.key', 'Digicardo-salt')),
            'created_at' => now(),
        ]);

        return $user;
    }

    /**
     * Terminate the authenticated session and invalidate cookies.
     */
    public function logout(): void
    {
        $user = Auth::guard('web')->user() ?? request()->user();
        $request = request();

        if ($user && $request->hasSession()) {
            $sessionId = $request->session()->getId();
            $sessionIdentifier = hash('sha256', $sessionId);

            // Mark session as revoked/ended
            \App\Models\AccountSession::where('user_id', $user->id)
                ->where('session_identifier', $sessionIdentifier)
                ->active()
                ->update([
                    'revoked_at' => now(),
                    'revoked_reason' => SessionRevocationReason::UserRevoked->value,
                ]);

            AccountSecurityEvent::create([
                'user_id' => $user->id,
                'event_type' => AccountSecurityEventType::Logout,
                'metadata' => ['action' => 'logout'],
                'ip_hash' => hash_hmac('sha256', (string) $request->ip(), config('app.key', 'Digicardo-salt')),
                'created_at' => now(),
            ]);
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    /**
     * Get the current authenticated user.
     *
     * @throws AuthenticationException
     */
    public function me(): User
    {
        $user = Auth::guard('web')->user() ?? request()->user();

        if (!$user) {
            throw new AuthenticationException('Unauthenticated.');
        }

        // Touch last activity on session
        $this->accountSessionService->updateLastActivity($user, request());

        return $user;
    }
}
