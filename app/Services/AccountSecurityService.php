<?php

namespace App\Services;

use App\Enums\AccountSecurityEventType;
use App\Enums\NotificationCategory;
use App\Enums\NotificationType;
use App\Enums\SessionRevocationReason;
use App\Exceptions\ConflictException;
use App\Exceptions\RecentAuthRequiredException;
use App\Models\AccountSecurityEvent;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AccountSecurityService
{
    public function __construct(
        protected AccountSessionService $sessionService,
        protected NotificationService $notificationService,
        protected AuthEmailService $authEmailService,
        protected AuthService $authService,
        protected ?DomainService $domainService = null
    ) {
        $this->domainService = $domainService ?? app(DomainService::class);
    }

    /**
     * Check if request has recent authentication timestamp within the allowed window.
     */
    public function isRecentlyAuthenticated(Request $request): bool
    {
        if (!$request->hasSession()) {
            return false;
        }

        $timestamp = $request->session()->get('auth.recent_authenticated_at');

        if (!$timestamp) {
            return false;
        }

        $windowMinutes = (int) config('services.security.recent_auth_minutes', 15);
        $maxAgeSeconds = $windowMinutes * 60;

        return (now()->timestamp - $timestamp) <= $maxAgeSeconds;
    }

    /**
     * Enforce recent authentication or throw HTTP 403 RecentAuthRequiredException.
     *
     * @throws RecentAuthRequiredException
     */
    public function ensureRecentAuth(Request $request): void
    {
        if (!$this->isRecentlyAuthenticated($request)) {
            throw new RecentAuthRequiredException();
        }
    }

    /**
     * Confirm password for recent authentication step-up.
     *
     * @throws ValidationException
     */
    public function confirmRecentAuth(User $user, string $password, Request $request): bool
    {
        if (!Hash::check($password, $user->password)) {
            AccountSecurityEvent::create([
                'user_id' => $user->id,
                'event_type' => AccountSecurityEventType::LoginFailed,
                'metadata' => ['action' => 'recent_auth_failed'],
                'ip_hash' => hash_hmac('sha256', (string) $request->ip(), config('app.key', 'Digicardo-salt')),
                'created_at' => now(),
            ]);

            throw ValidationException::withMessages([
                'password' => ['The provided password does not match our records.'],
            ]);
        }

        if ($request->hasSession()) {
            $request->session()->put('auth.recent_authenticated_at', now()->timestamp);
        }

        AccountSecurityEvent::create([
            'user_id' => $user->id,
            'event_type' => AccountSecurityEventType::RecentAuthConfirmed,
            'metadata' => ['timestamp' => now()->toIso8601String()],
            'ip_hash' => hash_hmac('sha256', (string) $request->ip(), config('app.key', 'Digicardo-salt')),
            'created_at' => now(),
        ]);

        return true;
    }

    /**
     * Change user password, revoke all other active sessions, and notify.
     *
     * @throws ValidationException
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword, Request $request): void
    {
        // 1. Verify current password
        if (!Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password provided is incorrect.'],
            ]);
        }

        // 2. Reject identical new password
        if (Hash::check($newPassword, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Your new password cannot be the same as your current password.'],
            ]);
        }

        // 3. Update password in database
        $user->forceFill([
            'password' => Hash::make($newPassword),
        ])->save();

        // 4. Invalidate all personal access tokens if using Sanctum
        $user->tokens()->delete();

        // 5. Revoke all OTHER active sessions
        $currentSessionId = $request->hasSession() ? $request->session()->getId() : 'current_' . bin2hex(random_bytes(8));
        $this->sessionService->revokeOtherSessions(
            $user,
            $currentSessionId,
            SessionRevocationReason::PasswordChanged->value
        );

        // 6. Regenerate session to prevent fixation and update recent auth timestamp
        if ($request->hasSession()) {
            $request->session()->regenerate();
            $request->session()->put('auth.recent_authenticated_at', now()->timestamp);
            $this->sessionService->registerSession($user, $request);
        }

        // 7. Record security audit event
        AccountSecurityEvent::create([
            'user_id' => $user->id,
            'event_type' => AccountSecurityEventType::PasswordChanged,
            'metadata' => ['changed_at' => now()->toIso8601String()],
            'ip_hash' => hash_hmac('sha256', (string) $request->ip(), config('app.key', 'Digicardo-salt')),
            'created_at' => now(),
        ]);

        // 8. Dispatch security notification
        try {
            $this->notificationService->createForUser(
                user: $user,
                type: NotificationType::SystemAlert,
                category: NotificationCategory::Security,
                title: 'Password Changed Successfully',
                body: 'Your Digicardo account password was changed. All other device sessions were automatically signed out.',
                data: ['changed_at' => now()->toIso8601String()],
                sendEmail: true
            );
        } catch (\Throwable $e) {
            Log::warning('[AccountSecurityService] Failed to send password changed notification', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Update account details (name and/or email).
     *
     * @param array{name?: string, email?: string} $data
     * @throws ValidationException
     */
    public function updateAccount(User $user, array $data, Request $request): User
    {
        $hasChanges = false;
        $emailChanged = false;

        if (isset($data['name']) && $data['name'] !== $user->name) {
            $user->name = trim($data['name']);
            $hasChanges = true;
        }

        if (isset($data['email'])) {
            $normalizedEmail = strtolower(trim($data['email']));
            if ($normalizedEmail !== $user->email) {
                // Ensure email uniqueness across active and soft-deleted users
                if (User::where('email', $normalizedEmail)->where('id', '!=', $user->id)->exists()) {
                    throw ValidationException::withMessages([
                        'email' => ['The email address is already in use by another account.'],
                    ]);
                }

                $oldEmail = $user->email;
                $user->email = $normalizedEmail;
                $user->email_verified_at = null;
                $emailChanged = true;
                $hasChanges = true;
            }
        }

        if ($hasChanges) {
            $user->save();

            // Record security audit event
            AccountSecurityEvent::create([
                'user_id' => $user->id,
                'event_type' => $emailChanged ? AccountSecurityEventType::EmailChanged : AccountSecurityEventType::AccountDetailsUpdated,
                'metadata' => [
                    'email_changed' => $emailChanged,
                    'name_updated' => isset($data['name']),
                ],
                'ip_hash' => hash_hmac('sha256', (string) $request->ip(), config('app.key', 'Digicardo-salt')),
                'created_at' => now(),
            ]);

            // If email changed, dispatch new verification link & security notification
            if ($emailChanged) {
                try {
                    $this->authEmailService->sendVerificationNotification($user);

                    $this->notificationService->createForUser(
                        user: $user,
                        type: NotificationType::SystemAlert,
                        category: NotificationCategory::Security,
                        title: 'Account Email Updated',
                        body: "Your Digicardo email address was changed to {$user->email}. Please check your inbox to verify your new email.",
                        data: ['new_email' => $user->email],
                        sendEmail: true
                    );
                } catch (\Throwable $e) {
                    Log::warning('[AccountSecurityService] Failed dispatching email changed verification', ['error' => $e->getMessage()]);
                }
            }
        }

        return $user->fresh();
    }

    /**
     * Safely delete the user account and cascade soft deletes.
     *
     * @throws ValidationException
     * @throws ConflictException
     * @throws RecentAuthRequiredException
     */
    public function deleteAccount(User $user, string $currentPassword, string $confirmationPhrase, Request $request): void
    {
        // 1. Enforce recent authentication
        $this->ensureRecentAuth($request);

        // 2. Validate current password
        if (!Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The password entered is incorrect.'],
            ]);
        }

        // 3. Validate exact confirmation phrase
        $expectedPhrase = config('services.security.deletion_phrase', 'DELETE MY ACCOUNT');
        if (trim($confirmationPhrase) !== $expectedPhrase) {
            throw ValidationException::withMessages([
                'confirmation' => ["Please type '{$expectedPhrase}' exactly to confirm account deletion."],
            ]);
        }

        // 4. Subscription conflict policy:
        // If user has an active paid subscription without cancellation scheduled, block deletion with 409
        $hasActivePaidSub = $user->subscriptions()
            ->whereIn('status', ['active', 'trialing'])
            ->where('cancel_at_period_end', false)
            ->exists();

        if ($hasActivePaidSub) {
            throw new \App\Exceptions\AccountDeletionBlockedException();
        }

        // 5. Record security audit events
        AccountSecurityEvent::create([
            'user_id' => $user->id,
            'event_type' => AccountSecurityEventType::AccountDeletionRequested,
            'metadata' => ['deleted_at' => now()->toIso8601String()],
            'ip_hash' => hash_hmac('sha256', (string) $request->ip(), config('app.key', 'Digicardo-salt')),
            'created_at' => now(),
        ]);

        // 6. Send security notification
        try {
            $this->notificationService->createForUser(
                user: $user,
                type: NotificationType::SystemAlert,
                category: NotificationCategory::Security,
                title: 'Account Deleted',
                body: 'Your Digicardo account and all associated profile data have been permanently scheduled for deletion.',
                data: ['deleted_at' => now()->toIso8601String()],
                sendEmail: true
            );
        } catch (\Throwable $e) {
            Log::warning('[AccountSecurityService] Failed sending deletion notice', ['error' => $e->getMessage()]);
        }

        // 7. Application-managed cascade soft deletion
        DB::transaction(function () use ($user) {
            // Invalidate and delete custom domains
            foreach ($user->domains as $domain) {
                try {
                    $this->domainService->deleteDomain($domain);
                } catch (\Throwable $e) {
                    $domain->delete();
                }
            }

            // Soft delete user profile and blocks
            if ($user->profile) {
                $user->profile->blocks()->delete();
                $user->profile->media()->delete();
                $user->profile->delete();
            }

            // Revoke all sessions
            $this->sessionService->revokeAllSessions($user, SessionRevocationReason::AccountDeleted->value);

            // Record AccountDeleted security event
            AccountSecurityEvent::create([
                'user_id' => $user->id,
                'event_type' => AccountSecurityEventType::AccountDeleted,
                'metadata' => ['deleted_at' => now()->toIso8601String()],
                'created_at' => now(),
            ]);

            // Invalidate Sanctum tokens
            $user->tokens()->delete();

            // Soft-delete user
            $user->delete();
        });

        // 8. Invalidate current session and logout
        $this->authService->logout();
    }

    /**
     * Get paginated security events for user.
     */
    public function getSecurityEvents(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return AccountSecurityEvent::where('user_id', $user->id)
            ->latest('created_at')
            ->paginate($perPage);
    }
}
