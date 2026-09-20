<?php

namespace Tests\Feature\Api\V1;

use App\Enums\AccountSecurityEventType;
use App\Models\AccountSecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountSecurityEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_retrieve_own_security_events_list(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        AccountSecurityEvent::create([
            'user_id' => $user->id,
            'event_type' => AccountSecurityEventType::LoginSuccess,
            'metadata' => ['device' => 'Desktop'],
            'created_at' => now()->subHours(2),
        ]);

        AccountSecurityEvent::create([
            'user_id' => $user->id,
            'event_type' => AccountSecurityEventType::PasswordChanged,
            'metadata' => ['action' => 'password_update'],
            'created_at' => now()->subHour(),
        ]);

        AccountSecurityEvent::create([
            'user_id' => $otherUser->id,
            'event_type' => AccountSecurityEventType::LoginSuccess,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/account/security-events');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.items');
    }

    public function test_security_events_sanitize_forbidden_metadata(): void
    {
        $user = User::factory()->create();

        AccountSecurityEvent::create([
            'user_id' => $user->id,
            'event_type' => AccountSecurityEventType::LoginSuccess,
            'metadata' => [
                'device' => 'Desktop',
                'password' => 'supersecret',
                'token' => 'raw_token_xyz',
            ],
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/account/security-events');

        $response->assertStatus(200)
            ->assertJsonPath('data.items.0.metadata.device', 'Desktop')
            ->assertJsonMissingPath('data.items.0.metadata.password')
            ->assertJsonMissingPath('data.items.0.metadata.token');
    }
}
