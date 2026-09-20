<?php

namespace Tests\Feature\Api\V1;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\FeatureEntitlementService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_cancellation_schedules_period_end_cancellation(): void
    {
        $user = User::factory()->create();
        $proPlan = Plan::where('code', 'pro')->first();

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $proPlan->id,
            'provider' => 'null',
            'provider_customer_id' => 'cus_123',
            'provider_subscription_id' => 'sub_123',
            'status' => 'active',
            'billing_interval' => 'monthly',
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(20),
            'cancel_at_period_end' => false,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/subscription/cancel');

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('data.cancel_at_period_end', true);

        $subscription->refresh();
        $this->assertTrue($subscription->cancel_at_period_end);
        $this->assertTrue($subscription->isActive());
    }

    public function test_user_retains_entitlements_when_cancellation_scheduled(): void
    {
        $user = User::factory()->create();
        $proPlan = Plan::where('code', 'pro')->first();

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $proPlan->id,
            'provider' => 'null',
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(15),
            'cancel_at_period_end' => true,
            'canceled_at' => now(),
        ]);

        $entitlementService = app(FeatureEntitlementService::class);
        $activePlan = $entitlementService->getActivePlan($user);

        $this->assertEquals('pro', $activePlan->code);
        $this->assertTrue($entitlementService->can($user, \App\Enums\FeatureKey::AdvancedTemplates));
    }

    public function test_resume_clears_scheduled_cancellation(): void
    {
        $user = User::factory()->create();
        $proPlan = Plan::where('code', 'pro')->first();

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $proPlan->id,
            'provider' => 'null',
            'provider_customer_id' => 'cus_123',
            'provider_subscription_id' => 'sub_123',
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(15),
            'cancel_at_period_end' => true,
            'canceled_at' => now(),
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/subscription/resume');

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('data.cancel_at_period_end', false);

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'cancel_at_period_end' => false,
        ]);
    }

    public function test_grace_period_retains_active_entitlements(): void
    {
        $user = User::factory()->create();
        $proPlan = Plan::where('code', 'pro')->first();

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $proPlan->id,
            'provider' => 'null',
            'status' => SubscriptionStatus::PastDue,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->subDay(),
            'grace_period_ends_at' => now()->addDays(5),
        ]);

        $this->assertTrue($subscription->isActive());
        $this->assertTrue($subscription->isInGracePeriod());

        $entitlementService = app(FeatureEntitlementService::class);
        $activePlan = $entitlementService->getActivePlan($user);
        $this->assertEquals('pro', $activePlan->code);
    }
}
