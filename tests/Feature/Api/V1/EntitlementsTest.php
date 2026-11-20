<?php

namespace Tests\Feature\Api\V1;

use App\Enums\FeatureKey;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
    }

    public function test_plans_endpoint_returns_active_plans_list(): void
    {
        $response = $this->getJson('/api/v1/plans');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data');

        $response->assertJsonFragment(['code' => 'free', 'name' => 'Free'])
            ->assertJsonFragment(['code' => 'pro', 'name' => 'Pro'])
            ->assertJsonFragment(['code' => 'business', 'name' => 'Business']);
    }

    public function test_unauthenticated_user_cannot_access_entitlements_or_subscription(): void
    {
        $this->getJson('/api/v1/entitlements')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->getJson('/api/v1/subscription')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    /**
     * PLATFORM POLICY (FeatureEntitlementService, commit 33cedc0): every feature is
     * unlocked for every user and no limits apply. Plans are still resolved so billing
     * screens show the right plan, and usage is still counted for display.
     */
    private function assertEverythingUnlocked(\Illuminate\Testing\TestResponse $response): void
    {
        foreach (['gallery_blocks', 'video_blocks', 'music_blocks', 'booking_blocks', 'advanced_templates', 'remove_branding'] as $feature) {
            $response->assertJsonPath("data.features.{$feature}", true);
        }
        foreach (['profile_count', 'custom_domain_count', 'analytics_history_days'] as $limit) {
            $response->assertJsonPath("data.limits.{$limit}", null);
        }
    }

    public function test_new_user_without_paid_subscription_resolves_to_free_plan(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/subscription');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.plan_code', 'free');

        $entitlementsRes = $this->actingAs($user)->getJson('/api/v1/entitlements');

        $entitlementsRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.plan.code', 'free');
        $this->assertEverythingUnlocked($entitlementsRes);
    }

    public function test_user_with_active_pro_subscription_receives_pro_entitlements(): void
    {
        $user = User::factory()->create();
        $proPlan = Plan::where('code', 'pro')->firstOrFail();

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $proPlan->id,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_123',
            'provider_subscription_id' => 'sub_123',
            'status' => SubscriptionStatus::Active,
            'billing_interval' => 'monthly',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/entitlements');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.plan.code', 'pro');
        $this->assertEverythingUnlocked($response);
    }

    public function test_canceled_expired_subscription_falls_back_to_free_plan(): void
    {
        $user = User::factory()->create();
        $proPlan = Plan::where('code', 'pro')->firstOrFail();

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $proPlan->id,
            'provider' => 'stripe',
            'status' => SubscriptionStatus::Canceled,
            'billing_interval' => 'monthly',
            'current_period_end' => now()->subDay(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/entitlements');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.plan.code', 'free');
        $this->assertEverythingUnlocked($response);
    }

    public function test_usage_is_counted_and_remaining_is_unlimited(): void
    {
        $user = User::factory()->create();
        Profile::create([
            'user_id' => $user->id,
            'username' => 'usertest1',
            'display_name' => 'User Test',
            'template_id' => 'vcard',
            'is_public' => true,
            'version' => 1,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/entitlements');

        $response->assertStatus(200)
            ->assertJsonPath('data.usage.profile_count', 1)
            ->assertJsonPath('data.remaining.profile_count', null)
            ->assertJsonPath('data.usage.custom_domain_count', 0)
            ->assertJsonPath('data.remaining.custom_domain_count', null);
    }
}
