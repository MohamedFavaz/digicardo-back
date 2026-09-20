<?php

namespace App\Services;

use App\Enums\AbuseReportReason;
use App\Enums\AbuseReportStatus;
use App\Enums\ModerationActionType;
use App\Models\AbuseReport;
use App\Models\Profile;
use App\Models\ProfileBlock;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AbuseReportService
{
    public function __construct(
        protected AdminAuditService $auditService,
        protected ModerationActionService $moderationService
    ) {}

    /**
     * Submit an abuse report against a public profile.
     *
     * @param string $username
     * @param array<string, mixed> $data
     * @param string|null $ip
     * @return AbuseReport
     * @throws ValidationException|NotFoundHttpException
     */
    public function submitReport(string $username, array $data, ?string $ip = null): AbuseReport
    {
        // 1. Honeypot check
        if (! empty($data['hp_field']) || ! empty($data['website'])) {
            throw ValidationException::withMessages([
                'form' => 'Spam bot submission detected.',
            ]);
        }

        // 2. Resolve profile
        $normalized = UsernameService::normalize($username);
        $profile = Profile::where('username', $normalized)->first();

        if (! $profile) {
            throw new NotFoundHttpException("Profile @{$username} not found.");
        }

        // 3. Validate block relationship if block_id is provided
        $blockId = $data['block_id'] ?? null;
        if ($blockId) {
            $blockBelongs = ProfileBlock::where('id', $blockId)
                ->where('profile_id', $profile->id)
                ->exists();

            if (! $blockBelongs) {
                throw ValidationException::withMessages([
                    'block_id' => 'The specified content block does not belong to this profile.',
                ]);
            }
        }

        // 4. Validate reason
        $reason = $data['reason'] ?? null;
        $validReasons = array_column(AbuseReportReason::cases(), 'value');
        if (! in_array($reason, $validReasons, true)) {
            throw ValidationException::withMessages([
                'reason' => 'Invalid abuse report reason selected.',
            ]);
        }

        $description = trim((string) ($data['description'] ?? ''));
        if (strlen($description) < 10) {
            throw ValidationException::withMessages([
                'description' => 'Please provide a detailed description of at least 10 characters.',
            ]);
        }

        $reporterEmail = ! empty($data['reporter_email']) ? strtolower(trim((string) $data['reporter_email'])) : null;
        $ipHash = $ip ? hash_hmac('sha256', $ip, config('app.key')) : null;

        // 5. Anti-spam duplicate prevention: check if open report exists from same email or IP within 24h
        $duplicateQuery = AbuseReport::where('profile_id', $profile->id)
            ->whereIn('status', ['open', 'investigating'])
            ->where('created_at', '>=', now()->subHours(24));

        if ($reporterEmail) {
            $duplicateQuery->where('reporter_email', $reporterEmail);
        } elseif ($ipHash) {
            $duplicateQuery->where('reporter_ip_hash', $ipHash);
        }

        $existing = $duplicateQuery->first();
        if ($existing) {
            return $existing;
        }

        // 6. Create report
        return AbuseReport::create([
            'profile_id' => $profile->id,
            'block_id' => $blockId,
            'reporter_email' => $reporterEmail,
            'reporter_ip_hash' => $ipHash,
            'reason' => $reason,
            'description' => $description,
            'status' => AbuseReportStatus::Open,
            'metadata' => [
                'reported_username' => $profile->username,
                'reporter_user_agent' => request()?->userAgent(),
            ],
        ]);
    }

    /**
     * List abuse reports with filters and pagination.
     *
     * @param array<string, mixed> $filters
     * @param int $page
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function listReports(array $filters = [], int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        $query = AbuseReport::with([
            'profile:id,username,display_name,avatar_url,moderation_status,user_id',
            'profile.user:id,name,email',
            'block:id,type,content,sort_order',
            'resolver:id,name,email',
        ])->orderBy('created_at', 'desc');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['reason'])) {
            $query->where('reason', $filters['reason']);
        }

        if (! empty($filters['profile_id'])) {
            $query->where('profile_id', $filters['profile_id']);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Get report details.
     *
     * @param string $id
     * @return AbuseReport
     * @throws NotFoundHttpException
     */
    public function getReportDetails(string $id): AbuseReport
    {
        $report = AbuseReport::with([
            'profile.user',
            'profile.blocks',
            'block',
            'resolver',
        ])->find($id);

        if (! $report) {
            throw new NotFoundHttpException("Abuse report with ID {$id} not found.");
        }

        return $report;
    }

    /**
     * Update report resolution status.
     *
     * @param User $actor
     * @param string $id
     * @param string $status ('open', 'investigating', 'resolved', 'dismissed')
     * @param string|null $resolutionNotes
     * @return AbuseReport
     * @throws ValidationException|NotFoundHttpException
     */
    public function updateReportStatus(
        User $actor,
        string $id,
        string $status,
        ?string $resolutionNotes = null
    ): AbuseReport {
        $report = AbuseReport::find($id);
        if (! $report) {
            throw new NotFoundHttpException("Abuse report with ID {$id} not found.");
        }

        if (! in_array($status, ['open', 'investigating', 'resolved', 'dismissed'], true)) {
            throw ValidationException::withMessages(['status' => 'Invalid report status value.']);
        }

        $oldStatus = $report->status instanceof AbuseReportStatus
            ? $report->status->value
            : $report->status;

        $report->status = $status;
        $report->resolution_notes = $resolutionNotes;

        if (in_array($status, ['resolved', 'dismissed'], true)) {
            $report->resolved_at = now();
            $report->resolved_by = $actor->id;
        } else {
            $report->resolved_at = null;
            $report->resolved_by = null;
        }

        $report->save();

        $actionType = match ($status) {
            'investigating' => ModerationActionType::ReportInvestigating,
            'resolved' => ModerationActionType::ReportResolved,
            'dismissed' => ModerationActionType::ReportDismissed,
            default => ModerationActionType::ReportInvestigating,
        };

        $this->moderationService->recordAction(
            $actor,
            'report',
            $report->id,
            $actionType,
            $resolutionNotes,
            null,
            ['old_status' => $oldStatus, 'new_status' => $status, 'profile_id' => $report->profile_id]
        );

        $this->auditService->log($actor, "report.status_updated", 'report', $report->id, [
            'old_status' => $oldStatus,
            'new_status' => $status,
            'profile_id' => $report->profile_id,
        ]);

        return $report;
    }
}
