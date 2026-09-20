<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AccountSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_and_search_users(): void
    {
        $admin = User::factory()->create(['name' => 'Super Admin', 'email' => 'admin@test.com', 'role' => UserRole::Admin]);
        $targetUser = User::factory()->create(['name' => 'Target User', 'email' => 'target@example.com', 'role' => UserRole::User]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/users?search=target');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.email', 'target@example.com');
    }

    public function test_admin_can_view_user_details(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $targetUser = User::factory()->create(['name' => 'Alice Doe', 'email' => 'alice@example.com']);

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/users/{$targetUser->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $targetUser->id)
            ->assertJsonPath('data.user.email', 'alice@example.com');
    }

    public function test_admin_can_suspend_user_and_revoke_sessions(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $targetUser = User::factory()->create(['status' => UserStatus::Active]);

        // Create an active session for target user
        AccountSession::create([
            'user_id' => $targetUser->id,
            'session_identifier' => hash('sha256', 'session-123'),
            'session_id' => 'session-123',
            'ip_hash' => 'ip-hash',
            'device_name' => 'Desktop',
            'last_activity_at' => now(),
        ]);

        $this->assertEquals(1, $targetUser->activeAccountSessions()->count());

        $response = $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/admin/users/{$targetUser->id}/status", [
            'status' => 'suspended',
            'reason' => 'Violation of platform terms',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'suspended');

        $targetUser->refresh();
        $this->assertEquals(UserStatus::Suspended, $targetUser->status);
        $this->assertEquals(0, $targetUser->activeAccountSessions()->count());
    }

    public function test_suspending_user_also_suspends_created_link(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $targetUser = User::factory()->create(['status' => UserStatus::Active, 'role' => UserRole::User]);

        $profile = \App\Models\Profile::create([
            'user_id' => $targetUser->id,
            'username' => 'creatorlink',
            'display_name' => 'Creator Link',
            'template_id' => 'vcard_business',
            'theme_tokens' => [
                'color_background' => '#000000',
                'color_surface' => '#111111',
                'color_text_primary' => '#ffffff',
                'color_text_secondary' => '#aaaaaa',
                'color_accent' => '#6366f1',
                'font_family' => 'inter',
                'button_radius' => 'medium',
                'button_style' => 'solid',
                'animation' => 'fade',
            ],
            'is_public' => true,
            'moderation_status' => 'active',
        ]);

        // Prior to suspension, public link is accessible
        $this->getJson('/api/v1/p/creatorlink')->assertStatus(200);

        // Suspend the user
        $response = $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/admin/users/{$targetUser->id}/status", [
            'status' => 'suspended',
            'reason' => 'Violation of platform terms',
        ]);
        $response->assertStatus(200);

        $profile->refresh();
        $this->assertEquals('suspended', $profile->moderation_status);

        // When user is suspended, the user created link is also suspended (returns 404)
        $this->getJson('/api/v1/p/creatorlink')->assertStatus(404);

        // Reactivate the user
        $reactivateResponse = $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/admin/users/{$targetUser->id}/status", [
            'status' => 'active',
        ]);
        $reactivateResponse->assertStatus(200);

        $profile->refresh();
        $this->assertEquals('active', $profile->moderation_status);

        // Once reactivated, the user created link is accessible again
        $this->getJson('/api/v1/p/creatorlink')->assertStatus(200);
    }

    public function test_admin_cannot_suspend_themselves(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/admin/users/{$admin->id}/status", [
            'status' => 'suspended',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['status']]]);
    }

    public function test_cannot_suspend_last_remaining_active_admin(): void
    {
        $admin1 = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);
        $admin2 = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);

        // First suspension succeeds because admin1 is still active
        $response1 = $this->actingAs($admin1, 'sanctum')->patchJson("/api/v1/admin/users/{$admin2->id}/status", [
            'status' => 'suspended',
        ]);
        $response1->assertStatus(200);

        // Second suspension of admin1 (now the only active admin) should fail
        $response2 = $this->actingAs($admin1, 'sanctum')->patchJson("/api/v1/admin/users/{$admin1->id}/status", [
            'status' => 'suspended',
        ]);
        $response2->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['status']]]);
    }

    public function test_admin_cannot_demote_themselves(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/admin/users/{$admin->id}/role", [
            'role' => 'user',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['role']]]);
    }

    public function test_admin_can_update_other_user_role(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $targetUser = User::factory()->create(['role' => UserRole::User]);

        $response = $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/admin/users/{$targetUser->id}/role", [
            'role' => 'moderator',
            'reason' => 'Promoted to trust & safety moderator',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.role', 'moderator');

        $targetUser->refresh();
        $this->assertEquals(UserRole::Moderator, $targetUser->role);
    }

    public function test_admin_can_permanently_delete_user_and_profile(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $targetUser = User::factory()->create(['name' => 'To Delete', 'email' => 'delete@example.com', 'role' => UserRole::User]);

        $profile = \App\Models\Profile::create([
            'user_id' => $targetUser->id,
            'username' => 'todelete',
            'display_name' => 'To Delete',
            'template_id' => 'vcard_business',
            'theme_tokens' => [
                'color_background' => '#000000',
                'color_surface' => '#111111',
                'color_text_primary' => '#ffffff',
                'color_text_secondary' => '#aaaaaa',
                'color_accent' => '#6366f1',
                'font_family' => 'inter',
                'button_radius' => 'medium',
                'button_style' => 'solid',
                'animation' => 'fade',
            ],
            'is_public' => true,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/admin/users/{$targetUser->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user_id', $targetUser->id);

        $this->assertDatabaseMissing('users', ['id' => $targetUser->id]);
        $this->assertDatabaseMissing('profiles', ['id' => $profile->id]);
    }

    public function test_admin_cannot_delete_themselves(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/admin/users/{$admin->id}");

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['user_id']]]);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }
}
