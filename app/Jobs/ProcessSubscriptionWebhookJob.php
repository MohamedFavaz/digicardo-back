<?php

namespace App\Jobs;

use App\Contracts\SubscriptionProviderInterface;
use App\DTOs\Subscriptions\SubscriptionProviderData;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionWebhookEvent;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionLifecycleService;
use App\Services\Subscriptions\SubscriptionSyncService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessSubscriptionWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        public readonly string $provider,
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly array $payload
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        SubscriptionSyncService $syncService,
        SubscriptionLifecycleService $lifecycleService,
        SubscriptionProviderInterface $provider
    ): void {
        $eventLog = SubscriptionWebhookEvent::where('provider', $this->provider)
            ->where('provider_event_id', $this->eventId)
            ->first();

        try {
            Log::info('[SubscriptionWebhookJob] Processing event', [
                'provider' => $this->provider,
                'event_type' => $this->eventType,
                'event_id' => $this->eventId,
            ]);

            switch ($this->eventType) {
                case 'checkout.session.completed':
                    $this->handleCheckoutSessionCompleted($syncService, $provider);
                    break;

                case 'customer.subscription.created':
                case 'customer.subscription.updated':
                    $this->handleSubscriptionUpdated($syncService, $provider);
                    break;

                case 'customer.subscription.deleted':
                    $this->handleSubscriptionDeleted($lifecycleService);
                    break;

                case 'invoice.paid':
                    $this->handleInvoicePaid($lifecycleService);
                    break;

                case 'invoice.payment_failed':
                    $this->handleInvoicePaymentFailed($lifecycleService);
                    break;

                default:
                    Log::info("[SubscriptionWebhookJob] Unhandled event type '{$this->eventType}' safely acknowledged.");
                    break;
            }

            $eventLog?->markAsProcessed();
        } catch (\Throwable $e) {
            Log::error('[SubscriptionWebhookJob] Failed to process webhook event', [
                'event_id' => $this->eventId,
                'error' => $e->getMessage(),
            ]);

            $eventLog?->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    protected function handleCheckoutSessionCompleted(
        SubscriptionSyncService $syncService,
        SubscriptionProviderInterface $provider
    ): void {
        $data = $this->payload['data']['object'] ?? $this->payload;
        $subscriptionId = $data['subscription'] ?? null;
        $customerId = $data['customer'] ?? null;
        $userId = $data['metadata']['user_id'] ?? $data['client_reference_id'] ?? null;
        $planCode = $data['metadata']['plan_code'] ?? 'pro';
        $interval = $data['metadata']['billing_interval'] ?? 'monthly';

        $user = $userId ? User::find($userId) : null;

        if ($subscriptionId) {
            // Retrieve fresh state from provider
            $providerData = $provider->retrieveSubscription($subscriptionId);
            if ($providerData) {
                $syncService->syncFromProviderData($providerData, $user);
                return;
            }
        }

        // Fallback sync from checkout data
        $dto = new SubscriptionProviderData(
            provider: $this->provider,
            providerCustomerId: $customerId,
            providerSubscriptionId: $subscriptionId ?: ('sub_chk_' . uniqid()),
            providerPriceId: null,
            status: SubscriptionStatus::Active,
            planCode: $planCode,
            billingInterval: $interval,
            currentPeriodStart: now(),
            currentPeriodEnd: $interval === 'yearly' ? now()->addYear() : now()->addMonth(),
            cancelAtPeriodEnd: false,
            canceledAt: null,
            trialEndsAt: null,
            gracePeriodEndsAt: null,
            metadata: $data['metadata'] ?? []
        );

        $syncService->syncFromProviderData($dto, $user);
    }

    protected function handleSubscriptionUpdated(
        SubscriptionSyncService $syncService,
        SubscriptionProviderInterface $provider
    ): void {
        $data = $this->payload['data']['object'] ?? $this->payload;
        $subId = $data['id'] ?? null;

        if (!$subId) {
            return;
        }

        // Attempt retrieval via provider
        $providerData = $provider->retrieveSubscription($subId);
        if ($providerData) {
            $syncService->syncFromProviderData($providerData);
            return;
        }

        // Fallback to payload transform
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
        $planCode = $data['metadata']['plan_code'] ?? 'pro';

        $dto = new SubscriptionProviderData(
            provider: $this->provider,
            providerCustomerId: $data['customer'] ?? null,
            providerSubscriptionId: $subId,
            providerPriceId: $priceId,
            status: $status,
            planCode: $planCode,
            billingInterval: $billingInterval,
            currentPeriodStart: isset($data['current_period_start']) ? Carbon::createFromTimestamp($data['current_period_start']) : now(),
            currentPeriodEnd: isset($data['current_period_end']) ? Carbon::createFromTimestamp($data['current_period_end']) : now()->addMonth(),
            cancelAtPeriodEnd: (bool) ($data['cancel_at_period_end'] ?? false),
            canceledAt: isset($data['canceled_at']) && $data['canceled_at'] ? Carbon::createFromTimestamp($data['canceled_at']) : null,
            trialEndsAt: isset($data['trial_end']) && $data['trial_end'] ? Carbon::createFromTimestamp($data['trial_end']) : null,
            gracePeriodEndsAt: null,
            metadata: $data['metadata'] ?? []
        );

        $syncService->syncFromProviderData($dto);
    }

    protected function handleSubscriptionDeleted(SubscriptionLifecycleService $lifecycleService): void
    {
        $data = $this->payload['data']['object'] ?? $this->payload;
        $subId = $data['id'] ?? null;

        if ($subId) {
            $lifecycleService->handleSubscriptionDeleted($subId);
        }
    }

    protected function handleInvoicePaid(SubscriptionLifecycleService $lifecycleService): void
    {
        $data = $this->payload['data']['object'] ?? $this->payload;
        $subId = $data['subscription'] ?? null;

        if ($subId) {
            $lifecycleService->handlePaymentSuccess($subId);
        }
    }

    protected function handleInvoicePaymentFailed(SubscriptionLifecycleService $lifecycleService): void
    {
        $data = $this->payload['data']['object'] ?? $this->payload;
        $subId = $data['subscription'] ?? null;
        $reason = $data['last_payment_error']['message'] ?? 'Payment attempt failed.';

        if ($subId) {
            $lifecycleService->handlePaymentFailed($subId, $reason);
        }
    }
}
