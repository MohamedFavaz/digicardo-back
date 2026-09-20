<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Services\AuthEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_returns_generic_success_response(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'user@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'user@example.com',
        ]);
    }

    public function test_forgot_password_returns_generic_response_for_nonexistent_email(): void
    {
        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'unknown@example.com',
        ]);

        // Prevents email enumeration
        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'unknown@example.com',
        ]);
    }

    public function test_user_can_reset_password_with_valid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'alice@example.com',
            'password' => Hash::make('OldPassword123!'),
        ]);

        $rawToken = Str::random(64);
        DB::table('password_reset_tokens')->insert([
            'email' => 'alice@example.com',
            'token' => hash('sha256', $rawToken),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $rawToken,
            'email' => 'alice@example.com',
            'password' => 'NewPassword456!',
            'password_confirmation' => 'NewPassword456!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.reset', true);

        // Assert token was invalidated
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'alice@example.com',
        ]);

        // Assert user can login with new password
        $this->assertTrue(Hash::check('NewPassword456!', $user->fresh()->password));
    }

    public function test_password_reset_fails_with_expired_token(): void
    {
        $user = User::factory()->create(['email' => 'bob@example.com']);
        $rawToken = Str::random(64);

        DB::table('password_reset_tokens')->insert([
            'email' => 'bob@example.com',
            'token' => hash('sha256', $rawToken),
            'created_at' => now()->subHours(2), // Expired
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $rawToken,
            'email' => 'bob@example.com',
            'password' => 'NewPassword456!',
            'password_confirmation' => 'NewPassword456!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'INVALID_OR_EXPIRED_RESET_TOKEN');
    }

    public function test_reset_token_cannot_be_reused(): void
    {
        $user = User::factory()->create(['email' => 'claire@example.com']);
        $rawToken = Str::random(64);

        DB::table('password_reset_tokens')->insert([
            'email' => 'claire@example.com',
            'token' => hash('sha256', $rawToken),
            'created_at' => now(),
        ]);

        // First attempt succeeds
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $rawToken,
            'email' => 'claire@example.com',
            'password' => 'NewPass123!',
            'password_confirmation' => 'NewPass123!',
        ])->assertStatus(200);

        // Second attempt with same token fails
        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $rawToken,
            'email' => 'claire@example.com',
            'password' => 'AnotherPass123!',
            'password_confirmation' => 'AnotherPass123!',
        ]);

        $response->assertStatus(422);
    }
}
