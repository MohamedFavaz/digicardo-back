<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Jobs\SendEmailJob;
use App\Models\EmailDelivery;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BroadcastMessageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $agentA;
    private User $agentB;
    private User $customerA1;
    private User $customerA2;
    private User $customerB1;
    private User $plainUser;
    private User $suspendedUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);
        $this->agentA = User::factory()->create(['role' => UserRole::Agent, 'status' => UserStatus::Active, 'name' => 'Ravi Agent']);
        $this->agentB = User::factory()->create(['role' => UserRole::Agent, 'status' => UserStatus::Active]);
        $this->customerA1 = User::factory()->create(['role' => UserRole::User, 'status' => UserStatus::Active, 'agent_id' => $this->agentA->id]);
        $this->customerA2 = User::factory()->create(['role' => UserRole::User, 'status' => UserStatus::Active, 'agent_id' => $this->agentA->id]);
        $this->customerB1 = User::factory()->create(['role' => UserRole::User, 'status' => UserStatus::Active, 'agent_id' => $this->agentB->id]);
        $this->plainUser = User::factory()->create(['role' => UserRole::User, 'status' => UserStatus::Active]);
        $this->suspendedUser = User::factory()->create(['role' => UserRole::User, 'status' => UserStatus::Suspended]);
    }

    /** @return array<int, string> */
    private function recipientsOf(string $title): array
    {
        return Notification::where('title', $title)->pluck('user_id')->sort()->values()->all();
    }

    /** @param array<int, User> $users @return array<int, string> */
    private function ids(array $users): array
    {
        return collect($users)->pluck('id')->sort()->values()->all();
    }

    public function test_admin_can_message_everyone_except_admins_and_inactive_accounts(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/broadcasts', [
            'audience' => 'everyone',
            'title' => 'Diwali offer',
            'body' => 'Renew this week for 20% off.',
        ])->assertStatus(201)->assertJsonPath('data.message.recipient_count', 6);

        $this->assertSame(
            $this->ids([$this->agentA, $this->agentB, $this->customerA1, $this->customerA2, $this->customerB1, $this->plainUser]),
            $this->recipientsOf('Diwali offer'),
        );
    }

    public function test_admin_can_message_only_agents_or_only_users(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/broadcasts', [
            'audience' => 'agents', 'title' => 'Agents only', 'body' => 'New commission rates.',
        ])->assertStatus(201);
        $this->assertSame($this->ids([$this->agentA, $this->agentB]), $this->recipientsOf('Agents only'));

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/broadcasts', [
            'audience' => 'users', 'title' => 'Users only', 'body' => 'New templates.',
        ])->assertStatus(201);
        $this->assertSame(
            $this->ids([$this->customerA1, $this->customerA2, $this->customerB1, $this->plainUser]),
            $this->recipientsOf('Users only'),
        );
    }

    public function test_admin_can_message_one_agents_customers_and_specific_people(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/broadcasts', [
            'audience' => 'agent_customers', 'agent_id' => $this->agentA->id, 'title' => 'For A customers', 'body' => 'Hi',
        ])->assertStatus(201)->assertJsonPath('data.message.audience_label', "Ravi Agent's customers");
        $this->assertSame($this->ids([$this->customerA1, $this->customerA2]), $this->recipientsOf('For A customers'));

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/broadcasts', [
            'audience' => 'selected', 'user_ids' => [$this->plainUser->id, $this->agentB->id], 'title' => 'Picked', 'body' => 'Hi',
        ])->assertStatus(201);
        $this->assertSame($this->ids([$this->plainUser, $this->agentB]), $this->recipientsOf('Picked'));
    }

    public function test_recipient_sees_message_with_sender_and_link_in_their_notifications(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/broadcasts', [
            'audience' => 'selected', 'user_ids' => [$this->plainUser->id],
            'title' => 'Update your card', 'body' => 'Try the new Poster template.', 'link_url' => '/dashboard/edit',
        ])->assertStatus(201);

        $this->actingAs($this->plainUser, 'sanctum')->getJson('/api/v1/notifications?category=message')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.type', 'admin_message')
            ->assertJsonPath('data.items.0.category', 'message')
            ->assertJsonPath('data.items.0.data.sender_name', 'Digicardo team')
            ->assertJsonPath('data.items.0.data.link_url', '/dashboard/edit');
    }

    public function test_unsafe_links_are_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/broadcasts', [
            'audience' => 'everyone', 'title' => 'Bad', 'body' => 'x', 'link_url' => 'javascript:alert(1)',
        ])->assertStatus(422);
        $this->assertSame(0, Notification::count());
    }

    public function test_non_admins_cannot_use_admin_messaging(): void
    {
        $this->actingAs($this->plainUser, 'sanctum')->postJson('/api/v1/admin/broadcasts', [
            'audience' => 'everyone', 'title' => 'x', 'body' => 'x',
        ])->assertStatus(403);
        $this->actingAs($this->agentA, 'sanctum')->getJson('/api/v1/admin/broadcasts')->assertStatus(403);
    }

    public function test_agent_can_message_only_their_own_customers(): void
    {
        $this->actingAs($this->agentA, 'sanctum')->postJson('/api/v1/agent/messages', [
            'audience' => 'my_customers', 'title' => 'Shop timings', 'body' => 'Closed on Sunday.',
        ])->assertStatus(201)->assertJsonPath('data.message.recipient_count', 2);

        $this->assertSame($this->ids([$this->customerA1, $this->customerA2]), $this->recipientsOf('Shop timings'));
        $this->actingAs($this->customerA1, 'sanctum')->getJson('/api/v1/notifications')
            ->assertJsonPath('data.items.0.type', 'agent_message')
            ->assertJsonPath('data.items.0.data.sender_name', 'Ravi Agent');
    }

    public function test_agent_cannot_reach_other_agents_customers_by_id(): void
    {
        // Mixed list: only their own customer is delivered.
        $this->actingAs($this->agentA, 'sanctum')->postJson('/api/v1/agent/messages', [
            'audience' => 'selected', 'user_ids' => [$this->customerA1->id, $this->customerB1->id, $this->plainUser->id],
            'title' => 'Mixed', 'body' => 'Hi',
        ])->assertStatus(201);
        $this->assertSame($this->ids([$this->customerA1]), $this->recipientsOf('Mixed'));

        // Only someone else's people: refused outright.
        $this->actingAs($this->agentA, 'sanctum')->postJson('/api/v1/agent/messages', [
            'audience' => 'selected', 'user_ids' => [$this->customerB1->id], 'title' => 'Sneaky', 'body' => 'Hi',
        ])->assertStatus(422);
        $this->assertSame([], $this->recipientsOf('Sneaky'));

        // The picker only lists their own customers.
        $ids = collect($this->actingAs($this->agentA, 'sanctum')->getJson('/api/v1/agent/messages/recipients')->json('data.items'))->pluck('id')->sort()->values()->all();
        $this->assertSame($this->ids([$this->customerA1, $this->customerA2]), $ids);
    }

    public function test_only_real_agents_use_agent_messaging(): void
    {
        $this->actingAs($this->plainUser, 'sanctum')->postJson('/api/v1/agent/messages', [
            'audience' => 'my_customers', 'title' => 'x', 'body' => 'x',
        ])->assertStatus(403);
        // Admins pass the agent middleware but have their own messaging; they must not send "as an agent".
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/agent/messages', [
            'audience' => 'my_customers', 'title' => 'x', 'body' => 'x',
        ])->assertStatus(403);
    }

    public function test_history_shows_read_counts_and_unsend_removes_messages(): void
    {
        $id = $this->actingAs($this->agentA, 'sanctum')->postJson('/api/v1/agent/messages', [
            'audience' => 'my_customers', 'title' => 'Read me', 'body' => 'Hi',
        ])->json('data.message.id');

        $n = Notification::where('user_id', $this->customerA1->id)->firstOrFail();
        $this->actingAs($this->customerA1, 'sanctum')->patchJson("/api/v1/notifications/{$n->id}/read")->assertOk();

        $this->actingAs($this->agentA, 'sanctum')->getJson('/api/v1/agent/messages')
            ->assertOk()
            ->assertJsonPath('data.items.0.read_count', 1)
            ->assertJsonPath('data.items.0.recipient_count', 2)
            ->assertJsonPath('data.customers', 2);

        // Another agent cannot unsend it; its sender can.
        $this->actingAs($this->agentB, 'sanctum')->deleteJson("/api/v1/agent/messages/{$id}")->assertStatus(404);
        $this->actingAs($this->agentA, 'sanctum')->deleteJson("/api/v1/agent/messages/{$id}")->assertOk();
        $this->assertSame([], $this->recipientsOf('Read me'));

        // The admin sees agents' messages in the shared history.
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/broadcasts?sender=agent')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $id)
            ->assertJsonPath('data.items.0.sender.name', 'Ravi Agent');
    }

    public function test_admin_can_also_email_respecting_email_preferences(): void
    {
        Queue::fake();
        $this->plainUser->notificationPreferences()->create(['email_enabled' => false]);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/broadcasts', [
            'audience' => 'users', 'title' => 'Mail too', 'body' => 'Hello', 'send_email' => true,
        ])->assertStatus(201)->assertJsonPath('data.message.emailed', true);

        $this->assertSame(3, EmailDelivery::count());
        $this->assertDatabaseMissing('email_deliveries', ['user_id' => $this->plainUser->id]);
        Queue::assertPushed(SendEmailJob::class, 3);
    }

    public function test_audience_counts_for_the_admin_composer(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/broadcasts/audiences')
            ->assertOk()
            ->assertJsonPath('data.counts.everyone', 6)
            ->assertJsonPath('data.counts.users', 4)
            ->assertJsonPath('data.counts.agents', 2);
    }
}
