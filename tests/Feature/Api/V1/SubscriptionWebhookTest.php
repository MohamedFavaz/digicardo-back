<?php

namespace Tests\Feature\Api\V1;

use App\Enums\SubscriptionStatus;
use App\Jobs\ProcessSubscriptionWebhookJob;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionWebhookEvent;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SubscriptionWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_valid_webhook_signature_is_accepted_and_recorded(): void
    {
        Queue::fake();

        $payload = [
            'id' => 'evt_test_123',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_123',
                    'customer' => 'cus_123',
                    'subscription' => 'sub_123',
                ],
            ],
        ];

        $response = $this->withHeaders([
            'Stripe-Signature' => 'valid_test_signature',
        ])->postJson('/api/v1/webhooks/stripe', $payload);

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('data.status', 'acknowledged')
            ->assertJsonPath('data.event_id', 'evt_test_123');

        $this->assertDatabaseHas('subscription_webhook_events', [
            'provider' => 'stripe',
            'provider_event_id' => 'evt_test_123',
            'event_type' => 'checkout.session.completed',
        ]);

        Queue::assertPushed(ProcessSubscriptionWebhookJob::class, function ($job) {
            return $job->eventId === 'evt_test_123' && $job->eventType === 'checkout.session.completed';
        });
    }

    public function test_invalid_webhook_signature_is_rejected(): void
    {
        $response = $this->withHeaders([
            'Stripe-Signature' => 'invalid_signature_garbage',
        ])->postJson('/api/v1/webhooks/stripe', [
            'id' => 'evt_test_bad',
            'type' => 'checkout.session.completed',
        ]);

        $response->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('error.code', 'INVALID_WEBHOOK_SIGNATURE');
    }

    public function test_duplicate_webhook_event_is_handled_idempotently(): void
    {
        SubscriptionWebhookEvent::create([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_duplicate_test',
            'event_type' => 'invoice.paid',
            'payload_hash' => 'dummyhash123',
            'processed_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Stripe-Signature' => 'valid_test_signature',
        ])->postJson('/api/v1/webhooks/stripe', [
            'id' => 'evt_duplicate_test',
            'type' => 'invoice.paid',
        ]);

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('data.status', 'duplicate');
    }

    public function test_webhook_queue_job_processes_checkout_completion(): void
    {
        $user = User::factory()->create();

        $payload = [
            'id' => 'evt_checkout_completed',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_123',
                    'customer' => 'cus_new_123',
                    'subscription' => 'sub_new_123',
                    'client_reference_id' => $user->id,
                    'metadata' => [
                        'user_id' => $user->id,
                        'plan_code' => 'pro',
                        'billing_interval' => 'monthly',
                    ],
                ],
            ],
        ];

        SubscriptionWebhookEvent::create([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_checkout_completed',
            'event_type' => 'checkout.session.completed',
            'payload_hash' => 'hash123',
            'payload' => $payload,
        ]);

        $job = new ProcessSubscriptionWebhookJob(
            provider: 'stripe',
            eventId: 'evt_checkout_completed',
            eventType: 'checkout.session.completed',
            payload: $payload
        );

        app()->call([$job, 'handle']);

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'provider_customer_id' => 'cus_new_123',
            'status' => SubscriptionStatus::Active->value,
        ]);

        $this->assertDatabaseHas('subscription_webhook_events', [
            'provider_event_id' => 'evt_checkout_completed',
        ]);
        $this->assertNotNull(SubscriptionWebhookEvent::where('provider_event_id', 'evt_checkout_completed')->value('processed_at'));
    }

    public function test_webhook_queue_job_processes_payment_failure_with_grace_period(): void
    {
        $user = User::factory()->create();
        $proPlan = Plan::where('code', 'pro')->first();

        $sub = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $proPlan->id,
            'provider' => 'stripe',
            'provider_subscription_id' => 'sub_fail_123',
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $payload = [
            'id' => 'evt_fail_123',
            'type' => 'invoice.payment_failed',
            'data' => [
                'object' => [
                    'subscription' => 'sub_fail_123',
                    'last_payment_error' => ['message' => 'Card declined'],
                ],
            ],
        ];

        $job = new ProcessSubscriptionWebhookJob(
            provider: 'stripe',
            eventId: 'evt_fail_123',
            eventType: 'invoice.payment_failed',
            payload: $payload
        );

        app()->call([$job, 'handle']);

        $sub->refresh();
        $this->assertEquals(SubscriptionStatus::PastDue, $sub->status);
        $this->assertNotNull($sub->grace_period_ends_at);
        $this->assertTrue($sub->isInGracePeriod());
    }
}
