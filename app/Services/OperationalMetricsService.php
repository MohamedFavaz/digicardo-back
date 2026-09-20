<?php

namespace App\Services;

use App\Models\Profile;
use App\Models\ProfileDomain;
use App\Models\ProfileMedia;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OperationalMetricsService
{
    public function __construct(
        protected QueueMonitoringService $queueService
    ) {
    }

    /**
     * Get aggregated system operational metrics (cached to prevent DB overload).
     *
     * @return array<string, mixed>
     */
    public function getMetrics(): array
    {
        $ttl = (int) config('observability.metrics.cache_ttl_seconds', 60);

        return Cache::remember('Digicardo_operational_metrics', $ttl, function () {
            return $this->computeMetrics();
        });
    }

    /**
     * Clear metrics cache and compute freshly.
     *
     * @return array<string, mixed>
     */
    public function refreshMetrics(): array
    {
        Cache::forget('Digicardo_operational_metrics');
        return $this->getMetrics();
    }

    /**
     * Execute fast aggregate queries to calculate metrics.
     *
     * @return array<string, mixed>
     */
    protected function computeMetrics(): array
    {
        // 1. Users metrics
        $totalUsers = User::count();
        $verifiedUsers = User::whereNotNull('email_verified_at')->count();
        $newUsersLast24h = User::where('created_at', '>=', now()->subDay())->count();
        $newUsersLast7d = User::where('created_at', '>=', now()->subDays(7))->count();

        // 2. Profiles metrics
        $totalProfiles = Profile::count();
        $publishedProfiles = Profile::where('is_public', true)->count();

        // 3. Custom Domains
        $totalDomains = ProfileDomain::count();
        $activeDomains = ProfileDomain::where('status', 'active')->count();
        $pendingDomains = ProfileDomain::where('status', 'pending')->count();

        // 4. Subscriptions
        $activeSubscriptions = Subscription::whereIn('status', ['active', 'trialing'])->count();
        $proSubscriptions = Subscription::whereIn('status', ['active', 'trialing'])
            ->whereHas('plan', fn($q) => $q->where('code', 'pro'))
            ->count();
        $businessSubscriptions = Subscription::whereIn('status', ['active', 'trialing'])
            ->whereHas('plan', fn($q) => $q->where('code', 'business'))
            ->count();
        $gracePeriodSubscriptions = Subscription::where('status', 'past_due')
            ->where('grace_period_ends_at', '>', now())
            ->count();

        // 5. Queues
        $queueHealth = $this->queueService->getQueueHealth();

        // 6. Media storage
        $totalMediaCount = ProfileMedia::count();

        // 7. Security & Activity
        $securityEventsLast24h = DB::table('account_security_events')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return [
            'users' => [
                'total' => $totalUsers,
                'verified' => $verifiedUsers,
                'unverified' => max(0, $totalUsers - $verifiedUsers),
                'new_last_24h' => $newUsersLast24h,
                'new_last_7d' => $newUsersLast7d,
            ],
            'profiles' => [
                'total' => $totalProfiles,
                'published' => $publishedProfiles,
            ],
            'domains' => [
                'total' => $totalDomains,
                'active' => $activeDomains,
                'pending' => $pendingDomains,
            ],
            'subscriptions' => [
                'active' => $activeSubscriptions,
                'pro' => $proSubscriptions,
                'business' => $businessSubscriptions,
                'in_grace_period' => $gracePeriodSubscriptions,
            ],
            'queues' => [
                'status' => $queueHealth['status'],
                'pending_jobs' => $queueHealth['pending_jobs'],
                'failed_jobs' => $queueHealth['failed_jobs'],
            ],
            'media' => [
                'total_items' => $totalMediaCount,
            ],
            'security' => [
                'events_last_24h' => $securityEventsLast24h,
            ],
            'computed_at' => now()->toIso8601String(),
        ];
    }
}
