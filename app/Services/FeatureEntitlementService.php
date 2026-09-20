<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Exceptions\FeatureLimitExceededException;
use App\Exceptions\FeatureNotAvailableException;
use App\Models\Plan;
use App\Models\User;

class FeatureEntitlementService
{
    /**
     * Default fallback feature configuration for Free plan if not yet seeded in DB.
     */
    protected const DEFAULT_FREE_FEATURES = [
        'profile_count' => 1,
        'custom_domain_count' => 0,
        'advanced_templates' => false,
        'analytics_history_days' => 7,
        'image_uploads' => true,
        'gallery_blocks' => false,
        'video_blocks' => false,
        'music_blocks' => false,
        'booking_blocks' => false,
        'contact_forms' => true,
        'advanced_analytics' => false,
        'remove_branding' => false,
    ];

    public function __construct(
        protected FeatureUsageService $usageService
    ) {}

    /**
     * Resolve the active Plan model for a user.
     * If user has an active paid subscription, returns that plan; otherwise resolves to the Free plan.
     */
    public function getActivePlan(User $user): Plan
    {
        // 1. Check if user has an active subscription (active, trialing, or grace period)
        $latestSubscription = $user->subscriptions()
            ->latest('created_at')
            ->first();

        if ($latestSubscription && $latestSubscription->isActive() && $latestSubscription->plan) {
            return $latestSubscription->plan;
        }

        // 2. Query the Free plan from DB
        $freePlan = Plan::where('code', 'free')->first();
        if ($freePlan) {
            return $freePlan;
        }

        // 3. Fallback in-memory Free plan if DB not seeded
        $fallback = new Plan();
        $fallback->code = 'free';
        $fallback->slug = 'free';
        $fallback->name = 'Free';
        $fallback->price_monthly_cents = 0;
        $fallback->price_yearly_cents = 0;
        $fallback->currency = 'USD';
        $fallback->features = self::DEFAULT_FREE_FEATURES;
        $fallback->is_active = true;
        return $fallback;
    }

    /**
     * PLATFORM POLICY — All features are unlocked for all users.
     * Subscription gating has been removed. Always returns true.
     */
    public function can(User $user, FeatureKey|string $feature): bool
    {
        return true;
    }

    /**
     * Get numeric limit for a feature.
     * PLATFORM POLICY — All limits are removed. Always returns null (unlimited).
     */
    public function limit(User $user, FeatureKey|string $feature): ?int
    {
        return null;
    }

    /**
     * Get current database usage for a feature.
     */
    public function usage(User $user, FeatureKey|string $feature): int
    {
        return $this->usageService->usage($user, $feature);
    }

    /**
     * Get remaining allowance for a numeric feature.
     * PLATFORM POLICY — Always returns null (unlimited).
     */
    public function remaining(User $user, FeatureKey|string $feature): ?int
    {
        return null;
    }

    /**
     * Assert that a user has access to a boolean feature.
     * PLATFORM POLICY — Never throws. All features are always accessible.
     *
     * @throws FeatureNotAvailableException
     */
    public function assertCan(User $user, FeatureKey|string $feature): void
    {
        // All features are unlocked — no exception is thrown.
    }

    /**
     * Assert that a user has not exceeded the numeric limit for a feature.
     * PLATFORM POLICY — Never throws. All limits are removed (unlimited).
     *
     * @throws FeatureLimitExceededException
     */
    public function assertWithinLimit(User $user, FeatureKey|string $feature, int $requestedUsage = 1): void
    {
        // All limits are removed — no exception is thrown.
    }

    /**
     * Build a complete entitlements payload for the API response.
     *
     * @return array<string, mixed>
     */
    public function buildEntitlementsPayload(User $user): array
    {
        $plan = $this->getActivePlan($user);

        // All features enabled, all limits unlimited
        $allFeatures = array_fill_keys(array_keys(self::DEFAULT_FREE_FEATURES), true);
        $allLimits   = array_fill_keys(array_keys(self::DEFAULT_FREE_FEATURES), null);

        // Still compute real usage for display purposes
        $usageData = [];
        $remainingData = [];
        foreach (array_keys(self::DEFAULT_FREE_FEATURES) as $key) {
            $used = $this->usageService->usage($user, $key);
            $usageData[$key] = $used;
            $remainingData[$key] = null; // unlimited
        }

        $latestSubscription = $user->subscriptions()->latest('created_at')->first();

        return [
            'plan'         => $plan,
            'subscription' => $latestSubscription,
            'features'     => $allFeatures,
            'limits'       => $allLimits,
            'usage'        => $usageData,
            'remaining'    => $remainingData,
        ];
    }
}
