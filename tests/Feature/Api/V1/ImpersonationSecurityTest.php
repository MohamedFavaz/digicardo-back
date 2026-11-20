<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Back to admin" / "Back to agent panel" (POST /auth/stop-impersonation) may only restore the
 * account that started the impersonation. Someone who is not impersonating must never be signed
 * in as an administrator by calling it.
 */
class ImpersonationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_cannot_become_admin_through_stop_impersonation(): void
    {
        User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active, 'email' => 'boss@example.test']);
        $customer = User::factory()->create(['role' => UserRole::User, 'status' => UserStatus::Active]);

        $this->withHeader('Referer', 'http://localhost:3000');
        $this->actingAs($customer, 'web')
            ->postJson('/api/v1/auth/stop-impersonation')
            ->assertStatus(403);

        $this->assertAuthenticatedAs($customer, 'web');
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.role', 'user');
    }

    public function test_admin_returns_after_opening_a_card_as_the_customer(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);
        $customer = User::factory()->create(['role' => UserRole::User, 'status' => UserStatus::Active]);

        // Browser requests from the frontend are stateful (session cookie), like the real BFF.
        $this->withHeader('Referer', 'http://localhost:3000');
        $this->actingAs($admin, 'web')->postJson("/api/v1/admin/users/{$customer->id}/impersonate")->assertOk();
        $this->assertAuthenticatedAs($customer, 'web');
        // In the browser the session remembers who opened the card ("impersonator_admin_id");
        // "Back to admin" then restores exactly that admin. (API test requests are stateless, so
        // the marker is given here the way the browser's session carries it.)
        $this->actingAs($customer, 'web')
            ->withSession(['impersonator_admin_id' => $admin->id, 'impersonator_role' => 'admin'])
            ->postJson('/api/v1/auth/stop-impersonation')
            ->assertOk()
            ->assertJsonPath('data.redirect_url', '/admin/my-links');
        $this->assertAuthenticatedAs($admin, 'web');
    }
}
