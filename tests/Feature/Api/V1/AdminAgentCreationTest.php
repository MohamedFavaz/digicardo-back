<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAgentCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_agent_successfully(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $payload = [
            'name' => 'Agent Bond',
            'email' => 'bond@example.com',
            'mobile' => '+91 9876543210',
            'password' => 'secret12345',
            'card_limit' => 50,
            'card_price' => 500,
            'agent_commission' => 200,
            'restore_on_delete' => true,
        ];

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/agents', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Agent Bond')
            ->assertJsonPath('data.email', 'bond@example.com');

        $createdAgent = User::where('email', 'bond@example.com')->first();
        $this->assertNotNull($createdAgent);
        $this->assertEquals(UserRole::Agent, $createdAgent->role);
        $this->assertEquals(UserStatus::Active, $createdAgent->status);
        $this->assertNotNull($createdAgent->email_verified_at);

        // Check quota
        $this->assertNotNull($createdAgent->agentQuota);
        $this->assertEquals(50, $createdAgent->agentQuota->card_limit);

        // Check commission
        $this->assertNotNull($createdAgent->commissionConfig);
        $this->assertEquals(500, $createdAgent->commissionConfig->card_price);
        $this->assertEquals(200, $createdAgent->commissionConfig->agent_commission);
    }
}

