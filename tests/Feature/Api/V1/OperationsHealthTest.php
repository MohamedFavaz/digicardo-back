<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_health_endpoint_returns_safe_status(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'healthy')
            ->assertJsonPath('data.checks.application', 'healthy')
            ->assertJsonPath('data.checks.database', 'healthy')
            ->assertJsonPath('data.checks.cache', 'healthy')
            ->assertJsonPath('data.checks.queue', 'healthy')
            ->assertJsonPath('data.checks.storage', 'healthy')
            ->assertJsonMissingPath('data.checks.database.password')
            ->assertJsonMissingPath('data.checks.database.connection')
            ->assertJsonMissingPath('data.checks.cache.host');
    }

    public function test_admin_can_access_detailed_operations_health(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/operations/health');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'healthy')
            ->assertJsonStructure([
                'data' => [
                    'status',
                    'timestamp',
                    'php_version',
                    'laravel_version',
                    'environment',
                    'checks' => [
                        'application',
                        'database',
                        'cache',
                        'queue',
                        'storage',
                    ],
                ],
            ]);
    }

    public function test_non_admin_cannot_access_detailed_operations_health(): void
    {
        $regularUser = User::factory()->create([
            'role' => UserRole::User,
        ]);

        $response = $this->actingAs($regularUser, 'sanctum')->getJson('/api/v1/admin/operations/health');

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'ADMIN_PRIVILEGES_REQUIRED');
    }

    public function test_unauthenticated_user_cannot_access_detailed_operations_health(): void
    {
        $response = $this->getJson('/api/v1/admin/operations/health');

        $response->assertStatus(401);
    }
}
