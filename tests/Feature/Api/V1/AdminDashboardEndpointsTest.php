<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The endpoints behind the admin Overview, Revenue and Analytics pages.
 * Regressions found in manual QA (2026-11-19):
 * - /users/expiring and /users/expired were registered after /users/{id}, so "expiring" was
 *   treated as a user id and returned 404;
 * - the monthly charts used MySQL-only date functions and failed on SQLite.
 */
class AdminDashboardEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->count(2)->create();
    }

    public function test_expiring_and_expired_lists_are_reachable(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/users/expiring')
            ->assertOk()->assertJsonPath('success', true);
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/users/expired')
            ->assertOk()->assertJsonPath('success', true);
    }

    public function test_user_detail_still_works_by_id(): void
    {
        $user = User::factory()->create();
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/admin/users/{$user->id}")->assertOk();
    }

    public function test_monthly_signups_chart_counts_new_users(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/analytics/signups/monthly')->assertOk();

        $signups = $response->json('data.signups');
        $this->assertNotEmpty($signups);
        $this->assertSame(now()->format('Y-m'), end($signups)['month']);
        $this->assertSame(3, array_sum(array_column($signups, 'count')));
    }

    public function test_monthly_sales_chart_has_one_bar_per_month(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/sales/chart/monthly?months=6')->assertOk();
        $this->assertTrue($response->json('success'));
    }

    public function test_non_admins_are_refused(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/users/expiring')->assertForbidden();
    }
}
