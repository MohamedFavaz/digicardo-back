<?php

namespace App\Services;

use App\Contracts\SubscriptionProviderInterface;
use App\DTOs\Subscriptions\CheckoutSessionData;
use App\DTOs\Subscriptions\CustomerPortalData;
use App\DTOs\Subscriptions\SubscriptionProviderData;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

class NullSubscriptionProvider implements SubscriptionProviderInterface
{
    public function getProviderName(): string
    {
        return 'null';
    }

    public function createCheckoutSession(
        User $user,
        Plan $plan,
        string $interval = 'monthly',
        ?string $successUrl = null,
        ?string $cancelUrl = null
    ): CheckoutSessionData {
        return new CheckoutSessionData(
            checkoutUrl: $successUrl ?: config('app.frontend_url', 'http://localhost:3000') . '/dashboard/billing/success?session_id=null_checkout_test',
            sessionId: 'null_sess_' . uniqid(),
            provider: 'null',
            message: 'Checkout provider is operating in null/mock mode for development and testing.'
        );
    }

    public function createCustomerPortalSession(User $user, ?string $returnUrl = null): CustomerPortalData
    {
        return new CustomerPortalData(
            portalUrl: $returnUrl ?: config('app.frontend_url', 'http://localhost:3000') . '/dashboard/billing',
            provider: 'null'
        );
    }

    public function cancelSubscription(Subscription $subscription, bool $immediately = false): SubscriptionProviderData
    {
        return new SubscriptionProviderData(
            provider: 'null',
            providerCustomerId: $subscription->provider_customer_id,
            providerSubscriptionId: $subscription->provider_subscription_id,
            providerPriceId: $subscription->provider_price_id,
            status: $immediately ? SubscriptionStatus::Canceled : ($subscription->status instanceof SubscriptionStatus ? $subscription->status : SubscriptionStatus::tryFrom((string) $subscription->status) ?? SubscriptionStatus::Active),
            planCode: $subscription->plan?->code ?? 'pro',
            billingInterval: $subscription->billing_interval ?? 'monthly',
            currentPeriodStart: $subscription->current_period_start ?: now(),
            currentPeriodEnd: $subscription->current_period_end ?: now()->addMonth(),
            cancelAtPeriodEnd: !$immediately,
            canceledAt: now(),
            trialEndsAt: $subscription->trial_ends_at,
            gracePeriodEndsAt: $subscription->grace_period_ends_at
        );
    }

    public function resumeSubscription(Subscription $subscription): SubscriptionProviderData
    {
        return new SubscriptionProviderData(
            provider: 'null',
            providerCustomerId: $subscription->provider_customer_id,
            providerSubscriptionId: $subscription->provider_subscription_id,
            providerPriceId: $subscription->provider_price_id,
            status: SubscriptionStatus::Active,
            planCode: $subscription->plan?->code ?? 'pro',
            billingInterval: $subscription->billing_interval ?? 'monthly',
            currentPeriodStart: $subscription->current_period_start ?: now(),
            currentPeriodEnd: $subscription->current_period_end ?: now()->addMonth(),
            cancelAtPeriodEnd: false,
            canceledAt: null,
            trialEndsAt: $subscription->trial_ends_at,
            gracePeriodEndsAt: null
        );
    }

    public function changeSubscriptionPlan(
        Subscription $subscription,
        Plan $newPlan,
        string $interval = 'monthly'
    ): SubscriptionProviderData {
        return new SubscriptionProviderData(
            provider: 'null',
            providerCustomerId: $subscription->provider_customer_id,
            providerSubscriptionId: $subscription->provider_subscription_id,
            providerPriceId: 'null_price_' . $newPlan->code . '_' . $interval,
            status: SubscriptionStatus::Active,
            planCode: $newPlan->code,
            billingInterval: $interval,
            currentPeriodStart: now(),
            currentPeriodEnd: $interval === 'yearly' ? now()->addYear() : now()->addMonth(),
            cancelAtPeriodEnd: false,
            canceledAt: null,
            trialEndsAt: null,
            gracePeriodEndsAt: null
        );
    }

    public function retrieveSubscription(string $providerSubscriptionId): ?SubscriptionProviderData
    {
        $existing = Subscription::where('provider_subscription_id', $providerSubscriptionId)->first();

        if (!$existing) {
            return null;
        }

        return new SubscriptionProviderData(
            provider: 'null',
            providerCustomerId: $existing->provider_customer_id ?? 'null_cus_test',
            providerSubscriptionId: $providerSubscriptionId,
            providerPriceId: $existing->provider_price_id ?? 'null_price_pro_monthly',
            status: $existing->status ?? SubscriptionStatus::Active,
            planCode: $existing->plan?->code ?? 'pro',
            billingInterval: $existing->billing_interval ?? 'monthly',
            currentPeriodStart: $existing->current_period_start ?? now(),
            currentPeriodEnd: $existing->current_period_end ?? now()->addMonth(),
            cancelAtPeriodEnd: (bool) $existing->cancel_at_period_end,
            canceledAt: $existing->canceled_at,
            trialEndsAt: $existing->trial_ends_at,
            gracePeriodEndsAt: $existing->grace_period_ends_at
        );
    }

    public function verifyWebhookSignature(string $payload, string $signatureHeader, string $secret): bool
    {
        // For testing, accept if header matches expected signature or equals test token
        if ($signatureHeader === 'valid_test_signature' || $signatureHeader === 'test_sig') {
            return true;
        }

        // Check standard HMAC sha256
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signatureHeader);
    }
}
