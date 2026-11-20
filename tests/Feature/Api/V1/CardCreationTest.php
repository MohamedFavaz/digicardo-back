<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AgentQuota;
use App\Models\AgentSite;
use App\Models\Profile;
use App\Models\User;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cards made by an admin (Users → Customer card) and by an agent (My cards → New card):
 * they start clean (no placeholder bio, no automatic verified tick, the template's own
 * colours), reserved addresses are refused, and the agent learns the card's real address.
 */
class CardCreationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);
        $this->agent = User::factory()->create(['role' => UserRole::Agent, 'status' => UserStatus::Active]);
        AgentQuota::create(['agent_id' => $this->agent->id, 'card_limit' => 5, 'cards_used' => 0]);
    }

    private function agentCard(array $overrides = [])
    {
        return $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/agent/cards', array_merge([
            'customer_name' => 'Glow Beauty Salon',
            'customer_email' => 'salon@example.test',
            'username' => 'glowsalon',
            'password' => 'Customer123!',
            'bio' => 'Hair and bridal make-up.',
            'template_id' => 'showcase',
        ], $overrides));
    }

    public function test_admin_created_card_starts_clean(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/users', [
            'name' => "Meera's Home Bakery",
            'email' => 'meera@example.test',
            'username' => 'meerabakes',
            'role' => 'user',
            'status' => 'active',
        ])->assertCreated();

        $profile = Profile::where('username', 'meerabakes')->firstOrFail();
        $this->assertSame("Meera's Home Bakery", $profile->content['identity']['name']);
        $this->assertSame('', $profile->content['identity']['bio']);
        $this->assertFalse($profile->content['identity']['verified_badge']);
        $this->assertNull($profile->bio);
        $this->assertSame(TemplateService::APPROVED_TEMPLATES['vcard']['default_theme']['color_background'], $profile->theme_tokens['color_background']);

        $public = $this->getJson('/api/v1/p/meerabakes')->assertOk()->json('data');
        $this->assertStringNotContainsString('Welcome to my', json_encode($public));
    }

    public function test_reserved_addresses_are_refused(): void
    {
        foreach (['login', 'agent', 'storage', 'partner'] as $reserved) {
            $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/users', [
                'name' => 'Someone', 'email' => "{$reserved}@example.test", 'username' => $reserved, 'role' => 'user', 'status' => 'active',
            ])->assertStatus(422);
            $this->agentCard(['username' => $reserved, 'customer_email' => "a-{$reserved}@example.test"])->assertStatus(422);
        }
        $this->assertSame(0, AgentQuota::where('agent_id', $this->agent->id)->value('cards_used'));
    }

    public function test_agent_card_uses_the_chosen_template_and_returns_its_address(): void
    {
        $res = $this->agentCard()->assertCreated();
        $this->assertSame(rtrim((string) config('app.frontend_url'), '/') . '/glowsalon', $res->json('data.public_url'));

        $profile = Profile::where('username', 'glowsalon')->firstOrFail();
        $this->assertSame('showcase', $profile->template_id);
        $this->assertSame('Glow Beauty Salon', $profile->content['identity']['name']);
        $this->assertSame('Hair and bridal make-up.', $profile->content['identity']['bio']);
        $this->assertFalse($profile->content['identity']['verified_badge']);
        $this->assertSame(1, AgentQuota::where('agent_id', $this->agent->id)->value('cards_used'));

        // Listing returns the same address.
        $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/agent/cards')->assertOk()
            ->assertJsonPath('data.0.public_url', $res->json('data.public_url'));
    }

    public function test_auto_created_card_address_is_a_valid_username(): void
    {
        $first = User::factory()->create(['role' => UserRole::User, 'status' => UserStatus::Active, 'name' => 'Rohan Mehta']);
        $second = User::factory()->create(['role' => UserRole::User, 'status' => UserStatus::Active, 'name' => 'Rohan Mehta']);

        $this->actingAs($first, 'sanctum')->getJson('/api/v1/profile')->assertOk()->assertJsonPath('data.username', 'rohan-mehta');
        $this->actingAs($second, 'sanctum')->getJson('/api/v1/profile')->assertOk()->assertJsonPath('data.username', 'rohan-mehta-2');

        $this->assertSame('user', \App\Services\UsernameService::fromName('  !!  '));
        $this->assertSame('al0', \App\Services\UsernameService::fromName('Al'));
        $long = \App\Services\UsernameService::fromName(str_repeat('Very Long Business Name ', 4));
        $this->assertLessThanOrEqual(30, strlen($long));
        $this->assertTrue(\App\Services\UsernameService::isValidFormat($long));
    }

    public function test_agents_do_not_get_a_card_of_their_own(): void
    {
        $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/profile')->assertNotFound();
        $this->assertFalse(Profile::where('user_id', $this->agent->id)->exists());
    }

    public function test_repair_command_fixes_old_addresses_and_hides_agent_cards(): void
    {
        $owner = User::factory()->create(['role' => UserRole::User, 'status' => UserStatus::Active, 'name' => 'Asha']);
        Profile::create(['user_id' => $owner->id, 'username' => 'asha store', 'is_public' => true, 'version' => 1]);
        Profile::create(['user_id' => $this->agent->id, 'username' => 'ravi agent', 'is_public' => true, 'version' => 1]);
        // Works today, so it must keep its address (printed QR codes) even though "help" is reserved now.
        $helper = User::factory()->create(['role' => UserRole::User, 'status' => UserStatus::Active]);
        Profile::create(['user_id' => $helper->id, 'username' => 'help', 'is_public' => true, 'version' => 1]);
        // An agent card with real content is left alone.
        $busyAgent = User::factory()->create(['role' => UserRole::Agent, 'status' => UserStatus::Active]);
        Profile::create(['user_id' => $busyAgent->id, 'username' => 'busy-agent', 'is_public' => true, 'version' => 1,
            'content' => \App\Services\CardContentSchema::starter('Busy Agent', 'We make cards.')]);

        // Listing changes nothing.
        $this->artisan('profiles:repair-usernames')->assertSuccessful();
        $this->assertTrue(Profile::where('username', 'asha store')->exists());

        $this->artisan('profiles:repair-usernames', ['--fix' => true])->assertSuccessful();
        $this->assertTrue(Profile::where('username', 'asha-store')->where('is_public', true)->exists());
        $agentCard = Profile::where('user_id', $this->agent->id)->firstOrFail();
        $this->assertSame('ravi-agent', $agentCard->username);
        $this->assertFalse((bool) $agentCard->is_public);
        $this->assertTrue(Profile::where('username', 'help')->exists());
        $this->assertTrue((bool) Profile::where('username', 'busy-agent')->value('is_public'));
    }

    public function test_agent_with_a_live_domain_gets_domain_addresses(): void
    {
        AgentSite::create([
            'agent_id' => $this->agent->id,
            'slug' => 'glow-partners',
            'content' => [],
            'domain' => 'glowcards.in',
            'domain_status' => AgentSite::DOMAIN_ACTIVE,
        ]);

        $this->agentCard()->assertCreated()->assertJsonPath('data.public_url', 'https://glowcards.in/glowsalon');
    }
}
