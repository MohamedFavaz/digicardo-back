<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgentProfileTest extends TestCase
{
    use RefreshDatabase;

    private function createAgent(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Agent Bond',
            'email' => 'agent@digicardo.com',
            'role' => UserRole::Agent,
            'status' => UserStatus::Active,
            'mobile' => '+91 9999988888',
        ], $attributes));
    }

    public function test_agent_can_retrieve_profile_details(): void
    {
        $agent = $this->createAgent();

        $response = $this->actingAs($agent, 'sanctum')->getJson('/api/v1/agent/profile');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.name', 'Agent Bond')
            ->assertJsonPath('data.user.email', 'agent@digicardo.com')
            ->assertJsonPath('data.user.mobile', '+91 9999988888');
    }

    public function test_non_agent_cannot_access_agent_profile(): void
    {
        $regularUser = User::factory()->create([
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $response = $this->actingAs($regularUser, 'sanctum')->getJson('/api/v1/agent/profile');

        $response->assertStatus(403);
    }

    public function test_agent_can_update_name_and_mobile(): void
    {
        $agent = $this->createAgent();

        $response = $this->actingAs($agent, 'sanctum')->putJson('/api/v1/agent/profile', [
            'name' => 'Updated Agent Name',
            'mobile' => '+91 1234567890',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.name', 'Updated Agent Name')
            ->assertJsonPath('data.user.mobile', '+91 1234567890');

        $this->assertEquals('Updated Agent Name', $agent->fresh()->name);
        $this->assertEquals('+91 1234567890', $agent->fresh()->mobile);
    }

    public function test_agent_can_upload_and_delete_avatar(): void
    {
        Storage::fake('public');

        $agent = $this->createAgent();
        $file = UploadedFile::fake()->image('agent_avatar.png', 400, 400);

        // Upload avatar
        $uploadResponse = $this->actingAs($agent, 'sanctum')->postJson('/api/v1/agent/profile/avatar', [
            'image' => $file,
        ]);

        $uploadResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        $freshAgent = $agent->fresh();
        $this->assertNotNull($freshAgent->avatar_url);

        // Delete avatar
        $deleteResponse = $this->actingAs($agent, 'sanctum')->deleteJson('/api/v1/agent/profile/avatar');

        $deleteResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.avatar_url', null);

        $this->assertNull($agent->fresh()->avatar_url);
    }

    public function test_agent_can_change_password(): void
    {
        $agent = $this->createAgent([
            'password' => Hash::make('OldPassword123!'),
        ]);

        $response = $this->actingAs($agent, 'sanctum')->postJson('/api/v1/agent/profile/password', [
            'current_password' => 'OldPassword123!',
            'password' => 'NewSecretPass999!',
            'password_confirmation' => 'NewSecretPass999!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('NewSecretPass999!', $agent->fresh()->password));
    }

    public function test_agent_password_change_rejects_incorrect_current_password(): void
    {
        $agent = $this->createAgent([
            'password' => Hash::make('CorrectPassword123!'),
        ]);

        $response = $this->actingAs($agent, 'sanctum')->postJson('/api/v1/agent/profile/password', [
            'current_password' => 'WrongPassword!',
            'password' => 'NewSecretPass999!',
            'password_confirmation' => 'NewSecretPass999!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}

