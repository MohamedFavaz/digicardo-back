<?php

namespace App\Services;

use App\Enums\AccountSecurityEventType;
use App\Enums\ModerationActionType;
use App\Enums\SessionRevocationReason;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AccountSecurityEvent;
use App\Models\AdminAuditLog;
use App\Models\AdminSale;
use App\Models\ModerationAction;
use App\Models\User;
use App\Models\ValidityPlan;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AdminUserService
{
    public function __construct(
        protected AdminAuditService $auditService,
        protected ModerationActionService $moderationService,
        protected AccountSessionService $sessionService,
        protected NotificationService $notificationService
    ) {
    }

    /**
     * List users with search, filtering, and safe sorting.
     *
     * @param array<string, mixed> $filters
     * @param int $page
     * @param int $perPage
     * @param string $sortBy
     * @param string $sortDir
     * @return LengthAwarePaginator
     */
    public function listUsers(
        array $filters = [],
        int $page = 1,
        int $perPage = 15,
        string $sortBy = 'created_at',
        string $sortDir = 'desc'
    ): LengthAwarePaginator {
        $allowedSorts = ['created_at', 'name', 'email', 'status', 'role'];
        $sortColumn = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'created_at';
        $direction = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

        $query = User::with(['profile:id,user_id,username,display_name,is_public,moderation_status'])
            ->withCount(['accountSessions as active_sessions_count' => fn($q) => $q->whereNull('revoked_at')])
            ->select('users.*');

        // Search
        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('id', $search);
            });
        }
        if (!empty($filters['role']))
            $query->where('role', $filters['role']);
        if (!empty($filters['status']))
            $query->where('status', $filters['status']);
        if (!empty($filters['expiry'])) {
            if ($filters['expiry'] === 'expired') {
                $query->where('expires_at', '<', now());
            } elseif ($filters['expiry'] === 'expiring_soon') {
                $query->whereBetween('expires_at', [now(), now()->addDays(7)]);
            }
        }
        if (isset($filters['email_verified'])) {
            if ($filters['email_verified'] === 'true')
                $query->whereNotNull('email_verified_at');
            if ($filters['email_verified'] === 'false')
                $query->whereNull('email_verified_at');
        }

        return $query->orderBy($sortColumn, $direction)->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Get rich user details for administrative review.
     *
     * @param string $id
     * @return array<string, mixed>
     * @throws NotFoundHttpException
     */
    public function getUserDetails(string $id): array
    {
        $user = User::with([
            'profile.domains',
            'profile.blocks',
            'subscriptions.plan',
        ])->find($id);

        if (!$user) {
            throw new NotFoundHttpException("User with ID {$id} not found.");
        }

        $moderationHistory = ModerationAction::where('target_type', 'user')
            ->where('target_id', $user->id)
            ->with('actor:id,name,email,role')
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        $auditLogs = AdminAuditLog::where('target_type', 'user')
            ->where('target_id', $user->id)
            ->with('actor:id,name,email,role')
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role instanceof UserRole ? $user->role->value : $user->role,
                'status' => $user->status instanceof UserStatus ? $user->status->value : $user->status,
                'is_email_verified' => $user->email_verified_at !== null,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'expires_at' => $user->expires_at?->toIso8601String(),
                'validity_months' => $user->validity_months,
                'validity_label' => $user->validity_label,
                'plan_price_paid' => $user->plan_price_paid ? (float) $user->plan_price_paid : null,
                'days_remaining' => $user->expires_at ? max(0, now()->diffInDays($user->expires_at, false)) : null,
                'is_expired' => $user->expires_at ? $user->expires_at->isPast() : false,
                'created_at' => $user->created_at->toIso8601String(),
                'updated_at' => $user->updated_at->toIso8601String(),
            ],
            'profile' => $user->profile ? [
                'id' => $user->profile->id,
                'username' => $user->profile->username,
                'display_name' => $user->profile->display_name,
                'is_public' => $user->profile->is_public,
                'moderation_status' => $user->profile->moderation_status instanceof \App\Enums\ProfileModerationStatus ? $user->profile->moderation_status->value : ($user->profile->moderation_status ?? 'active'),
                'blocks_count' => $user->profile->blocks->count(),
                'domains_count' => $user->profile->domains->count(),
            ] : null,
            'subscription' => $user->subscriptions->first() ? [
                'plan_name' => $user->subscriptions->first()->plan->name ?? 'Unknown',
                'plan_code' => $user->subscriptions->first()->plan->code ?? 'free',
                'status' => $user->subscriptions->first()->status,
                'current_period_end' => $user->subscriptions->first()->current_period_end?->toIso8601String(),
            ] : null,
            'moderation_history' => $moderationHistory,
            'audit_logs' => $auditLogs,
        ];
    }

    /**
     * Update user account status with safety protections.
     *
     * @param User $actor
     * @param string $id
     * @param string $status ('active', 'suspended', 'banned')
     * @param string|null $reason
     * @return User
     * @throws ValidationException|NotFoundHttpException
     */
    public function updateStatus(User $actor, string $id, string $status, ?string $reason = null): User
    {
        $user = User::find($id);
        if (!$user) {
            throw new NotFoundHttpException("User with ID {$id} not found.");
        }

        if (!in_array($status, ['active', 'suspended', 'banned'], true)) {
            throw ValidationException::withMessages(['status' => 'Invalid status value.']);
        }

        // 1. Prevent self-lockout
        if ($actor->id === $user->id && in_array($status, ['suspended', 'banned'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Administrators cannot suspend or ban their own account.',
            ]);
        }

        // 2. Prevent suspending the last remaining active administrator
        $isUserAdmin = ($user->role instanceof UserRole && $user->role->isAdmin()) || $user->role === 'admin';
        if ($isUserAdmin && in_array($status, ['suspended', 'banned'], true)) {
            $otherActiveAdmins = User::where('role', 'admin')
                ->where('status', 'active')
                ->where('id', '!=', $user->id)
                ->count();

            if ($otherActiveAdmins === 0) {
                throw ValidationException::withMessages([
                    'status' => 'Cannot suspend or ban the only remaining active administrator on the platform.',
                ]);
            }
        }

        $oldStatus = $user->status instanceof UserStatus ? $user->status->value : $user->status;
        $user->status = $status;
        $user->save();

        // Synchronize user's public profile link status and purge cache immediately
        if ($user->profile) {
            $newProfileModerationStatus = in_array($status, ['suspended', 'banned'], true)
                ? 'suspended'
                : 'active';

            $user->profile->update([
                'moderation_status' => $newProfileModerationStatus,
                'moderation_reason' => $reason ?? ($status === 'suspended' ? 'User account suspended by administrator' : null),
                'moderated_at' => now(),
                'moderated_by' => $actor->id,
            ]);

            // Invalidate edge and application profile cache so web immediately reflects the suspension
            try {
                app(PublicProfileCacheService::class)->invalidateProfile($user->profile->username);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("[AdminUserService] Failed invalidating cache on user status update: {$e->getMessage()}");
            }
        }

        // If suspended or banned, invalidate all active sessions
        if (in_array($status, ['suspended', 'banned'], true)) {
            $this->sessionService->revokeAllSessions($user, 'account_suspended');
        }

        // Record audit and moderation events
        $actionType = $status === 'active'
            ? ModerationActionType::UserReactivated
            : ModerationActionType::UserSuspended;

        $this->moderationService->recordAction(
            $actor,
            'user',
            $user->id,
            $actionType,
            $reason,
            null,
            ['old_status' => $oldStatus, 'new_status' => $status]
        );

        $this->auditService->log($actor, "user.status_updated", 'user', $user->id, [
            'old_status' => $oldStatus,
            'new_status' => $status,
            'reason' => $reason,
        ]);

        return $user;
    }

    /**
     * Update user role with safety protections.
     *
     * @param User $actor
     * @param string $id
     * @param string $role ('user', 'moderator', 'admin')
     * @param string|null $reason
     * @return User
     * @throws ValidationException|NotFoundHttpException
     */
    public function updateRole(User $actor, string $id, string $role, ?string $reason = null): User
    {
        $user = User::find($id);
        if (!$user) {
            throw new NotFoundHttpException("User with ID {$id} not found.");
        }

        if (!in_array($role, ['user', 'moderator', 'admin'], true)) {
            throw ValidationException::withMessages(['role' => 'Invalid role value.']);
        }

        // 1. Prevent self-demotion
        if ($actor->id === $user->id && $role !== 'admin') {
            throw ValidationException::withMessages([
                'role' => 'Administrators cannot demote their own account.',
            ]);
        }

        // 2. Prevent demoting the last remaining active administrator
        $isUserAdmin = ($user->role instanceof UserRole && $user->role->isAdmin()) || $user->role === 'admin';
        if ($isUserAdmin && $role !== 'admin') {
            $otherActiveAdmins = User::where('role', 'admin')
                ->where('status', 'active')
                ->where('id', '!=', $user->id)
                ->count();

            if ($otherActiveAdmins === 0) {
                throw ValidationException::withMessages([
                    'role' => 'Cannot demote the only remaining active administrator on the platform.',
                ]);
            }
        }

        $oldRole = $user->role instanceof UserRole ? $user->role->value : $user->role;
        $user->role = $role;
        $user->save();

        // Record audit and moderation events
        $this->moderationService->recordAction(
            $actor,
            'user',
            $user->id,
            ModerationActionType::UserRoleChanged,
            $reason,
            null,
            ['old_role' => $oldRole, 'new_role' => $role]
        );

        $this->auditService->log($actor, "user.role_updated", 'user', $user->id, [
            'old_role' => $oldRole,
            'new_role' => $role,
            'reason' => $reason,
        ]);

        return $user;
    }

    /**
     * Create a new user account with assigned validity plan and one-time password.
     * Returns user + plain-text password (shown once, never stored).
     *
     * @param User $actor
     * @param array<string, mixed> $data
     * @return array{user: User, plain_password: string}
     */
    public function createUser(User $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data) {
            $role = $data['role'] ?? 'user';
            $status = $data['status'] ?? 'active';
            $plainPassword = $data['password'] ?? Str::password(12, true, true, false);

            // Resolve validity plan
            $validityPlan = null;
            $expiresAt = null;
            $validityMonths = null;
            $validityLabel = null;
            $pricePaid = null;

            if (!empty($data['validity_plan_id'])) {
                $validityPlan = ValidityPlan::find($data['validity_plan_id']);
                if ($validityPlan) {
                    $expiresAt = now()->addMonths($validityPlan->months);
                    $validityMonths = $validityPlan->months;
                    $validityLabel = $validityPlan->name;
                    $pricePaid = (float) $validityPlan->price;
                }
            }

            $user = User::create([
                'name' => trim($data['name']),
                'email' => strtolower(trim($data['email'])),
                'password' => Hash::make($plainPassword),
                'role' => $role,
                'status' => $status,
                'email_verified_at' => now(),
                'expires_at' => $expiresAt,
                'validity_months' => $validityMonths,
                'validity_label' => $validityLabel,
                'plan_price_paid' => $pricePaid,
            ]);

            // Create initial profile
            $username = strtolower(trim($data['username']));
            \App\Models\Profile::create([
                'user_id' => $user->id,
                'username' => $username,
                'display_name' => $data['name'],
                'bio' => 'Welcome to my Digicardo page!',
                'template_id' => 'vcard_business',
                'is_public' => true,
                'moderation_status' => 'active',
                'version' => 1,
                'theme_tokens' => [
                    'color_background' => '#090d16',
                    'color_surface' => '#111827',
                    'color_text_primary' => '#f9fafb',
                    'color_text_secondary' => '#9ca3af',
                    'color_accent' => '#6366f1',
                    'font_family' => 'inter',
                    'button_radius' => 'medium',
                    'button_style' => 'solid',
                    'animation' => 'fade',
                ],
            ]);

            // Record sale for revenue tracking
            if ($validityPlan && $pricePaid > 0) {
                AdminSale::create([
                    'user_id' => $user->id,
                    'validity_plan_id' => $validityPlan->id,
                    'plan_label' => $validityLabel,
                    'amount' => $pricePaid,
                    'currency_symbol' => $validityPlan->currency_symbol,
                    'sale_date' => now()->toDateString(),
                ]);
            }

            // Audit log
            $this->auditService->log($actor, 'user.created_by_admin', 'user', $user->id, [
                'email' => $user->email,
                'username' => $username,
                'role' => $role,
                'validity_label' => $validityLabel,
                'expires_at' => $expiresAt?->toDateString(),
            ]);

            return [
                'user' => $user->load('profile'),
                'plain_password' => $plainPassword,
            ];
        });
    }

    /**
     * Get users expiring within N days for dashboard alerts.
     *
     * @param int $days
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getExpiringUsers(int $days = 7)
    {
        return User::with('profile:id,user_id,username')
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($days))
            ->orderBy('expires_at')
            ->get(['id', 'name', 'email', 'expires_at', 'validity_label']);
    }

    /**
     * Get users whose validity has already expired.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getExpiredUsers()
    {
        return User::with('profile:id,user_id,username')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->where('status', '!=', 'suspended')
            ->orderBy('expires_at', 'desc')
            ->get(['id', 'name', 'email', 'expires_at', 'status', 'validity_label']);
    }

    /**
     * Extend a user's validity by renewing with a new plan.
     */
    public function renewUser(User $actor, string $userId, string $planId): array
    {
        $user = User::findOrFail($userId);
        $plan = ValidityPlan::findOrFail($planId);

        $base = $user->expires_at && $user->expires_at->isFuture() ? $user->expires_at : now();
        $expiresAt = Carbon::parse($base)->addMonths($plan->months);

        $user->update([
            'expires_at' => $expiresAt,
            'validity_months' => $plan->months,
            'validity_label' => $plan->name,
            'plan_price_paid' => (float) $plan->price,
            'status' => 'active', // re-activate if suspended due to expiry
        ]);

        $this->auditService->log($actor, 'user.validity_renewed', 'user', $user->id, [
            'plan' => $plan->name,
            'expires_at' => $expiresAt->toDateString(),
        ]);

        return ['user' => $user->fresh(), 'expires_at' => $expiresAt->toIso8601String()];
    }

    /**
     * Permanently delete a user account, their public profile link, and all associated data.
     * Purges edge/application cache so the profile is immediately removed from the web.
     *
     * @param User $actor
     * @param string $userId
     * @return void
     * @throws ValidationException|NotFoundHttpException
     */
    public function deleteUser(User $actor, string $userId): void
    {
        $user = User::withTrashed()->with(['profile.blocks', 'profile.domains', 'profile.media'])->find($userId);

        if (!$user) {
            throw new NotFoundHttpException("User with ID {$userId} not found.");
        }

        // 1. Prevent self-deletion
        if ($actor->id === $user->id) {
            throw ValidationException::withMessages([
                'user_id' => 'Administrators cannot delete their own account.',
            ]);
        }

        // 2. Prevent deleting the last remaining active administrator
        $isUserAdmin = ($user->role instanceof UserRole && $user->role->isAdmin()) || $user->role === 'admin';
        if ($isUserAdmin) {
            $otherActiveAdmins = User::where('role', 'admin')
                ->where('status', 'active')
                ->where('id', '!=', $user->id)
                ->count();

            if ($otherActiveAdmins === 0) {
                throw ValidationException::withMessages([
                    'user_id' => 'Cannot delete the only remaining active administrator on the platform.',
                ]);
            }
        }

        $username = $user->profile?->username;
        $userEmail = $user->email;

        // 3. Invalidate public profile cache from web/CDN immediately
        if ($username) {
            try {
                app(PublicProfileCacheService::class)->invalidateProfile($username);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("[AdminUserService] Profile cache invalidation error: {$e->getMessage()}");
            }
        }

        // 4. Cascade permanent deletion inside database transaction
        DB::transaction(function () use ($user) {
            // Revoke and delete sessions
            try {
                $this->sessionService->revokeAllSessions($user, 'account_deleted');
            } catch (\Throwable $e) {
            }
            DB::table('sessions')->where('user_id', $user->id)->delete();

            // Invalidate API tokens
            $user->tokens()->delete();

            // Delete user-related security records, sessions, notifications
            DB::table('account_security_events')->where('user_id', $user->id)->delete();
            DB::table('account_sessions')->where('user_id', $user->id)->delete();
            DB::table('notifications')->where('user_id', $user->id)->delete();
            DB::table('notification_preferences')->where('user_id', $user->id)->delete();
            DB::table('subscriptions')->where('user_id', $user->id)->delete();
            DB::table('admin_sales')->where('user_id', $user->id)->delete();
            DB::table('moderation_actions')->where('target_type', 'user')->where('target_id', $user->id)->delete();

            // Clean up profile data completely
            if ($user->profile) {
                $profileId = $user->profile->id;
                DB::table('profile_blocks')->where('profile_id', $profileId)->delete();
                DB::table('profile_media')->where('profile_id', $profileId)->orWhere('user_id', $user->id)->delete();
                DB::table('profile_domains')->where('profile_id', $profileId)->orWhere('user_id', $user->id)->delete();
                $user->profile->forceDelete();
            }

            // Clean up access requests if any match email
            try {
                DB::table('access_requests')->where('email', $user->email)->delete();
            } catch (\Throwable $e) {
            }

            // Permanently force delete the user
            $user->forceDelete();
        });

        // 5. Record admin audit log
        $this->auditService->log($actor, 'user.permanently_deleted', 'user', $userId, [
            'deleted_user_id' => $userId,
            'email' => $userEmail,
            'username' => $username,
        ]);
    }

    /**
     * Impersonate a user without requiring their password/credentials.
     * Accessible strictly to platform administrators.
     *
     * @param User $admin Current authenticated administrator.
     * @param string $userId Target user ID to impersonate.
     * @return array{user: User, redirect_url: string}
     */
    public function impersonateUser(User $admin, string $userId): array
    {
        // 1. Verify caller has admin privileges
        $isAdmin = ($admin->role instanceof UserRole && $admin->role->isAdmin()) || $admin->role === 'admin';
        if (!$isAdmin) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException(
                'Unauthorized. Administrator privileges are required to impersonate accounts.'
            );
        }

        // 2. Find target user
        $targetUser = User::with('profile')->findOrFail($userId);

        // 3. Security check: cannot impersonate self
        if ($admin->id === $targetUser->id) {
            throw ValidationException::withMessages([
                'user_id' => ['You cannot impersonate your own administrator account.'],
            ]);
        }

        $request = request();

        // 4. Log in target user into web session
        \Illuminate\Support\Facades\Auth::guard('web')->login($targetUser);

        // 5. Regenerate session to prevent fixation and attach impersonation markers
        if ($request->hasSession()) {
            $request->session()->regenerate();
            $request->session()->put('impersonator_admin_id', $admin->id);
            $request->session()->put('impersonator_admin_name', $admin->name);
            $request->session()->put('impersonator_admin_email', $admin->email);
            $request->session()->put('impersonated_at', now()->toIso8601String());
            $request->session()->save();

            try {
                $this->sessionService->registerSession($targetUser, $request);
            } catch (\Throwable $e) {
                // Session registration non-blocking
            }
        }

        // 6. Record audit log
        $this->auditService->log(
            $admin,
            'user.impersonated',
            'user',
            $targetUser->id,
            [
                'target_name' => $targetUser->name,
                'target_email' => $targetUser->email,
                'target_username' => $targetUser->profile?->username,
                'impersonated_by_admin_id' => $admin->id,
                'impersonated_by_admin_email' => $admin->email,
            ]
        );

        // 7. Record security audit event
        try {
            AccountSecurityEvent::create([
                'user_id' => $targetUser->id,
                'event_type' => AccountSecurityEventType::LoginSuccess,
                'metadata' => [
                    'action' => 'admin_impersonate',
                    'admin_id' => $admin->id,
                    'admin_email' => $admin->email,
                ],
                'ip_hash' => hash_hmac('sha256', (string) $request->ip(), config('app.key', 'Digicardo-salt')),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }

        return [
            'user' => $targetUser,
            'redirect_url' => '/dashboard',
        ];
    }

    /**
     * Terminate active impersonation and restore the administrator session.
     *
     * @return array{admin: User, redirect_url: string}
     */
    public function stopImpersonation(): array
    {
        $request = request();

        if ($request->hasSession() && $request->session()->has('impersonator_admin_id')) {
            $adminId = $request->session()->get('impersonator_admin_id');
            $admin = User::find($adminId);
        } else {
            // Check if current session user is already an admin
            $currentUser = \Illuminate\Support\Facades\Auth::guard('web')->user();
            $isCurrentAdmin = $currentUser && (
                ($currentUser->role instanceof UserRole && $currentUser->role->isAdmin())
                || $currentUser->role === 'admin'
                || (string) $currentUser->role === 'admin'
            );

            if ($isCurrentAdmin) {
                return [
                    'admin' => $currentUser,
                    'redirect_url' => '/admin/my-links',
                ];
            }

            // Fallback to active administrator account
            $admin = User::where('role', 'admin')->where('status', 'active')->first();
        }

        if (!$admin) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(
                'No active administrator account available to restore.'
            );
        }

        $impersonatedUserId = \Illuminate\Support\Facades\Auth::guard('web')->id();

        // Clear impersonation markers
        if ($request->hasSession()) {
            $request->session()->forget([
                'impersonator_admin_id',
                'impersonator_admin_name',
                'impersonator_admin_email',
                'impersonated_at',
            ]);

            // Restore admin session
            \Illuminate\Support\Facades\Auth::guard('web')->login($admin);
            $request->session()->regenerate();
            $request->session()->save();

            try {
                $this->sessionService->registerSession($admin, $request);
            } catch (\Throwable $e) {
            }
        } else {
            \Illuminate\Support\Facades\Auth::guard('web')->login($admin);
        }

        // Record audit log
        try {
            $this->auditService->log(
                $admin,
                'user.impersonation_stopped',
                'user',
                $impersonatedUserId ?? 'unknown',
                [
                    'admin_id' => $admin->id,
                    'restored_email' => $admin->email,
                ]
            );
        } catch (\Throwable $e) {
        }

        return [
            'admin' => $admin,
            'redirect_url' => '/admin/my-links',
        ];
    }
}
