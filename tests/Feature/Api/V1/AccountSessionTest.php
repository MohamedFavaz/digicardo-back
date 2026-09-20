<?php

namespace Tests\Feature\Api\V1;

use App\Models\AccountSession;
use App\Models\User;
use App\Services\AccountSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_only_own_active_sessions(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $session1 = AccountSession::create([
            'user_id' => $user->id,
            'session_identifier' => hash('sha256', 'session_1'),
            'device_name' => 'MacBook Pro',
            'browser' => 'Chrome',
            'platform' => 'macOS',
            'last_activity_at' => now(),
        ]);

        $session2 = AccountSession::create([
            'user_id' => $user->id,
            'session_identifier' => hash('sha256', 'session_2'),
            'device_name' => 'iPhone',
            'browser' => 'Safari',
            'platform' => 'iOS',
            'last_activity_at' => now(),
        ]);

        AccountSession::create([
            'user_id' => $otherUser->id,
            'session_identifier' => hash('sha256', 'other_session'),
            'device_name' => 'Windows PC',
            'browser' => 'Edge',
            'platform' => 'Windows',
            'last_activity_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/account/sessions');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.items');
    }

    public function test_user_can_revoke_an_individual_session(): void
    {
        $user = User::factory()->create();

        $session = AccountSession::create([
            'user_id' => $user->id,
            'session_identifier' => hash('sha256', 'remote_device_token'),
            'session_id' => 'remote_device_token',
            'device_name' => 'iPad',
            'browser' => 'Safari',
            'platform' => 'iPadOS',
            'last_activity_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/account/sessions/{$session->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.revoked', true);

        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertEquals('user_revoked', $session->fresh()->revoked_reason);

        // Assert security event was logged
        $this->assertDatabaseHas('account_security_events', [
            'user_id' => $user->id,
            'event_type' => 'session_revoked',
        ]);
    }

    public function test_user_cannot_revoke_another_users_session(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $otherSession = AccountSession::create([
            'user_id' => $otherUser->id,
            'session_identifier' => hash('sha256', 'foreign_token'),
            'device_name' => 'Foreign PC',
            'browser' => 'Firefox',
            'platform' => 'Linux',
            'last_activity_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/account/sessions/{$otherSession->id}");

        $response->assertStatus(404);
        $this->assertNull($otherSession->fresh()->revoked_at);
    }

    public function test_user_can_revoke_all_other_sessions(): void
    {
        $user = User::factory()->create();

        // In test mode, simulate session with recent auth
        $sessionService = app(AccountSessionService::class);

        $s1 = AccountSession::create([
            'user_id' => $user->id,
            'session_identifier' => hash('sha256', 's1'),
            'session_id' => 's1',
            'device_name' => 'Phone 1',
            'last_activity_at' => now(),
        ]);

        $s2 = AccountSession::create([
            'user_id' => $user->id,
            'session_identifier' => hash('sha256', 's2'),
            'session_id' => 's2',
            'device_name' => 'Phone 2',
            'last_activity_at' => now(),
        ]);

        $currentSession = AccountSession::create([
            'user_id' => $user->id,
            'session_identifier' => hash('sha256', 'current_session_token'),
            'session_id' => 'current_session_token',
            'device_name' => 'Current Laptop',
            'last_activity_at' => now(),
        ]);

        $revokedCount = $sessionService->revokeOtherSessions($user, 'current_session_token');

        $this->assertEquals(2, $revokedCount);
        $this->assertNotNull($s1->fresh()->revoked_at);
        $this->assertNotNull($s2->fresh()->revoked_at);
        $this->assertNull($currentSession->fresh()->revoked_at);

        // Assert security event was created
        $this->assertDatabaseHas('account_security_events', [
            'user_id' => $user->id,
            'event_type' => 'all_other_sessions_revoked',
        ]);
    }
}
