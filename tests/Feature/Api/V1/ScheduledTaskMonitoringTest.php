<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\ScheduledTaskMonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledTaskMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_retrieve_scheduled_tasks_list(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        // Record a mock task run
        $service = app(ScheduledTaskMonitoringService::class);
        $service->recordTaskExecution('Digicardo:reconcile-subscriptions', 0.25, true);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/operations/scheduler');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'tasks' => [
                        '*' => [
                            'name',
                            'command',
                            'expression',
                            'frequency',
                            'last_run_at',
                            'last_duration_seconds',
                            'status',
                            'next_due_at',
                        ],
                    ],
                ],
            ]);

        // Verify the recorded task has healthy status
        $tasks = collect($response->json('data.tasks'));
        $reconTask = $tasks->firstWhere('command', 'Digicardo:reconcile-subscriptions');
        $this->assertNotNull($reconTask);
        $this->assertEquals('healthy', $reconTask['status']);
        $this->assertEquals(0.25, $reconTask['last_duration_seconds']);
    }

    public function test_non_admin_cannot_access_scheduler_monitoring(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/operations/scheduler');

        $response->assertStatus(403);
    }
}
