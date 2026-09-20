<?php

namespace Tests\Feature\Api\V1;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
    }

    public function test_free_user_cannot_create_a_second_profile(): void
    {
        $user = User::factory()->create();
        Profile::create([
            'user_id' => $user->id,
            'username' => 'freeprofile1',
            'display_name' => 'Free User',
            'template_id' => 'vcard',
            'is_public' => true,
            'version' => 1,
        ]);

        // 1. Direct service check throws FeatureLimitExceededException
        $entitlementService = app(\App\Services\FeatureEntitlementService::class);
        $this->assertEquals(1, $entitlementService->usage($user, \App\Enums\FeatureKey::ProfileCount));
        $this->assertEquals(0, $entitlementService->remaining($user, \App\Enums\FeatureKey::ProfileCount));

        // 2. Attempting second profile returns 409 conflict
        $response = $this->actingAs($user)->postJson('/api/v1/profile', [
            'username' => 'freeprofile2',
            'display_name' => 'Free Profile 2',
        ]);
        $response->assertStatus(409);
    }

    public function test_free_user_cannot_register_a_custom_domain(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'freedomainuser',
            'display_name' => 'Free Domain User',
            'template_id' => 'vcard',
            'is_public' => true,
            'version' => 1,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/profile/domains', [
            'domain' => 'mydomain.com',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'FEATURE_LIMIT_REACHED')
            ->assertJsonPath('error.details.feature', 'custom_domain_count')
            ->assertJsonPath('error.details.limit', 0);
    }

    public function test_pro_user_can_register_custom_domain_within_limit(): void
    {
        $user = User::factory()->create();
        $proPlan = Plan::where('code', 'pro')->firstOrFail();

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $proPlan->id,
            'status' => SubscriptionStatus::Active,
            'billing_interval' => 'monthly',
        ]);

        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'prodomainuser',
            'display_name' => 'Pro Domain User',
            'template_id' => 'vcard',
            'is_public' => true,
            'version' => 1,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/profile/domains', [
            'domain' => 'probrand.io',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.normalized_domain', 'probrand.io');
    }

    public function test_free_user_cannot_create_gallery_video_music_or_booking_blocks(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'blockgateuser',
            'display_name' => 'Block Gate User',
            'template_id' => 'vcard',
            'is_public' => true,
            'version' => 1,
        ]);

        $media = \App\Models\ProfileMedia::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'type' => 'image',
            'disk' => 'public',
            'path' => 'profiles/test.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
        ]);

        $testConfigs = [
            'gallery' => ['layout' => 'grid', 'media_ids' => [$media->id]],
            'video' => ['provider' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'music' => ['provider' => 'spotify', 'url' => 'https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT'],
            'booking' => ['provider' => 'calendly', 'url' => 'https://calendly.com/johndoe/meeting'],
        ];

        foreach ($testConfigs as $type => $config) {
            $response = $this->actingAs($user)->postJson('/api/v1/profile/blocks', [
                'type' => $type,
                'config' => $config,
            ]);

            $response->assertStatus(403)
                ->assertJsonPath('success', false)
                ->assertJsonPath('error.code', 'FEATURE_NOT_AVAILABLE')
                ->assertJsonPath('error.details.feature', "{$type}_blocks");
        }
    }

    public function test_pro_user_can_create_gated_blocks(): void
    {
        $user = User::factory()->create();
        $proPlan = Plan::where('code', 'pro')->firstOrFail();

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $proPlan->id,
            'status' => SubscriptionStatus::Active,
            'billing_interval' => 'monthly',
        ]);

        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'problocksuser',
            'display_name' => 'Pro Blocks User',
            'template_id' => 'vcard',
            'is_public' => true,
            'version' => 1,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/profile/blocks', [
            'type' => 'video',
            'config' => [
                'provider' => 'youtube',
                'title' => 'My YouTube Video',
                'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'video');
    }

    public function test_free_user_cannot_select_advanced_template(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'templategateuser',
            'display_name' => 'Template Gate User',
            'template_id' => 'vcard',
            'is_public' => true,
            'version' => 1,
        ]);

        $response = $this->actingAs($user)->putJson('/api/v1/profile/appearance', [
            'template_id' => 'gradient',
            'version' => 1,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'FEATURE_NOT_AVAILABLE')
            ->assertJsonPath('error.details.feature', 'advanced_templates');
    }

    public function test_pro_user_can_select_advanced_template(): void
    {
        $user = User::factory()->create();
        $proPlan = Plan::where('code', 'pro')->firstOrFail();

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $proPlan->id,
            'status' => SubscriptionStatus::Active,
            'billing_interval' => 'monthly',
        ]);

        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'protemplateuser',
            'display_name' => 'Pro Template User',
            'template_id' => 'vcard',
            'is_public' => true,
            'version' => 1,
        ]);

        $response = $this->actingAs($user)->putJson('/api/v1/profile/appearance', [
            'template_id' => 'gradient',
            'version' => 1,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.template_id', 'gradient');
    }

    public function test_branding_is_mandatory_for_free_and_removable_for_pro(): void
    {
        // Free user
        $freeUser = User::factory()->create();
        $freeProfile = Profile::create([
            'user_id' => $freeUser->id,
            'username' => 'freebranduser',
            'display_name' => 'Free Brand User',
            'template_id' => 'vcard',
            'theme_tokens' => ['hide_branding' => true],
            'is_public' => true,
            'version' => 1,
        ]);

        $freeRes = $this->getJson("/api/v1/p/{$freeProfile->username}");
        $freeRes->assertStatus(200)
            ->assertJsonPath('data.show_branding', true); // Free user cannot hide branding

        // Pro user
        $proUser = User::factory()->create();
        $proPlan = Plan::where('code', 'pro')->firstOrFail();
        Subscription::create([
            'user_id' => $proUser->id,
            'plan_id' => $proPlan->id,
            'status' => SubscriptionStatus::Active,
        ]);

        $proProfile = Profile::create([
            'user_id' => $proUser->id,
            'username' => 'probranduser',
            'display_name' => 'Pro Brand User',
            'template_id' => 'vcard',
            'theme_tokens' => ['hide_branding' => true],
            'is_public' => true,
            'version' => 1,
        ]);

        $proRes = $this->getJson("/api/v1/p/{$proProfile->username}");
        $proRes->assertStatus(200)
            ->assertJsonPath('data.show_branding', false); // Pro user hides branding
    }
}
