<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AgentCard;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * After an agent opens a customer's card to edit it, the session is signed in as that customer
 * (the agent is remembered as "impersonator_agent_id"). The agent panel must still show the
 * agent's own numbers — previously it counted the customer's cards until the agent logged in again.
 */
class AgentDashboardWhileEditingTest extends TestCase
{
    use RefreshDatabase;

    /** A page on the site the API trusts for session cookies (as the browser sends it). */
    private function frontend(): string
    {
        return 'http://'.trim(config('sanctum.stateful')[0]).'/agent/dashboard';
    }

    private function cardFor(User $agent, string $handle): User
    {
        $customer = User::factory()->create(['role' => UserRole::User, 'status' => UserStatus::Active]);
        $profile = Profile::create(['user_id' => $customer->id, 'username' => $handle, 'is_public' => true, 'version' => 1]);
        AgentCard::create([
            'agent_id' => $agent->id,
            'customer_user_id' => $customer->id,
            'profile_id' => $profile->id,
            'card_name' => ucfirst($handle),
            'customer_name' => ucfirst($handle),
            'status' => 'active',
        ]);

        return $customer;
    }

    public function test_dashboard_counts_the_agents_cards_while_a_customer_card_is_open(): void
    {
        $agent = User::factory()->create(['role' => UserRole::Agent, 'status' => UserStatus::Active]);
        $customer = $this->cardFor($agent, 'firstcard');
        $this->cardFor($agent, 'secondcard');
        $this->cardFor($agent, 'thirdcard');

        // The browser is editing a customer's card: the session is signed in as the customer.
        // (Run first — the test client keeps the first signed-in user for later requests.)
        $this->actingAs($customer, 'web')
            ->withHeader('Referer', $this->frontend())
            ->withSession(['impersonator_agent_id' => $agent->id])
            ->getJson('/api/v1/agent/dashboard')
            ->assertOk()
            ->assertJsonPath('data.total_cards', 3);

        $this->actingAs($customer, 'web')
            ->withHeader('Referer', $this->frontend())
            ->withSession(['impersonator_agent_id' => $agent->id])
            ->getJson('/api/v1/agent/cards')
            ->assertOk();
    }

    public function test_a_forged_or_suspended_impersonator_gets_no_agent_access(): void
    {
        $customer = User::factory()->create(['role' => UserRole::User, 'status' => UserStatus::Active]);
        $otherCustomer = User::factory()->create(['role' => UserRole::User, 'status' => UserStatus::Active]);
        $suspended = User::factory()->create(['role' => UserRole::Agent, 'status' => UserStatus::Suspended]);

        foreach ([$otherCustomer->id, $suspended->id, 'not-a-user'] as $id) {
            $this->actingAs($customer, 'web')
                ->withHeader('Referer', $this->frontend())
                ->withSession(['impersonator_agent_id' => $id])
                ->getJson('/api/v1/agent/dashboard')
                ->assertForbidden();
        }
    }
}
