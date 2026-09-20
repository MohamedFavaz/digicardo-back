<?php

namespace Tests\Feature\Api\V1;

use App\Models\AccountSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_change_password_with_correct_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldPassword123!'),
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/account/change-password', [
            'current_password' => 'OldPassword123!',
            'password' => 'NewSecurePassword456!',
            'password_confirmation' => 'NewSecurePassword456!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('NewSecurePassword456!', $user->fresh()->password));

        // Assert security event was logged
        $this->assertDatabaseHas('account_security_events', [
            'user_id' => $user->id,
            'event_type' => 'password_changed',
        ]);
    }

    public function test_incorrect_current_password_is_rejected(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CorrectPassword123!'),
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/account/change-password', [
            'current_password' => 'WrongPassword123!',
            'password' => 'NewSecurePassword456!',
            'password_confirmation' => 'NewSecurePassword456!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertTrue(Hash::check('CorrectPassword123!', $user->fresh()->password));
    }

    public function test_same_new_password_is_rejected(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('SamePassword123!'),
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/account/change-password', [
            'current_password' => 'SamePassword123!',
            'password' => 'SamePassword123!',
            'password_confirmation' => 'SamePassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CurrentPassword123!'),
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/account/change-password', [
            'current_password' => 'CurrentPassword123!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'DifferentPassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_password_change_revokes_other_sessions(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldPass123!'),
        ]);

        // Create other active session
        $otherSession = AccountSession::create([
            'user_id' => $user->id,
            'session_identifier' => hash('sha256', 'other_session_token'),
            'session_id' => 'other_session_token',
            'device_name' => 'Other Phone',
            'browser' => 'Safari',
            'platform' => 'iOS',
            'last_activity_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/account/change-password', [
            'current_password' => 'OldPass123!',
            'password' => 'NewPass123!',
            'password_confirmation' => 'NewPass123!',
        ]);

        $response->assertStatus(200);

        $this->assertNotNull($otherSession->fresh()->revoked_at);
        $this->assertEquals('password_changed', $otherSession->fresh()->revoked_reason);
    }
}
