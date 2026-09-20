<?php

namespace Tests\Feature\Api\V1;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SubscriptionCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_unauthenticated_user_cannot_initiate_checkout(): void
    {
        $response = $this->postJson('/api/v1/subscription/checkout', [
            'plan' => 'pro',
            'interval' => 'monthly',
        ]);

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    public function test_authenticated_user_can_request_pro_monthly_checkout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/subscription/checkout', [
            'plan' => 'pro',
            'interval' => 'monthly',
        ]);

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'checkout_url',
                    'session_id',
                    'provider',
                ],
            ]);
    }

    public function test_authenticated_user_can_request_business_yearly_checkout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/subscription/checkout', [
            'plan' => 'business',
            'interval' => 'yearly',
        ]);

        $response->assertStatus(Response::HTTP_OK)
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_invalid_plan_code_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/subscription/checkout', [
            'plan' => 'ultra-deluxe',
            'interval' => 'monthly',
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_free_plan_checkout_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/subscription/checkout', [
            'plan' => 'free',
            'interval' => 'monthly',
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_invalid_billing_interval_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/subscription/checkout', [
            'plan' => 'pro',
            'interval' => 'weekly',
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_billing_portal_session_generated_for_subscriber(): void
    {
        $user = User::factory()->create();
        $proPlan = Plan::where('code', 'pro')->first();

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $proPlan->id,
            'provider' => 'null',
            'provider_customer_id' => 'cus_null_123',
            'provider_subscription_id' => 'sub_null_123',
            'status' => 'active',
            'billing_interval' => 'monthly',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/subscription/portal');

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'portal_url',
                    'provider',
                ],
            ]);
    }
}
