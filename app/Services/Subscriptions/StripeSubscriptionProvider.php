<?php

namespace App\Services\Subscriptions;

use App\Contracts\SubscriptionProviderInterface;
use App\DTOs\Subscriptions\CheckoutSessionData;
use App\DTOs\Subscriptions\CustomerPortalData;
use App\DTOs\Subscriptions\SubscriptionProviderData;
use App\Enums\SubscriptionStatus;
use App\Exceptions\PaymentProviderException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StripeSubscriptionProvider implements SubscriptionProviderInterface
{
    protected string $secretKey;
    protected string $webhookSecret;
    protected string $baseUrl = 'https://api.stripe.com/v1';

    public function __construct()
    {
        $this->secretKey = (string) config('services.stripe.secret', '');
        $this->webhookSecret = (string) config('services.stripe.webhook_secret', '');
    }

    public function getProviderName(): string
    {
        return 'stripe';
    }

    /**
     * Create a Stripe Checkout Session for subscription purchase.
     */
    public function createCheckoutSession(
        User $user,
        Plan $plan,
        string $interval = 'monthly',
        ?string $successUrl = null,
        ?string $cancelUrl = null
    ): CheckoutSessionData {
        $priceId = $this->resolvePriceId($plan->code, $interval);

        if (!$priceId) {
            throw new PaymentProviderException(
                "No Stripe Price ID configured for plan '{$plan->code}' with interval '{$interval}'.",
                'CHECKOUT_CREATION_FAILED',
                400
            );
        }

        $customerId = $this->getOrCreateCustomerId($user);

        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $resolvedSuccessUrl = $successUrl ?: "{$frontendUrl}/dashboard/billing/success?session_id={CHECKOUT_SESSION_ID}";
        $resolvedCancelUrl = $cancelUrl ?: "{$frontendUrl}/dashboard/billing/cancel";

        $payload = [
            'customer' => $customerId,
            'mode' => 'subscription',
            'line_items' => [
                [
                    'price' => $priceId,
                    'quantity' => 1,
                ],
            ],
            'success_url' => $resolvedSuccessUrl,
            'cancel_url' => $resolvedCancelUrl,
            'client_reference_id' => $user->id,
            'metadata' => [
                'user_id' => $user->id,
                'plan_code' => $plan->code,
                'billing_interval' => $interval,
            ],
            'subscription_data' => [
                'metadata' => [
                    'user_id' => $user->id,
                    'plan_code' => $plan->code,
                    'billing_interval' => $interval,
                ],
            ],
        ];

        $response = $this->client()->asForm()->post("{$this->baseUrl}/checkout/sessions", $payload);

        if (!$response->successful()) {
            Log::error('[StripeProvider] Checkout session creation failed', [
                'user_id' => $user->id,
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);

            throw new PaymentProviderException(
                $response->json('error.message', 'Failed to create checkout session with payment provider.'),
                'CHECKOUT_CREATION_FAILED',
                $response->status() >= 500 ? 503 : 400
            );
        }

        $data = $response->json();

        return new CheckoutSessionData(
            checkoutUrl: $data['url'] ?? '',
            sessionId: $data['id'] ?? '',
            provider: 'stripe'
        );
    }

    /**
     * Create a Stripe Customer Billing Portal session.
     */
    public function createCustomerPortalSession(User $user, ?string $returnUrl = null): CustomerPortalData
    {
        $customerId = $this->getExistingCustomerId($user);

        if (!$customerId) {
            throw new PaymentProviderException(
                'No billing customer record found. Please subscribe to a plan first.',
                'BILLING_PORTAL_UNAVAILABLE',
                400
            );
        }

        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $resolvedReturnUrl = $returnUrl ?: "{$frontendUrl}/dashboard/billing";

        $response = $this->client()->asForm()->post("{$this->baseUrl}/billing_portal/sessions", [
            'customer' => $customerId,
            'return_url' => $resolvedReturnUrl,
        ]);

        if (!$response->successful()) {
            Log::error('[StripeProvider] Customer portal creation failed', [
                'user_id' => $user->id,
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);

            throw new PaymentProviderException(
                $response->json('error.message', 'Failed to create billing portal session.'),
                'BILLING_PORTAL_UNAVAILABLE',
                $response->status() >= 500 ? 503 : 400
            );
        }

        return new CustomerPortalData(
            portalUrl: $response->json('url', ''),
            provider: 'stripe'
        );
    }

    /**
     * Cancel an active subscription.
     */
    public function cancelSubscription(Subscription $subscription, bool $immediately = false): SubscriptionProviderData
    {
        $subId = $subscription->provider_subscription_id;

        if (!$subId) {
            throw new PaymentProviderException(
                'Missing provider subscription ID.',
                'SUBSCRIPTION_NOT_FOUND',
                404
            );
        }

        if ($immediately) {
            $response = $this->client()->delete("{$this->baseUrl}/subscriptions/{$subId}");
        } else {
            $response = $this->client()->asForm()->post("{$this->baseUrl}/subscriptions/{$subId}", [
                'cancel_at_period_end' => 'true',
            ]);
        }

        if (!$response->successful()) {
            throw new PaymentProviderException(
                $response->json('error.message', 'Failed to cancel subscription with payment provider.'),
                'PAYMENT_PROVIDER_ERROR',
                $response->status()
            );
        }

        return $this->transformStripeSubscription($response->json());
    }

    /**
     * Resume a canceled subscription if still within period.
     */
    public function resumeSubscription(Subscription $subscription): SubscriptionProviderData
    {
        $subId = $subscription->provider_subscription_id;

        if (!$subId) {
            throw new PaymentProviderException(
                'Missing provider subscription ID.',
                'SUBSCRIPTION_NOT_FOUND',
                404
            );
        }

        $response = $this->client()->asForm()->post("{$this->baseUrl}/subscriptions/{$subId}", [
            'cancel_at_period_end' => 'false',
        ]);

        if (!$response->successful()) {
            throw new PaymentProviderException(
                $response->json('error.message', 'Failed to resume subscription with payment provider.'),
                'PAYMENT_PROVIDER_ERROR',
                $response->status()
            );
        }

        return $this->transformStripeSubscription($response->json());
    }

    /**
     * Change a subscription plan or interval.
     */
    public function changeSubscriptionPlan(
        Subscription $subscription,
        Plan $newPlan,
        string $interval = 'monthly'
    ): SubscriptionProviderData {
        $subId = $subscription->provider_subscription_id;

        if (!$subId) {
            throw new PaymentProviderException(
                'Missing provider subscription ID.',
                'SUBSCRIPTION_NOT_FOUND',
                404
            );
        }

        $newPriceId = $this->resolvePriceId($newPlan->code, $interval);

        if (!$newPriceId) {
            throw new PaymentProviderException(
                "No Stripe Price ID configured for plan '{$newPlan->code}' ({$interval}).",
                'CHECKOUT_CREATION_FAILED',
                400
            );
        }

        // Retrieve current subscription to find items[0].id
        $currentSubResponse = $this->client()->get("{$this->baseUrl}/subscriptions/{$subId}");

        if (!$currentSubResponse->successful()) {
            throw new PaymentProviderException(
                'Unable to retrieve current provider subscription.',
                'SUBSCRIPTION_NOT_FOUND',
                404
            );
        }

        $items = $currentSubResponse->json('items.data', []);
        $itemId = $items[0]['id'] ?? null;

        if (!$itemId) {
            throw new PaymentProviderException(
                'Subscription has no active item lines to modify.',
                'SUBSCRIPTION_CHANGE_NOT_ALLOWED',
                400
            );
        }

        $response = $this->client()->asForm()->post("{$this->baseUrl}/subscriptions/{$subId}", [
            'items' => [
                [
                    'id' => $itemId,
                    'price' => $newPriceId,
                ],
            ],
            'proration_behavior' => 'create_prorations',
            'metadata' => [
                'user_id' => $subscription->user_id,
                'plan_code' => $newPlan->code,
                'billing_interval' => $interval,
            ],
        ]);

        if (!$response->successful()) {
            throw new PaymentProviderException(
                $response->json('error.message', 'Failed to change subscription plan with payment provider.'),
                'SUBSCRIPTION_CHANGE_NOT_ALLOWED',
                $response->status()
            );
        }

        return $this->transformStripeSubscription($response->json());
    }

    /**
     * Retrieve current subscription details from Stripe.
     */
    public function retrieveSubscription(string $providerSubscriptionId): ?SubscriptionProviderData
    {
        $response = $this->client()->get("{$this->baseUrl}/subscriptions/{$providerSubscriptionId}");

        if (!$response->successful()) {
            return null;
        }

        return $this->transformStripeSubscription($response->json());
    }

    /**
     * Verify Stripe webhook signature against raw payload.
     */
    public function verifyWebhookSignature(string $payload, string $signatureHeader, string $secret): bool
    {
        if (empty($signatureHeader) || empty($secret)) {
            return false;
        }

        // Parse signature header: "t=1492774577,v1=5257a869e7ecebeda32affa62cdca3fa51cad7e77a0e56ff536d0ce8e108d8bd"
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $signatureHeader) as $item) {
            $parts = explode('=', trim($item), 2);
            if (count($parts) === 2) {
                if ($parts[0] === 't') {
                    $timestamp = $parts[1];
                } elseif ($parts[0] === 'v1') {
                    $signatures[] = $parts[1];
                }
            }
        }

        if (!$timestamp || empty($signatures)) {
            return false;
        }

        // Check timestamp tolerance (300 seconds)
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $signedPayload = "{$timestamp}.{$payload}";
        $expectedSignature = hash_hmac('sha256', $signedPayload, $secret);

        foreach ($signatures as $sig) {
            if (hash_equals($expectedSignature, $sig)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves server-side configured Stripe Price ID.
     */
    protected function resolvePriceId(string $planCode, string $interval): ?string
    {
        return config("services.stripe.prices.{$planCode}_{$interval}")
            ?: config("services.stripe.prices.{$planCode}");
    }

    /**
     * Get existing Stripe customer ID or create a new one.
     */
    protected function getOrCreateCustomerId(User $user): string
    {
        $existingId = $this->getExistingCustomerId($user);

        if ($existingId) {
            return $existingId;
        }

        $response = $this->client()->asForm()->post("{$this->baseUrl}/customers", [
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => [
                'user_id' => $user->id,
            ],
        ]);

        if (!$response->successful()) {
            throw new PaymentProviderException(
                $response->json('error.message', 'Failed to create customer record with payment provider.'),
                'CHECKOUT_CREATION_FAILED',
                $response->status() >= 500 ? 503 : 400
            );
        }

        return $response->json('id');
    }

    protected function getExistingCustomerId(User $user): ?string
    {
        return $user->subscriptions()
            ->whereNotNull('provider_customer_id')
            ->latest('created_at')
            ->value('provider_customer_id');
    }

    /**
     * Transform Stripe Subscription payload to typed DTO.
     */
    protected function transformStripeSubscription(array $data): SubscriptionProviderData
    {
        $statusStr = $data['status'] ?? 'active';
        $status = match ($statusStr) {
            'active' => SubscriptionStatus::Active,
            'trialing' => SubscriptionStatus::Trialing,
            'past_due' => SubscriptionStatus::PastDue,
            'unpaid' => SubscriptionStatus::Unpaid,
            'canceled' => SubscriptionStatus::Canceled,
            'incomplete' => SubscriptionStatus::Incomplete,
            'incomplete_expired' => SubscriptionStatus::IncompleteExpired,
            'paused' => SubscriptionStatus::Paused,
            default => SubscriptionStatus::Active,
        };

        $item = $data['items']['data'][0] ?? [];
        $priceId = $item['price']['id'] ?? null;
        $interval = $item['price']['recurring']['interval'] ?? 'month';
        $billingInterval = $interval === 'year' ? 'yearly' : 'monthly';

        // Plan code from metadata or lookup
        $planCode = $data['metadata']['plan_code'] ?? 'pro';

        return new SubscriptionProviderData(
            provider: 'stripe',
            providerCustomerId: $data['customer'] ?? null,
            providerSubscriptionId: $data['id'] ?? null,
            providerPriceId: $priceId,
            status: $status,
            planCode: $planCode,
            billingInterval: $billingInterval,
            currentPeriodStart: isset($data['current_period_start']) ? Carbon::createFromTimestamp($data['current_period_start']) : null,
            currentPeriodEnd: isset($data['current_period_end']) ? Carbon::createFromTimestamp($data['current_period_end']) : null,
            cancelAtPeriodEnd: (bool) ($data['cancel_at_period_end'] ?? false),
            canceledAt: isset($data['canceled_at']) && $data['canceled_at'] ? Carbon::createFromTimestamp($data['canceled_at']) : null,
            trialEndsAt: isset($data['trial_end']) && $data['trial_end'] ? Carbon::createFromTimestamp($data['trial_end']) : null,
            gracePeriodEndsAt: null,
            metadata: $data['metadata'] ?? null
        );
    }

    protected function client()
    {
        return Http::withToken($this->secretKey)
            ->timeout(15)
            ->withHeaders([
                'Stripe-Version' => '2023-10-16',
            ]);
    }
}
