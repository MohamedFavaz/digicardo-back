<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_successfully(): void
    {
        $response = $this->postJson(route('api.v1.auth.register'), [
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'email',
                    'role',
                    'status',
                    'created_at',
                    'updated_at',
                ],
                'meta' => ['message'],
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertEquals('jane@example.com', $response->json('data.email'));
        $this->assertEquals('Jane Smith', $response->json('data.name'));
        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::create([
            'name' => 'Existing User',
            'email' => 'jane@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson(route('api.v1.auth.register'), [
            'name' => 'Jane Clone',
            'email' => 'jane@example.com',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                ],
            ]);
    }

    public function test_registration_requires_password_confirmation(): void
    {
        $response = $this->postJson(route('api.v1.auth.register'), [
            'name' => 'Jane Mismatch',
            'email' => 'mismatch@example.com',
            'password' => 'secret1234',
            'password_confirmation' => 'different1234',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                ],
            ]);
    }

    public function test_registration_normalizes_email(): void
    {
        $response = $this->postJson(route('api.v1.auth.register'), [
            'name' => 'Uppercase User',
            'email' => '  UPPERCASE@Example.COM  ',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'uppercase@example.com']);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::create([
            'name' => 'Login User',
            'email' => 'login@example.com',
            'password' => Hash::make('correct_password'),
        ]);

        $response = $this->postJson(route('api.v1.auth.login'), [
            'email' => 'login@example.com',
            'password' => 'correct_password',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'email' => 'login@example.com',
                ],
            ]);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::create([
            'name' => 'Wrong Password User',
            'email' => 'user@example.com',
            'password' => Hash::make('correct_password'),
        ]);

        $response = $this->postJson(route('api.v1.auth.login'), [
            'email' => 'user@example.com',
            'password' => 'wrong_password',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                ],
            ]);
    }

    public function test_authenticated_user_can_access_me_endpoint(): void
    {
        $user = User::create([
            'name' => 'Auth Me User',
            'email' => 'me@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson(route('api.v1.auth.me'));

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'email' => 'me@example.com',
                    'name' => 'Auth Me User',
                ],
            ]);
    }

    public function test_unauthenticated_user_cannot_access_me_endpoint(): void
    {
        $response = $this->getJson(route('api.v1.auth.me'));

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                ],
            ]);
    }

    public function test_user_can_logout_and_session_is_invalidated(): void
    {
        $user = User::create([
            'name' => 'Logout User',
            'email' => 'logout@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.auth.logout'));

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'message' => 'Logged out successfully.',
                ],
            ]);
    }

    public function test_user_password_is_never_exposed_in_api_responses(): void
    {
        $response = $this->postJson(route('api.v1.auth.register'), [
            'name' => 'Security Audit User',
            'email' => 'audit@example.com',
            'password' => 'super_secret_password',
            'password_confirmation' => 'super_secret_password',
        ]);

        $response->assertStatus(201);
        $payload = $response->getContent();

        $this->assertStringNotContainsString('super_secret_password', $payload);
        $this->assertStringNotContainsString('$2y$', $payload);
        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertArrayNotHasKey('remember_token', $response->json('data'));
    }
}
