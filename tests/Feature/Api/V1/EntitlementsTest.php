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
            ->assertJsonPath('data.plan.code', 'free')
            ->assertJsonPath('data.limits.profile_count', 1)
            ->assertJsonPath('data.limits.custom_domain_count', 0)
            ->assertJsonPath('data.limits.analytics_history_days', 7)
            ->assertJsonPath('data.features.gallery_blocks', false)
            ->assertJsonPath('data.features.video_blocks', false)
            ->assertJsonPath('data.features.music_blocks', false)
            ->assertJsonPath('data.features.booking_blocks', false)
            ->assertJsonPath('data.features.advanced_templates', false)
            ->assertJsonPath('data.features.remove_branding', false);
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
            ->assertJsonPath('data.plan.code', 'pro')
            ->assertJsonPath('data.limits.profile_count', 3)
            ->assertJsonPath('data.limits.custom_domain_count', 1)
            ->assertJsonPath('data.limits.analytics_history_days', 90)
            ->assertJsonPath('data.features.gallery_blocks', true)
            ->assertJsonPath('data.features.video_blocks', true)
            ->assertJsonPath('data.features.music_blocks', true)
            ->assertJsonPath('data.features.booking_blocks', true)
            ->assertJsonPath('data.features.advanced_templates', true)
            ->assertJsonPath('data.features.remove_branding', true);
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
            ->assertJsonPath('data.plan.code', 'free')
            ->assertJsonPath('data.limits.profile_count', 1)
            ->assertJsonPath('data.features.gallery_blocks', false);
    }

    public function test_usage_and_remaining_are_accurately_computed(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create([
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
            ->assertJsonPath('data.remaining.profile_count', 0)
            ->assertJsonPath('data.usage.custom_domain_count', 0)
            ->assertJsonPath('data.remaining.custom_domain_count', 0);
    }
}
