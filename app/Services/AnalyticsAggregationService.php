<?php

namespace App\Services;

use App\Enums\AnalyticsEventType;
use App\Models\AnalyticsDailyMetric;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsUniqueVisitor;
use App\Models\ContactSubmission;
use App\Models\Profile;
use App\Models\ProfileBlock;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnalyticsAggregationService
{
    public function __construct(
        protected FeatureEntitlementService $entitlementService
    ) {}

    /**
     * Clamp start date to the maximum allowed historical days permitted by user's plan.
     */
    public function clampStartDateToPlan(Carbon $start, ?Profile $profile): Carbon
    {
        if (!$profile) {
            return $start;
        }

        $user = $profile->user ?? User::find($profile->user_id);
        if ($user) {
            $allowedDays = $this->entitlementService->limit($user, \App\Enums\FeatureKey::AnalyticsHistoryDays) ?? 7;
            $earliestAllowed = Carbon::today()->subDays(max(1, $allowedDays) - 1)->startOfDay();
            if ($start->lt($earliestAllowed)) {
                return $earliestAllowed;
            }
        }

        return $start;
    }

    /**
     * Process and aggregate a raw analytics event into daily metrics.
     */
    public function aggregateEvent(AnalyticsEvent $event, string $visitorHash): void
    {
        $date = $event->occurred_at ? $event->occurred_at->toDateString() : date('Y-m-d');
        $profileId = $event->profile_id;
        $blockId = $event->block_id ?? '';
        $eventType = $event->event_type instanceof AnalyticsEventType ? $event->event_type->value : (string) $event->event_type;

        DB::transaction(function () use ($profileId, $blockId, $date, $eventType, $visitorHash) {
            // 1. Check unique visitor status for profile_view
            $isNewUnique = false;
            if ($eventType === AnalyticsEventType::ProfileView->value) {
                $exists = AnalyticsUniqueVisitor::where('profile_id', $profileId)
                    ->where('visitor_hash', $visitorHash)
                    ->whereDate('date', $date)
                    ->exists();

                if (!$exists) {
                    try {
                        AnalyticsUniqueVisitor::create([
                            'id' => (string) Str::ulid(),
                            'profile_id' => $profileId,
                            'visitor_hash' => $visitorHash,
                            'date' => $date,
                        ]);
                        $isNewUnique = true;
                    } catch (\Throwable) {
                        // Concurrent race condition duplicate safely caught by DB unique constraint
                        $isNewUnique = false;
                    }
                }
            }

            // 2. Increment or create daily metric rollup row
            $metric = AnalyticsDailyMetric::where('profile_id', $profileId)
                ->where('block_id', $blockId)
                ->whereDate('date', $date)
                ->where('event_type', $eventType)
                ->first();

            if ($metric) {
                $metric->increment('total_count');
                if ($isNewUnique) {
                    $metric->increment('unique_count');
                }
            } else {
                AnalyticsDailyMetric::create([
                    'id' => (string) Str::ulid(),
                    'profile_id' => $profileId,
                    'block_id' => $blockId,
                    'date' => $date,
                    'event_type' => $eventType,
                    'total_count' => 1,
                    'unique_count' => $isNewUnique ? 1 : 0,
                ]);
            }
        });
    }

    /**
     * Resolve date boundary range for analytics queries.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function resolveDateRange(string $period, ?string $startDate = null, ?string $endDate = null, ?Profile $profile = null): array
    {
        $today = Carbon::today();

        [$start, $end] = match ($period) {
            'today' => [$today->copy(), $today->copy()->endOfDay()],
            '7d' => [$today->copy()->subDays(6)->startOfDay(), $today->copy()->endOfDay()],
            '30d' => [$today->copy()->subDays(29)->startOfDay(), $today->copy()->endOfDay()],
            '90d' => [$today->copy()->subDays(89)->startOfDay(), $today->copy()->endOfDay()],
            'custom' => [
                $startDate ? Carbon::parse($startDate)->startOfDay() : $today->copy()->subDays(29)->startOfDay(),
                $endDate ? Carbon::parse($endDate)->endOfDay() : $today->copy()->endOfDay(),
            ],
            default => [$today->copy()->subDays(6)->startOfDay(), $today->copy()->endOfDay()],
        };

        $start = $this->clampStartDateToPlan($start, $profile);

        return [$start, $end];
    }

    /**
     * Get high-level overview metrics for a profile.
     *
     * @return array<string, mixed>
     */
    public function getOverview(Profile $profile, string $period, ?string $startDate = null, ?string $endDate = null): array
    {
        [$start, $end] = $this->resolveDateRange($period, $startDate, $endDate, $profile);
        $startDateStr = $start->toDateString();
        $endDateStr = $end->toDateString();

        $metrics = AnalyticsDailyMetric::where('profile_id', $profile->id)
            ->whereDate('date', '>=', $startDateStr)
            ->whereDate('date', '<=', $endDateStr)
            ->get();

        $totalViews = 0;
        $uniqueViews = 0;
        $totalClicks = 0;

        $clickEvents = [
            AnalyticsEventType::LinkClick->value,
            AnalyticsEventType::SocialClick->value,
            AnalyticsEventType::CtaClick->value,
            AnalyticsEventType::EmailClick->value,
            AnalyticsEventType::PhoneClick->value,
            AnalyticsEventType::WhatsAppClick->value,
            AnalyticsEventType::BookingClick->value,
            AnalyticsEventType::ImageClick->value,
        ];

        foreach ($metrics as $metric) {
            $type = $metric->event_type instanceof AnalyticsEventType ? $metric->event_type->value : (string) $metric->event_type;

            if ($type === AnalyticsEventType::ProfileView->value) {
                $totalViews += $metric->total_count;
                $uniqueViews += $metric->unique_count;
            } elseif (in_array($type, $clickEvents, true)) {
                $totalClicks += $metric->total_count;
            }
        }

        // Contact submissions count
        $contactCount = ContactSubmission::where('profile_id', $profile->id)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        // Safe CTR calculation
        $ctr = $totalViews > 0 ? round(($totalClicks / $totalViews) * 100, 2) : 0.0;

        // Top clicked block
        $topBlockMetric = AnalyticsDailyMetric::where('profile_id', $profile->id)
            ->where('block_id', '!=', '')
            ->whereIn('event_type', $clickEvents)
            ->whereDate('date', '>=', $startDateStr)
            ->whereDate('date', '<=', $endDateStr)
            ->select('block_id', DB::raw('SUM(total_count) as total_clicks'))
            ->groupBy('block_id')
            ->orderByDesc('total_clicks')
            ->first();

        $topBlock = null;
        if ($topBlockMetric && $topBlockMetric->block_id) {
            $block = ProfileBlock::find($topBlockMetric->block_id);
            if ($block) {
                $topBlock = [
                    'id' => $block->id,
                    'type' => $block->type,
                    'title' => $block->config['title'] ?? $block->config['text'] ?? $block->config['label'] ?? $block->type,
                    'clicks' => (int) $topBlockMetric->total_clicks,
                ];
            }
        }

        return [
            'total_views' => $totalViews,
            'unique_views' => $uniqueViews,
            'total_clicks' => $totalClicks,
            'total_contact_submissions' => $contactCount,
            'click_through_rate' => $ctr,
            'top_block' => $topBlock,
            'period' => $period,
            'start_date' => $startDateStr,
            'end_date' => $endDateStr,
        ];
    }

    /**
     * Get day-by-day time series data, zero-filling missing dates.
     *
     * @return array<int, array{date: string, views: int, unique_views: int, clicks: int}>
     */
    public function getTimeseries(Profile $profile, string $period, ?string $startDate = null, ?string $endDate = null): array
    {
        [$start, $end] = $this->resolveDateRange($period, $startDate, $endDate, $profile);
        $startDateStr = $start->toDateString();
        $endDateStr = $end->toDateString();

        $metrics = AnalyticsDailyMetric::where('profile_id', $profile->id)
            ->whereDate('date', '>=', $startDateStr)
            ->whereDate('date', '<=', $endDateStr)
            ->get();

        $clickEvents = [
            AnalyticsEventType::LinkClick->value,
            AnalyticsEventType::SocialClick->value,
            AnalyticsEventType::CtaClick->value,
            AnalyticsEventType::EmailClick->value,
            AnalyticsEventType::PhoneClick->value,
            AnalyticsEventType::WhatsAppClick->value,
            AnalyticsEventType::BookingClick->value,
            AnalyticsEventType::ImageClick->value,
        ];

        $dataByDate = [];
        foreach ($metrics as $metric) {
            $dateStr = $metric->date instanceof Carbon ? $metric->date->toDateString() : substr((string) $metric->date, 0, 10);
            $type = $metric->event_type instanceof AnalyticsEventType ? $metric->event_type->value : (string) $metric->event_type;

            if (!isset($dataByDate[$dateStr])) {
                $dataByDate[$dateStr] = ['views' => 0, 'unique_views' => 0, 'clicks' => 0];
            }

            if ($type === AnalyticsEventType::ProfileView->value) {
                $dataByDate[$dateStr]['views'] += $metric->total_count;
                $dataByDate[$dateStr]['unique_views'] += $metric->unique_count;
            } elseif (in_array($type, $clickEvents, true)) {
                $dataByDate[$dateStr]['clicks'] += $metric->total_count;
            }
        }

        // Generate complete continuous date series
        $periodRange = \Carbon\CarbonPeriod::create($start, $end);
        $timeSeries = [];

        foreach ($periodRange as $date) {
            $dateStr = $date->toDateString();
            $timeSeries[] = [
                'date' => $dateStr,
                'views' => $dataByDate[$dateStr]['views'] ?? 0,
                'unique_views' => $dataByDate[$dateStr]['unique_views'] ?? 0,
                'clicks' => $dataByDate[$dateStr]['clicks'] ?? 0,
            ];
        }

        return $timeSeries;
    }

    /**
     * Get block-level interaction metrics for the profile.
     *
     * @return array<int, array{block_id: string, block_type: string, title: string, count: int}>
     */
    public function getBlockMetrics(Profile $profile, string $period, ?string $startDate = null, ?string $endDate = null): array
    {
        [$start, $end] = $this->resolveDateRange($period, $startDate, $endDate, $profile);

        $results = AnalyticsDailyMetric::where('profile_id', $profile->id)
            ->where('block_id', '!=', '')
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->select('block_id', DB::raw('SUM(total_count) as total_interactions'))
            ->groupBy('block_id')
            ->orderByDesc('total_interactions')
            ->get();

        $blocks = ProfileBlock::where('profile_id', $profile->id)
            ->get()
            ->keyBy('id');

        $list = [];
        foreach ($results as $row) {
            $block = $blocks[$row->block_id] ?? null;
            if ($block) {
                $list[] = [
                    'block_id' => $block->id,
                    'block_type' => $block->type,
                    'title' => $block->config['title'] ?? $block->config['text'] ?? $block->config['label'] ?? $block->type,
                    'count' => (int) $row->total_interactions,
                ];
            }
        }

        return $list;
    }

    /**
     * Get privacy-normalized referrer distribution.
     *
     * @return array<int, array{referrer: string, count: int, percentage: float}>
     */
    public function getReferrers(Profile $profile, string $period, ?string $startDate = null, ?string $endDate = null): array
    {
        [$start, $end] = $this->resolveDateRange($period, $startDate, $endDate, $profile);

        $results = AnalyticsEvent::where('profile_id', $profile->id)
            ->where('event_type', AnalyticsEventType::ProfileView->value)
            ->whereBetween('occurred_at', [$start, $end])
            ->select('referrer_host', DB::raw('COUNT(*) as total'))
            ->groupBy('referrer_host')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $totalViews = $results->sum('total');

        return $results->map(function ($row) use ($totalViews) {
            $count = (int) $row->total;
            return [
                'referrer' => $row->referrer_host ?: 'direct',
                'count' => $count,
                'percentage' => $totalViews > 0 ? round(($count / $totalViews) * 100, 1) : 0.0,
            ];
        })->toArray();
    }
}
