<?php

namespace Tests\Feature\Api\V1;

use App\Models\Plan;
use App\Models\Profile;
use App\Models\ProfileDomain;
use App\Models\Subscription;
use App\Models\User;
use App\Services\FeatureUsageService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SubscriptionPlanChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_upgrade_from_pro_to_business_succeeds(): void
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
            'billing_interval' => 'monthly',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/subscription/change-plan', [
            'plan' => 'business',
            'interval' => 'yearly',
        ]);

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('data.plan_code', 'business')
            ->assertJsonPath('data.billing_interval', 'yearly');

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'billing_interval' => 'yearly',
        ]);
    }

    public function test_downgrade_from_business_to_pro_succeeds_when_usage_within_limits(): void
    {
        $user = User::factory()->create();
        $businessPlan = Plan::where('code', 'business')->first();

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $businessPlan->id,
            'provider' => 'null',
            'provider_customer_id' => 'cus_123',
            'provider_subscription_id' => 'sub_123',
            'status' => 'active',
            'billing_interval' => 'monthly',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        Profile::create(['user_id' => $user->id, 'username' => 'prof1', 'display_name' => 'Prof 1']);

        $response = $this->actingAs($user)->postJson('/api/v1/subscription/change-plan', [
            'plan' => 'pro',
            'interval' => 'monthly',
        ]);

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('data.plan_code', 'pro');
    }

    public function test_downgrade_blocked_with_409_when_profiles_exceed_new_limit(): void
    {
        $user = User::factory()->create();
        $businessPlan = Plan::where('code', 'business')->first();

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $businessPlan->id,
            'provider' => 'null',
            'provider_customer_id' => 'cus_123',
            'provider_subscription_id' => 'sub_123',
            'status' => 'active',
            'billing_interval' => 'monthly',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        // Mock usage service to report 4 profiles (exceeding Pro limit of 3)
        $mockUsage = $this->mock(FeatureUsageService::class);
        $mockUsage->shouldReceive('getProfileCount')->andReturn(4);
        $mockUsage->shouldReceive('getCustomDomainCount')->andReturn(0);

        $response = $this->actingAs($user)->postJson('/api/v1/subscription/change-plan', [
            'plan' => 'pro',
            'interval' => 'monthly',
        ]);

        $response->assertStatus(Response::HTTP_CONFLICT)
            ->assertJsonPath('error.code', 'SUBSCRIPTION_DOWNGRADE_BLOCKED')
            ->assertJsonPath('error.details.profiles.current', 4)
            ->assertJsonPath('error.details.profiles.allowed', 3);
    }

    public function test_downgrade_blocked_with_409_when_domains_exceed_new_limit(): void
    {
        $user = User::factory()->create();
        $businessPlan = Plan::where('code', 'business')->first();

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $businessPlan->id,
            'provider' => 'null',
            'provider_customer_id' => 'cus_123',
            'provider_subscription_id' => 'sub_123',
            'status' => 'active',
            'billing_interval' => 'monthly',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $profile = Profile::create(['user_id' => $user->id, 'username' => 'prof1', 'display_name' => 'Prof 1']);

        // Create 2 custom domains (Pro allows only 1)
        ProfileDomain::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'domain' => 'domain1.com',
            'normalized_domain' => 'domain1.com',
            'verification_token' => 'tok_test_1',
        ]);
        ProfileDomain::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'domain' => 'domain2.com',
            'normalized_domain' => 'domain2.com',
            'verification_token' => 'tok_test_2',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/subscription/change-plan', [
            'plan' => 'pro',
            'interval' => 'monthly',
        ]);

        $response->assertStatus(Response::HTTP_CONFLICT)
            ->assertJsonPath('error.code', 'SUBSCRIPTION_DOWNGRADE_BLOCKED')
            ->assertJsonPath('error.details.custom_domains.current', 2)
            ->assertJsonPath('error.details.custom_domains.allowed', 1);

        // Verify zero domains deleted
        $this->assertEquals(2, ProfileDomain::where('profile_id', $profile->id)->count());
    }
}
