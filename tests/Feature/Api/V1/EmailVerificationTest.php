<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Services\Email\NullEmailProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        NullEmailProvider::clearSentMessages();
    }

    public function test_verification_email_is_queued_after_registration(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'john@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);

        // Assert notification and email delivery were created
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'email_verification',
        ]);
    }

    public function test_user_can_verify_email_with_valid_signed_url(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $expires = now()->addHour()->timestamp;
        $hash = sha1($user->email);
        $signature = hash_hmac('sha256', "verify:{$user->id}:{$hash}:{$expires}", config('app.key'));

        $response = $this->getJson("/api/v1/auth/email/verify/{$user->id}/{$hash}?expires={$expires}&signature={$signature}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.verified', true);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verification_fails_with_expired_link(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $expires = now()->subHour()->timestamp; // Expired
        $hash = sha1($user->email);
        $signature = hash_hmac('sha256', "verify:{$user->id}:{$hash}:{$expires}", config('app.key'));

        $response = $this->getJson("/api/v1/auth/email/verify/{$user->id}/{$hash}?expires={$expires}&signature={$signature}");

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'INVALID_OR_EXPIRED_VERIFICATION_LINK');
    }

    public function test_verification_fails_with_invalid_signature(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $expires = now()->addHour()->timestamp;
        $hash = sha1($user->email);
        $signature = 'invalid_tampered_signature';

        $response = $this->getJson("/api/v1/auth/email/verify/{$user->id}/{$hash}?expires={$expires}&signature={$signature}");

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_user_can_resend_verification_notification(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/email/verification-notification');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sent', true);
    }
}
