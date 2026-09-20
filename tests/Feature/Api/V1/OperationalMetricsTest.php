<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_retrieve_operational_metrics(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        // Create sample records
        $user = User::factory()->create();
        Profile::create([
            'user_id' => $user->id,
            'username' => 'metricsuser',
            'display_name' => 'Metrics User',
            'is_public' => true,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/operations/metrics');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'users' => ['total', 'verified', 'unverified', 'new_last_24h', 'new_last_7d'],
                    'profiles' => ['total', 'published'],
                    'domains' => ['total', 'active', 'pending'],
                    'subscriptions' => ['active', 'pro', 'business', 'in_grace_period'],
                    'queues' => ['status', 'pending_jobs', 'failed_jobs'],
                    'media' => ['total_items'],
                    'security' => ['events_last_24h'],
                    'computed_at',
                ],
            ]);

        $this->assertGreaterThanOrEqual(2, $response->json('data.users.total'));
        $this->assertGreaterThanOrEqual(1, $response->json('data.profiles.published'));
    }

    public function test_admin_can_refresh_operational_metrics(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/operations/metrics/refresh');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_non_admin_cannot_access_operational_metrics(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/operations/metrics');

        $response->assertStatus(403);
    }
}
