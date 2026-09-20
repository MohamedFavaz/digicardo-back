<?php

namespace App\Services;

use App\Enums\ModerationActionType;
use App\Enums\ProfileModerationStatus;
use App\Models\AbuseReport;
use App\Models\ModerationAction;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProfileModerationService
{
    public function __construct(
        protected AdminAuditService $auditService,
        protected ModerationActionService $moderationService,
        protected PublicProfileCacheService $cacheService,
        protected NotificationService $notificationService
    ) {}

    /**
     * List profiles with search, moderation filtering, and safe sorting.
     *
     * @param array<string, mixed> $filters
     * @param int $page
     * @param int $perPage
     * @param string $sortBy
     * @param string $sortDir
     * @return LengthAwarePaginator
     */
    public function listProfiles(
        array $filters = [],
        int $page = 1,
        int $perPage = 15,
        string $sortBy = 'created_at',
        string $sortDir = 'desc'
    ): LengthAwarePaginator {
        $allowedSorts = ['created_at', 'username', 'display_name', 'moderation_status'];
        $sortColumn = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'created_at';
        $direction = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

        $query = Profile::with(['user:id,name,email,role,status'])
            ->withCount(['blocks', 'domains', 'abuseReports as open_reports_count' => fn ($q) => $q->whereIn('status', ['open', 'investigating'])]);

        // Search by username, display_name, owner email, or ULID
        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                    ->orWhere('display_name', 'like', "%{$search}%")
                    ->orWhere('id', $search)
                    ->orWhereHas('user', fn ($uq) => $uq->where('email', 'like', "%{$search}%"));
            });
        }

        // Filter by moderation status
        if (! empty($filters['moderation_status'])) {
            $query->where('moderation_status', $filters['moderation_status']);
        }

        // Filter by public visibility
        if (isset($filters['is_public'])) {
            if ($filters['is_public'] === 'true' || $filters['is_public'] === true || $filters['is_public'] === 1) {
                $query->where('is_public', true);
            } elseif ($filters['is_public'] === 'false' || $filters['is_public'] === false || $filters['is_public'] === 0) {
                $query->where('is_public', false);
            }
        }

        return $query->orderBy($sortColumn, $direction)->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Get detailed profile record for moderation review.
     *
     * @param string $id
     * @return array<string, mixed>
     * @throws NotFoundHttpException
     */
    public function getProfileDetails(string $id): array
    {
        $profile = Profile::with([
            'user:id,name,email,role,status,created_at',
            'blocks',
            'domains',
        ])->find($id);

        if (! $profile) {
            throw new NotFoundHttpException("Profile with ID {$id} not found.");
        }

        $reports = AbuseReport::where('profile_id', $profile->id)
            ->with('resolver:id,name')
            ->orderBy('created_at', 'desc')
            ->get();

        $moderationHistory = ModerationAction::where('target_type', 'profile')
            ->where('target_id', $profile->id)
            ->with('actor:id,name,email,role')
            ->orderBy('created_at', 'desc')
            ->get();

        return [
            'profile' => [
                'id' => $profile->id,
                'user_id' => $profile->user_id,
                'username' => $profile->username,
                'display_name' => $profile->display_name,
                'bio' => $profile->bio,
                'avatar_url' => $profile->avatar_url,
                'template_id' => $profile->template_id,
                'is_public' => $profile->is_public,
                'moderation_status' => $profile->moderation_status instanceof ProfileModerationStatus ? $profile->moderation_status->value : ($profile->moderation_status ?? 'active'),
                'moderation_reason' => $profile->moderation_reason,
                'moderation_notes' => $profile->moderation_notes,
                'moderated_at' => $profile->moderated_at?->toIso8601String(),
                'moderated_by' => $profile->moderated_by,
                'created_at' => $profile->created_at->toIso8601String(),
                'updated_at' => $profile->updated_at->toIso8601String(),
            ],
            'owner' => $profile->user,
            'blocks' => $profile->blocks,
            'domains' => $profile->domains,
            'reports' => $reports,
            'moderation_history' => $moderationHistory,
        ];
    }

    /**
     * Moderate a profile's governance status.
     *
     * @param User $actor
     * @param string $id
     * @param string $status ('active', 'under_review', 'restricted', 'suspended')
     * @param string|null $reason
     * @param string|null $notes
     * @return Profile
     * @throws ValidationException|NotFoundHttpException
     */
    public function moderateProfile(
        User $actor,
        string $id,
        string $status,
        ?string $reason = null,
        ?string $notes = null
    ): Profile {
        $profile = Profile::with('user')->find($id);
        if (! $profile) {
            throw new NotFoundHttpException("Profile with ID {$id} not found.");
        }

        if (! in_array($status, ['active', 'under_review', 'restricted', 'suspended'], true)) {
            throw ValidationException::withMessages(['moderation_status' => 'Invalid moderation status.']);
        }

        // Restrictive actions require an explanation
        if (in_array($status, ['restricted', 'suspended'], true) && empty($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required when restricting or suspending a profile.',
            ]);
        }

        $oldStatus = $profile->moderation_status instanceof ProfileModerationStatus
            ? $profile->moderation_status->value
            : ($profile->moderation_status ?? 'active');

        $profile->moderation_status = $status;
        $profile->moderation_reason = $reason;
        $profile->moderation_notes = $notes;
        $profile->moderated_at = now();
        $profile->moderated_by = $actor->id;
        $profile->save();

        // Invalidate public profile edge/application cache
        $this->cacheService->invalidateProfile($profile);

        // Map moderation action type
        $actionType = match ($status) {
            'under_review' => ModerationActionType::ProfileUnderReview,
            'restricted' => ModerationActionType::ProfileRestricted,
            'suspended' => ModerationActionType::ProfileSuspended,
            default => ModerationActionType::ProfileRestored,
        };

        $this->moderationService->recordAction(
            $actor,
            'profile',
            $profile->id,
            $actionType,
            $reason,
            $notes,
            ['old_status' => $oldStatus, 'new_status' => $status]
        );

        $this->auditService->log($actor, "profile.moderation_updated", 'profile', $profile->id, [
            'old_status' => $oldStatus,
            'new_status' => $status,
            'reason' => $reason,
        ]);

        return $profile;
    }
}
