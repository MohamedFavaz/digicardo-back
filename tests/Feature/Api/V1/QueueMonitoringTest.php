<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueueMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_retrieve_queue_health_metrics(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/operations/queue');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'healthy')
            ->assertJsonStructure([
                'data' => [
                    'status',
                    'pending_jobs',
                    'failed_jobs',
                    'failed_last_hour',
                    'oldest_pending_seconds',
                    'queues',
                ],
            ]);
    }

    public function test_non_admin_cannot_access_queue_health_metrics(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/operations/queue');

        $response->assertStatus(403);
    }

    public function test_admin_can_list_sanitized_failed_jobs(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        DB::table('failed_jobs')->insert([
            'id' => 1,
            'uuid' => 'job-uuid-12345',
            'connection' => 'database',
            'queue' => 'emails',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\SendEmailJob',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => ['password' => 'secret123'],
            ]),
            'exception' => "Exception: Connection refused to api.stripe.com with Bearer sk_test_secret123\npassword: secret123",
            'failed_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/operations/failed-jobs');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.name', 'App\\Jobs\\SendEmailJob')
            ->assertJsonPath('data.items.0.queue', 'emails');

        // Check that secrets are redacted from exception preview
        $preview = $response->json('data.items.0.exception_preview');
        $this->assertStringNotContainsString('sk_test_secret123', $preview);
    }

    public function test_admin_can_retry_all_failed_jobs(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/operations/failed-jobs/retry-all');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['retried_count']]);
    }

    public function test_admin_can_flush_all_failed_jobs(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/operations/failed-jobs/flush');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.flushed', true);
    }
}
