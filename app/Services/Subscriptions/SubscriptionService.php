<?php

namespace App\Services\Subscriptions;

use App\Contracts\SubscriptionProviderInterface;
use App\DTOs\Subscriptions\CheckoutSessionData;
use App\DTOs\Subscriptions\CustomerPortalData;
use App\DTOs\Subscriptions\SubscriptionProviderData;
use App\Exceptions\PaymentProviderException;
use App\Exceptions\SubscriptionDowngradeBlockedException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\FeatureUsageService;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    public function __construct(
        protected SubscriptionProviderInterface $provider,
        protected SubscriptionSyncService $syncService,
        protected FeatureUsageService $usageService,
        protected SubscriptionLifecycleService $lifecycleService
    ) {}

    /**
     * Get the active subscription for user if any.
     */
    public function getActiveSubscription(User $user): ?Subscription
    {
        return $user->subscriptions()
            ->latest('created_at')
            ->first();
    }

    /**
     * Create a checkout session for upgrading to a paid plan.
     */
    public function checkout(User $user, string $planCode, string $interval = 'monthly'): CheckoutSessionData
    {
        $planCode = strtolower(trim($planCode));
        $interval = strtolower(trim($interval));

        if (!in_array($planCode, ['pro', 'business'])) {
            throw new PaymentProviderException(
                "Cannot checkout for plan '{$planCode}'. Only 'pro' and 'business' require checkout.",
                'CHECKOUT_CREATION_FAILED',
                400
            );
        }

        if (!in_array($interval, ['monthly', 'yearly'])) {
            throw new PaymentProviderException(
                "Invalid billing interval '{$interval}'. Must be 'monthly' or 'yearly'.",
                'CHECKOUT_CREATION_FAILED',
                400
            );
        }

        $plan = Plan::where('code', $planCode)->orWhere('slug', $planCode)->firstOrFail();

        return $this->provider->createCheckoutSession($user, $plan, $interval);
    }

    /**
     * Create a Customer Billing Portal session.
     */
    public function portal(User $user): CustomerPortalData
    {
        return $this->provider->createCustomerPortalSession($user);
    }

    /**
     * Change a subscription's plan tier or billing interval.
     */
    public function changePlan(User $user, string $newPlanCode, string $interval = 'monthly'): Subscription
    {
        $newPlanCode = strtolower(trim($newPlanCode));
        $interval = strtolower(trim($interval));

        $subscription = $this->getActiveSubscription($user);

        if (!$subscription || !$subscription->isActive()) {
            throw new PaymentProviderException(
                'No active subscription found to modify. Please initiate checkout instead.',
                'SUBSCRIPTION_NOT_FOUND',
                404
            );
        }

        $newPlan = Plan::where('code', $newPlanCode)->orWhere('slug', $newPlanCode)->firstOrFail();

        // 1. Safety check for downgrade: Check if user usage fits within new plan limits
        $currentProfiles = $this->usageService->getProfileCount($user);
        $currentDomains = $this->usageService->getCustomDomainCount($user);

        $allowedProfiles = $newPlan->features['profile_count'] ?? 1;
        $allowedDomains = $newPlan->features['custom_domain_count'] ?? 0;

        $conflicts = [];
        if ($currentProfiles > $allowedProfiles) {
            $conflicts['profiles'] = [
                'current' => $currentProfiles,
                'allowed' => $allowedProfiles,
            ];
        }

        if ($currentDomains > $allowedDomains) {
            $conflicts['custom_domains'] = [
                'current' => $currentDomains,
                'allowed' => $allowedDomains,
            ];
        }

        if (!empty($conflicts)) {
            throw new SubscriptionDowngradeBlockedException(
                'Reduce usage before downgrading.',
                $conflicts
            );
        }

        // 2. Call provider to update plan
        $providerData = $this->provider->changeSubscriptionPlan($subscription, $newPlan, $interval);

        // 3. Synchronize local record
        return $this->syncService->syncFromProviderData($providerData, $user);
    }

    /**
     * Cancel an active subscription (schedules period-end cancellation).
     */
    public function cancel(User $user): Subscription
    {
        $subscription = $this->getActiveSubscription($user);

        if (!$subscription || !$subscription->isActive()) {
            throw new PaymentProviderException(
                'No active subscription found to cancel.',
                'SUBSCRIPTION_NOT_FOUND',
                404
            );
        }

        if ($subscription->cancel_at_period_end) {
            throw new PaymentProviderException(
                'Subscription is already scheduled for cancellation at period end.',
                'SUBSCRIPTION_ALREADY_CANCELED',
                400
            );
        }

        $providerData = $this->provider->cancelSubscription($subscription, false);

        return $this->syncService->syncFromProviderData($providerData, $user);
    }

    /**
     * Resume a canceled subscription if still within active period.
     */
    public function resume(User $user): Subscription
    {
        $subscription = $this->getActiveSubscription($user);

        if (!$subscription) {
            throw new PaymentProviderException(
                'No subscription found to resume.',
                'SUBSCRIPTION_NOT_FOUND',
                404
            );
        }

        if (!$subscription->cancel_at_period_end) {
            throw new PaymentProviderException(
                'Subscription is already active and renewing normally.',
                'SUBSCRIPTION_CHANGE_NOT_ALLOWED',
                400
            );
        }

        $providerData = $this->provider->resumeSubscription($subscription);

        return $this->syncService->syncFromProviderData($providerData, $user);
    }
}
