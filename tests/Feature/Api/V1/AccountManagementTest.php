<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_retrieve_account_details(): void
    {
        $user = User::factory()->create([
            'name' => 'Alice Smith',
            'email' => 'alice@example.com',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/account');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Alice Smith')
            ->assertJsonPath('data.email', 'alice@example.com')
            ->assertJsonPath('data.is_email_verified', true);
    }

    public function test_unauthenticated_user_cannot_retrieve_account_details(): void
    {
        $response = $this->getJson('/api/v1/account');

        $response->assertStatus(401);
    }

    public function test_user_can_update_name(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($user, 'sanctum')->patchJson('/api/v1/account', [
            'name' => 'New Name',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'New Name');

        $this->assertEquals('New Name', $user->fresh()->name);
    }

    public function test_email_normalization_works(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);

        $response = $this->actingAs($user, 'sanctum')->patchJson('/api/v1/account', [
            'email' => '  NEW.EMAIL@EXAMPLE.COM  ',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'new.email@example.com');

        $this->assertEquals('new.email@example.com', $user->fresh()->email);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);
        $user = User::factory()->create(['email' => 'user@example.com']);

        $response = $this->actingAs($user, 'sanctum')->patchJson('/api/v1/account', [
            'email' => 'existing@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_email_change_clears_verification_and_triggers_notification(): void
    {
        $user = User::factory()->create([
            'email' => 'verified@example.com',
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')->patchJson('/api/v1/account', [
            'email' => 'unverified@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_email_verified', false);

        $fresh = $user->fresh();
        $this->assertNull($fresh->email_verified_at);
        $this->assertEquals('unverified@example.com', $fresh->email);

        // Assert verification notification created
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'email_verification',
        ]);

        // Assert security event created
        $this->assertDatabaseHas('account_security_events', [
            'user_id' => $user->id,
            'event_type' => 'email_changed',
        ]);
    }
}
