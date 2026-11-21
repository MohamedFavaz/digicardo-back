<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Middleware\IgnoreRetiredSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A request still running on the admin's session when "Open as customer" switches accounts must
 * not put the browser back on the admin account (it used to open the admin's own dashboard).
 */
class RetiredSessionTest extends TestCase
{
    use RefreshDatabase;

    private function frontend(): string
    {
        return 'http://'.trim(config('sanctum.stateful')[0]).'/admin/dashboard';
    }

    public function test_a_late_request_on_a_retired_session_is_flagged_and_not_restored(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);

        // The browser's session id before the switch.
        $oldId = \Illuminate\Support\Str::random(40);

        // The switch retires it.
        IgnoreRetiredSession::retire($oldId);

        // A request that was already in flight on that old session finishes now.
        $response = $this->actingAs($admin, 'web')
            ->withCredentials()
            ->withCookie(config('session.cookie'), $oldId)
            ->withHeader('Referer', $this->frontend())
            ->getJson('/api/v1/auth/me');

        $response->assertHeader(IgnoreRetiredSession::HEADER, '1');
    }

    public function test_normal_requests_are_not_flagged(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);

        $this->actingAs($admin, 'web')
            ->withHeader('Referer', $this->frontend())
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertHeaderMissing(IgnoreRetiredSession::HEADER);
    }

    public function test_opening_a_card_retires_the_admin_session_but_not_its_own_response(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);
        $customer = User::factory()->create(['role' => UserRole::User, 'status' => UserStatus::Active]);

        $this->actingAs($admin, 'web')
            ->withHeader('Referer', $this->frontend())
            ->postJson("/api/v1/admin/users/{$customer->id}/impersonate")
            ->assertOk()
            ->assertHeaderMissing(IgnoreRetiredSession::HEADER);
    }
}
