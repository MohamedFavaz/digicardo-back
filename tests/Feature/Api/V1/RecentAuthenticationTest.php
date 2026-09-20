<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RecentAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_confirm_recent_auth_with_correct_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('MyPassword123!'),
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/account/confirm-password', [
            'password' => 'MyPassword123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.confirmed', true);

        // Assert security event was recorded
        $this->assertDatabaseHas('account_security_events', [
            'user_id' => $user->id,
            'event_type' => 'recent_auth_confirmed',
        ]);
    }

    public function test_recent_auth_fails_with_wrong_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('MyPassword123!'),
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/account/confirm-password', [
            'password' => 'WrongPassword999!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        // Assert failed attempt was recorded
        $this->assertDatabaseHas('account_security_events', [
            'user_id' => $user->id,
            'event_type' => 'login_failed',
        ]);
    }
}
