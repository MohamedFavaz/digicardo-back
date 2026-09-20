<?php

namespace App\Services;

use App\Jobs\ProcessAnalyticsEvent;
use App\Models\Profile;
use App\Models\ProfileBlock;
use Carbon\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class AnalyticsService
{
    public function __construct(
        private readonly AnalyticsPrivacyService $privacyService
    ) {}

    /**
     * Ingest a public analytics event, validate relationships, and dispatch for asynchronous processing.
     *
     * @param array{profile_id: string, event_type: string, block_id?: ?string, referrer?: ?string, metadata?: ?array<string, mixed>, occurred_at?: ?string} $data
     * @param string $ip
     * @param ?string $userAgent
     * @return bool
     */
    public function ingest(array $data, string $ip, ?string $userAgent): bool
    {
        // 1. Verify profile existence
        $profile = Profile::where('id', $data['profile_id'])->first();
        if (!$profile) {
            throw new NotFoundHttpException('Profile not found.');
        }

        // 2. If block_id is provided, verify it belongs strictly to this profile
        if (!empty($data['block_id'])) {
            $block = ProfileBlock::where('id', $data['block_id'])
                ->where('profile_id', $profile->id)
                ->first();

            if (!$block) {
                throw new UnprocessableEntityHttpException('The specified block does not belong to this profile.');
            }
        }

        // 3. Verify timestamp skew (within 5 minutes of server time)
        if (!empty($data['occurred_at'])) {
            $occurredAt = Carbon::parse($data['occurred_at']);
            if ($occurredAt->diffInMinutes(Carbon::now()) > 5) {
                // Skew too high, snap to now
                $data['occurred_at'] = Carbon::now()->toIso8601String();
            }
        }

        // 4. Generate privacy-preserving visitor hash (no raw IP stored)
        $visitorHash = $this->privacyService->generateVisitorHash($ip, $userAgent);
        $referrerHost = $this->privacyService->normalizeReferrer($data['referrer'] ?? null);

        $eventData = [
            'profile_id' => $profile->id,
            'block_id' => $data['block_id'] ?? null,
            'event_type' => $data['event_type'],
            'referrer_host' => $referrerHost,
            'metadata' => $data['metadata'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? Carbon::now()->toIso8601String(),
        ];

        // 5. Dispatch to background queue worker
        ProcessAnalyticsEvent::dispatch($eventData, $visitorHash);

        return true;
    }
}
