<?php

namespace App\Services;

use App\Enums\AccountSecurityEventType;
use App\Enums\NotificationCategory;
use App\Enums\NotificationType;
use App\Enums\SessionRevocationReason;
use App\Exceptions\ConflictException;
use App\Models\AccountSecurityEvent;
use App\Models\AccountSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AccountSessionService
{
    public function __construct(
        protected ?NotificationService $notificationService = null
    ) {
        $this->notificationService = $notificationService ?? app(NotificationService::class);
    }

    /**
     * Register or update an active session for the user.
     */
    public function registerSession(User $user, Request $request, ?string $sessionId = null): AccountSession
    {
        $rawSessionId = $sessionId ?: ($request->hasSession() ? $request->session()->getId() : null);

        if (!$rawSessionId) {
            $rawSessionId = 'stateless_' . bin2hex(random_bytes(16));
        }

        $sessionIdentifier = hash('sha256', $rawSessionId);
        $userAgent = (string) $request->userAgent();
        $parsed = $this->parseUserAgent($userAgent);
        $ipHash = hash_hmac('sha256', (string) $request->ip(), config('app.key', 'Digicardo-salt'));

        /** @var AccountSession $accountSession */
        $accountSession = AccountSession::updateOrCreate(
            [
                'user_id' => $user->id,
                'session_identifier' => $sessionIdentifier,
            ],
            [
                'session_id' => $rawSessionId,
                'ip_hash' => $ipHash,
                'user_agent' => substr($userAgent, 0, 500),
                'device_name' => $parsed['device_name'],
                'browser' => $parsed['browser'],
                'platform' => $parsed['platform'],
                'last_activity_at' => now(),
                'revoked_at' => null,
                'revoked_reason' => null,
            ]
        );

        return $accountSession;
    }

    /**
     * Touch session last activity.
     */
    public function updateLastActivity(User $user, Request $request): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $sessionId = $request->session()->getId();
        $sessionIdentifier = hash('sha256', $sessionId);

        AccountSession::where('user_id', $user->id)
            ->where('session_identifier', $sessionIdentifier)
            ->whereNull('revoked_at')
            ->update(['last_activity_at' => now()]);
    }

    /**
     * List all active sessions for the user with is_current flag.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listActiveSessions(User $user, ?string $currentSessionId = null): Collection
    {
        $currentHash = $currentSessionId ? hash('sha256', $currentSessionId) : null;

        $sessions = AccountSession::where('user_id', $user->id)
            ->active()
            ->orderByDesc('last_activity_at')
            ->get();

        return $sessions->map(function (AccountSession $session) use ($currentHash) {
            return [
                'id' => $session->id,
                'device_name' => $session->device_name ?: 'Unknown Device',
                'browser' => $session->browser ?: 'Unknown Browser',
                'platform' => $session->platform ?: 'Unknown OS',
                'last_activity_at' => $session->last_activity_at?->toIso8601String(),
                'created_at' => $session->created_at?->toIso8601String(),
                'is_current' => $currentHash !== null && $session->session_identifier === $currentHash,
            ];
        });
    }

    /**
     * Revoke an individual session belonging to the user.
     *
     * @throws ValidationException
     */
    public function revokeSession(
        User $user,
        string $accountSessionId,
        ?string $currentSessionId = null,
        string $reason = 'user_revoked'
    ): AccountSession {
        /** @var AccountSession $session */
        $session = AccountSession::where('user_id', $user->id)
            ->where('id', $accountSessionId)
            ->firstOrFail();

        $currentHash = $currentSessionId ? hash('sha256', $currentSessionId) : null;

        if ($currentHash !== null && $session->session_identifier === $currentHash) {
            throw ValidationException::withMessages([
                'session' => ['Current session cannot be revoked through this endpoint. Please use logout instead.'],
            ]);
        }

        $session->update([
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ]);

        // Invalidate actual Laravel session in database
        if ($session->session_id) {
            try {
                DB::table('sessions')->where('id', $session->session_id)->delete();
            } catch (\Throwable $e) {
                Log::warning('[AccountSessionService] Could not delete session from DB table', ['error' => $e->getMessage()]);
            }
        }

        // Record security audit event
        AccountSecurityEvent::create([
            'user_id' => $user->id,
            'event_type' => AccountSecurityEventType::SessionRevoked,
            'metadata' => [
                'device' => $session->device_name,
                'browser' => $session->browser,
                'platform' => $session->platform,
            ],
            'created_at' => now(),
        ]);

        // Send security notification
        try {
            $this->notificationService->createForUser(
                user: $user,
                type: NotificationType::SystemAlert,
                category: NotificationCategory::Security,
                title: 'Device Session Revoked',
                body: "A session on {$session->browser} ({$session->platform}) was recently revoked from your account.",
                data: ['session_id' => $session->id],
                sendEmail: true
            );
        } catch (\Throwable $e) {
            Log::warning('[AccountSessionService] Failed to send session revoked notification', ['error' => $e->getMessage()]);
        }

        return $session->fresh();
    }

    /**
     * Revoke all sessions for the user except the current one.
     */
    public function revokeOtherSessions(
        User $user,
        string $currentSessionId,
        string $reason = 'revoke_all_others'
    ): int {
        $currentHash = hash('sha256', $currentSessionId);

        $otherSessions = AccountSession::where('user_id', $user->id)
            ->where('session_identifier', '!=', $currentHash)
            ->active()
            ->get();

        $count = $otherSessions->count();

        if ($count === 0) {
            return 0;
        }

        $sessionIdsToDelete = $otherSessions->pluck('session_id')->filter()->values()->all();

        // Invalidate Laravel database sessions
        if (!empty($sessionIdsToDelete)) {
            try {
                DB::table('sessions')->whereIn('id', $sessionIdsToDelete)->delete();
            } catch (\Throwable $e) {
                Log::warning('[AccountSessionService] Failed deleting batch sessions', ['error' => $e->getMessage()]);
            }
        }

        // Update AccountSession records to revoked
        AccountSession::where('user_id', $user->id)
            ->where('session_identifier', '!=', $currentHash)
            ->active()
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => $reason,
            ]);

        // Record security audit event
        AccountSecurityEvent::create([
            'user_id' => $user->id,
            'event_type' => AccountSecurityEventType::AllOtherSessionsRevoked,
            'metadata' => ['revoked_count' => $count],
            'created_at' => now(),
        ]);

        // Send security notification
        try {
            $this->notificationService->createForUser(
                user: $user,
                type: NotificationType::SystemAlert,
                category: NotificationCategory::Security,
                title: 'All Other Sessions Revoked',
                body: "All other active sessions ({$count} device(s)) were revoked from your account.",
                data: ['count' => $count],
                sendEmail: true
            );
        } catch (\Throwable $e) {
            Log::warning('[AccountSessionService] Failed sending revoke-others notification', ['error' => $e->getMessage()]);
        }

        return $count;
    }

    /**
     * Revoke all sessions for a user (e.g. on account deletion).
     */
    public function revokeAllSessions(User $user, string $reason = 'account_deleted'): int
    {
        try {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        } catch (\Throwable $e) {
            Log::warning('[AccountSessionService] Failed deleting all sessions', ['error' => $e->getMessage()]);
        }

        return AccountSession::where('user_id', $user->id)
            ->active()
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => $reason,
            ]);
    }

    /**
     * Check if a session identifier is marked revoked.
     */
    public function isSessionRevoked(string $sessionId): bool
    {
        $identifier = hash('sha256', $sessionId);

        return AccountSession::where('session_identifier', $identifier)
            ->whereNotNull('revoked_at')
            ->exists();
    }

    /**
     * Parse User-Agent into safe display device, browser, and platform names.
     *
     * @return array{device_name: string, browser: string, platform: string}
     */
    public function parseUserAgent(?string $ua): array
    {
        if (!$ua) {
            return [
                'device_name' => 'Unknown Device',
                'browser' => 'Web Browser',
                'platform' => 'Unknown OS',
            ];
        }

        $browser = 'Web Browser';
        if (preg_match('/Edg(?:e|A|iOS)?\/([0-9.]+)/i', $ua)) {
            $browser = 'Microsoft Edge';
        } elseif (preg_match('/Chrome\/([0-9.]+)/i', $ua) && !preg_match('/Chromium|Edg/i', $ua)) {
            $browser = 'Google Chrome';
        } elseif (preg_match('/Safari\/([0-9.]+)/i', $ua) && !preg_match('/Chrome|Chromium/i', $ua)) {
            $browser = 'Apple Safari';
        } elseif (preg_match('/Firefox\/([0-9.]+)/i', $ua)) {
            $browser = 'Mozilla Firefox';
        } elseif (preg_match('/Opera|OPR\/([0-9.]+)/i', $ua)) {
            $browser = 'Opera';
        }

        $platform = 'Unknown OS';
        if (preg_match('/Windows NT 10/i', $ua)) {
            $platform = 'Windows 10/11';
        } elseif (preg_match('/Windows NT/i', $ua)) {
            $platform = 'Windows';
        } elseif (preg_match('/iPhone/i', $ua)) {
            $platform = 'iOS (iPhone)';
        } elseif (preg_match('/iPad/i', $ua)) {
            $platform = 'iPadOS';
        } elseif (preg_match('/Macintosh|Mac OS X/i', $ua)) {
            $platform = 'macOS';
        } elseif (preg_match('/Android/i', $ua)) {
            $platform = 'Android';
        } elseif (preg_match('/Linux/i', $ua)) {
            $platform = 'Linux';
        }

        $device = 'Desktop';
        if (preg_match('/Mobile|iPhone|Android.*Mobile/i', $ua)) {
            $device = 'Mobile Device';
        } elseif (preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $ua)) {
            $device = 'Tablet';
        }

        return [
            'device_name' => $device,
            'browser' => $browser,
            'platform' => $platform,
        ];
    }
}
