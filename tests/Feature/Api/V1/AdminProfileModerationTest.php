<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ProfileModerationStatus;
use App\Enums\UserRole;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProfileModerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_and_filter_profiles(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create(['email' => 'moduser@test.com']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'moduser',
            'display_name' => 'Mod User',
            'moderation_status' => ProfileModerationStatus::UnderReview,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/profiles?moderation_status=under_review');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.username', 'moduser');
    }

    public function test_admin_can_moderate_profile_status(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'spammer',
            'display_name' => 'Spam Profile',
            'moderation_status' => ProfileModerationStatus::Active,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/admin/profiles/{$profile->id}/moderation", [
            'moderation_status' => 'restricted',
            'reason' => 'Spam links detected on profile',
            'notes' => 'Internal flag: multiple user reports',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.moderation_status', 'restricted');

        $profile->refresh();
        $this->assertEquals(ProfileModerationStatus::Restricted, $profile->moderation_status);
        $this->assertEquals('Spam links detected on profile', $profile->moderation_reason);
    }

    public function test_restrict_or_suspend_requires_reason(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'baduser',
            'display_name' => 'Bad User',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/admin/profiles/{$profile->id}/moderation", [
            'moderation_status' => 'suspended',
            // Reason missing
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['reason']]]);
    }

    public function test_public_rendering_blocks_restricted_or_suspended_profiles(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'suspendeduser',
            'display_name' => 'Suspended User',
            'is_public' => true,
            'moderation_status' => ProfileModerationStatus::Suspended,
            'moderation_reason' => 'Terms violation',
        ]);

        // Public visitor request
        $response = $this->getJson('/api/v1/p/suspendeduser');

        $response->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_public_rendering_allows_active_and_under_review_profiles(): void
    {
        $user1 = User::factory()->create();
        Profile::create([
            'user_id' => $user1->id,
            'username' => 'activeuser',
            'display_name' => 'Active User',
            'is_public' => true,
            'moderation_status' => ProfileModerationStatus::Active,
        ]);

        $user2 = User::factory()->create();
        Profile::create([
            'user_id' => $user2->id,
            'username' => 'reviewuser',
            'display_name' => 'Review User',
            'is_public' => true,
            'moderation_status' => ProfileModerationStatus::UnderReview,
        ]);

        $response1 = $this->getJson('/api/v1/p/activeuser');
        $response1->assertStatus(200);

        $response2 = $this->getJson('/api/v1/p/reviewuser');
        $response2->assertStatus(200);
    }
}
