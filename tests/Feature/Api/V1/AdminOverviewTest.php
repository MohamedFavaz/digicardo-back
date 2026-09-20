<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_retrieve_overview_metrics(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/overview');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'metrics' => [
                        'users' => ['total', 'active', 'suspended'],
                        'profiles' => ['total', 'under_review', 'restricted_or_suspended'],
                        'reports' => ['open', 'investigating', 'total_pending'],
                    ],
                    'recent_actions',
                ],
            ]);
    }

    public function test_non_admin_cannot_access_admin_overview(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/overview');

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'ADMIN_PRIVILEGES_REQUIRED');
    }

    public function test_unauthenticated_user_cannot_access_admin_overview(): void
    {
        $response = $this->getJson('/api/v1/admin/overview');

        $response->assertStatus(401);
    }
}
