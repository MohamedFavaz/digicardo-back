<?php

namespace App\Contracts;

use App\DTOs\Subscriptions\CheckoutSessionData;
use App\DTOs\Subscriptions\CustomerPortalData;
use App\DTOs\Subscriptions\SubscriptionProviderData;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

interface SubscriptionProviderInterface
{
    /**
     * Provider identifier string (e.g. 'null', 'stripe', 'lemonsqueezy', 'paddle').
     */
    public function getProviderName(): string;

    /**
     * Create a checkout session for purchasing or upgrading a plan.
     */
    public function createCheckoutSession(
        User $user,
        Plan $plan,
        string $interval = 'monthly',
        ?string $successUrl = null,
        ?string $cancelUrl = null
    ): CheckoutSessionData;

    /**
     * Create a customer billing portal session URL.
     */
    public function createCustomerPortalSession(User $user, ?string $returnUrl = null): CustomerPortalData;

    /**
     * Cancel an active subscription (defaulting to cancel at period end).
     */
    public function cancelSubscription(Subscription $subscription, bool $immediately = false): SubscriptionProviderData;

    /**
     * Resume a canceled subscription if still within period.
     */
    public function resumeSubscription(Subscription $subscription): SubscriptionProviderData;

    /**
     * Change a subscription plan or billing interval.
     */
    public function changeSubscriptionPlan(
        Subscription $subscription,
        Plan $newPlan,
        string $interval = 'monthly'
    ): SubscriptionProviderData;

    /**
     * Retrieve the current state of a subscription from the provider.
     */
    public function retrieveSubscription(string $providerSubscriptionId): ?SubscriptionProviderData;

    /**
     * Verify the raw webhook payload signature.
     */
    public function verifyWebhookSignature(string $payload, string $signatureHeader, string $secret): bool;
}
