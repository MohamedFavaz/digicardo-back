<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_actions_create_audit_logs_with_request_id(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $targetUser = User::factory()->create();

        $customRequestId = 'req_audit_test_trace_12345';

        $response = $this->withHeaders([
            'X-Request-ID' => $customRequestId,
        ])->actingAs($admin, 'sanctum')->patchJson("/api/v1/admin/users/{$targetUser->id}/role", [
            'role' => 'moderator',
            'reason' => 'Appointed moderator',
        ]);

        $response->assertStatus(200);

        // Fetch audit logs
        $logsResponse = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/audit-logs');

        $logsResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.action', 'user.role_updated')
            ->assertJsonPath('data.items.0.actor_id', $admin->id)
            ->assertJsonPath('data.items.0.target_id', $targetUser->id)
            ->assertJsonPath('data.items.0.request_id', $customRequestId);
    }

    public function test_admin_can_retrieve_moderation_actions_history(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $targetUser = User::factory()->create();

        $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/admin/users/{$targetUser->id}/status", [
            'status' => 'suspended',
            'reason' => 'Suspicious automated activity',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/moderation-actions');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.action_type', 'user_suspended');
    }
}
