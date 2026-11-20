<?php

namespace Tests\Feature\Api\V1;

use App\Contracts\AgentDomainDnsCheckerInterface;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AgentLead;
use App\Models\AgentSite;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentSiteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $agent;
    private User $otherAgent;
    private Profile $customerCard;
    private Profile $otherAgentsCard;
    private Profile $directCard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);
        $this->agent = User::factory()->create(['role' => UserRole::Agent, 'status' => UserStatus::Active, 'name' => 'Excellent Cards', 'mobile' => '+91 90000 00001']);
        $this->otherAgent = User::factory()->create(['role' => UserRole::Agent, 'status' => UserStatus::Active, 'name' => 'Other Agent']);
        $this->customerCard = $this->card('rahul', $this->agent);
        $this->otherAgentsCard = $this->card('meera', $this->otherAgent);
        $this->directCard = $this->card('direct', null);
    }

    private function card(string $username, ?User $agent): Profile
    {
        $user = User::factory()->create(['role' => UserRole::User, 'status' => UserStatus::Active, 'agent_id' => $agent?->id]);

        return Profile::create([
            'id' => strtolower((string) Str::ulid()),
            'user_id' => $user->id,
            'username' => $username,
            'display_name' => ucfirst($username),
            'is_public' => true,
            'version' => 1,
        ]);
    }

    /** One fake DNS checker per test whose answer can change (controllers are reused between requests). */
    private ?object $dns = null;

    private function fakeDns(bool $ok, array $found = []): void
    {
        if (! $this->dns) {
            $this->dns = new class implements AgentDomainDnsCheckerInterface {
                public bool $ok = false;
                public array $found = [];

                public function check(string $domain): array
                {
                    return ['ok' => $this->ok, 'found' => $this->found, 'expected' => ['76.76.21.21']];
                }
            };
            $this->app->instance(AgentDomainDnsCheckerInterface::class, $this->dns);
        }
        $this->dns->ok = $ok;
        $this->dns->found = $found;
    }

    // ── Agent: editing ─────────────────────────────────────────────────────────

    public function test_agent_gets_a_site_with_defaults_and_their_customers(): void
    {
        $res = $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/agent/site')->assertOk();
        $res->assertJsonPath('data.site.slug', 'excellent-cards')
            ->assertJsonPath('data.site.content.brand.name', 'Excellent Cards')
            ->assertJsonPath('data.site.content.contact.whatsapp', '+91 90000 00001')
            ->assertJsonPath('data.site.published', false)
            ->assertJsonPath('data.site.domain', null);
        $this->assertSame(['rahul'], collect($res->json('data.customers'))->pluck('username')->all());
    }

    public function test_only_real_agents_can_edit_a_site(): void
    {
        $plain = User::factory()->create(['role' => UserRole::User, 'status' => UserStatus::Active]);
        $this->actingAs($plain, 'sanctum')->getJson('/api/v1/agent/site')->assertStatus(403);
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/agent/site')->assertStatus(403);
    }

    public function test_content_is_sanitised_and_showcase_limited_to_own_customers(): void
    {
        Storage::fake('public');
        $own = $this->actingAs($this->agent, 'sanctum')
            ->postJson('/api/v1/agent/site/images', ['image' => UploadedFile::fake()->image('logo.png', 200, 200)])
            ->assertStatus(201)
            ->json('data.url');

        $res = $this->actingAs($this->agent, 'sanctum')->putJson('/api/v1/agent/site', [
            'content' => [
                'brand' => ['name' => '  Excellent Cards  ', 'logo_url' => $own],
                'hero' => ['title' => str_repeat('x', 200), 'image_url' => 'https://evil.example.com/tracker.png'],
                'theme' => ['preset' => 'midnight', 'accent' => '#ff6600'],
                'contact' => ['instagram' => 'javascript:alert(1)', 'website' => 'https://excellentcards.in'],
                'showcase' => ['profile_ids' => [$this->customerCard->id, $this->otherAgentsCard->id, $this->directCard->id]],
                'layout' => ['faq', 'nonsense', 'about'],
            ],
        ])->assertOk();

        $res->assertJsonPath('data.site.content.brand.name', 'Excellent Cards')
            ->assertJsonPath('data.site.content.brand.logo_url', $own)
            ->assertJsonPath('data.site.content.hero.image_url', '')
            ->assertJsonPath('data.site.content.theme.preset', 'midnight')
            ->assertJsonPath('data.site.content.theme.accent', '#FF6600')
            ->assertJsonPath('data.site.content.contact.instagram', '')
            ->assertJsonPath('data.site.content.contact.website', 'https://excellentcards.in')
            ->assertJsonPath('data.site.content.showcase.profile_ids', [$this->customerCard->id])
            ->assertJsonPath('data.site.content.layout.0', 'faq')
            ->assertJsonPath('data.site.content.layout.1', 'about');
        $this->assertSame(80, mb_strlen($res->json('data.site.content.hero.title')));
        $this->assertCount(7, $res->json('data.site.content.layout'));
    }

    public function test_agent_can_change_the_page_address_but_not_to_a_reserved_or_taken_one(): void
    {
        $this->actingAs($this->otherAgent, 'sanctum')->getJson('/api/v1/agent/site')->assertOk(); // other-agent
        $content = ['brand' => ['name' => 'Excellent']];

        $this->actingAs($this->agent, 'sanctum')->putJson('/api/v1/agent/site', ['content' => $content, 'slug' => 'admin'])->assertStatus(422);
        $this->actingAs($this->agent, 'sanctum')->putJson('/api/v1/agent/site', ['content' => $content, 'slug' => 'other-agent'])->assertStatus(422);
        $this->actingAs($this->agent, 'sanctum')->putJson('/api/v1/agent/site', ['content' => $content, 'slug' => 'Bad Slug!'])->assertStatus(422);
        $this->actingAs($this->agent, 'sanctum')->putJson('/api/v1/agent/site', ['content' => $content, 'slug' => 'excellent'])
            ->assertOk()
            ->assertJsonPath('data.site.slug', 'excellent');
    }

    // ── Public page ────────────────────────────────────────────────────────────

    public function test_landing_page_is_public_only_when_published_and_not_disabled(): void
    {
        $this->actingAs($this->agent, 'sanctum')->putJson('/api/v1/agent/site', [
            'content' => ['showcase' => ['profile_ids' => [$this->customerCard->id]]],
        ])->assertOk();

        $this->getJson('/api/v1/public/partners/excellent-cards')->assertStatus(404);

        $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/agent/site/publish', ['published' => true])->assertOk();
        $this->getJson('/api/v1/public/partners/excellent-cards')
            ->assertOk()
            ->assertJsonPath('data.content.brand.name', 'Excellent Cards')
            ->assertJsonPath('data.showcase.0.username', 'rahul')
            ->assertJsonPath('data.domain', null);

        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/admin/agents/{$this->agent->id}/site/landing", ['disabled' => true])->assertOk();
        $this->getJson('/api/v1/public/partners/excellent-cards')->assertStatus(404);
    }

    public function test_public_pages_are_rebuilt_when_the_site_changes(): void
    {
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\RevalidatePartnerPage::class, \App\Jobs\RevalidatePublicProfile::class]);
        $slug = $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/agent/site')->json('data.site.slug');

        // Publishing: the landing page and the agent's cards ("Card by …" footer) are rebuilt.
        $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/agent/site/publish', ['published' => true])->assertOk();
        \Illuminate\Support\Facades\Bus::assertDispatchedAfterResponse(\App\Jobs\RevalidatePartnerPage::class, fn ($job) => $job->slug === $slug);
        \Illuminate\Support\Facades\Bus::assertDispatchedAfterResponse(\App\Jobs\RevalidatePublicProfile::class, fn ($job) => $job->username === 'rahul');
        \Illuminate\Support\Facades\Bus::assertNotDispatchedAfterResponse(\App\Jobs\RevalidatePublicProfile::class, fn ($job) => $job->username === 'meera');

        // An ordinary edit rebuilds only the landing page, not every card.
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\RevalidatePartnerPage::class, \App\Jobs\RevalidatePublicProfile::class]);
        $content = $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/agent/site')->json('data.site.content');
        $content['hero']['headline'] = 'New headline';
        $this->actingAs($this->agent, 'sanctum')->putJson('/api/v1/agent/site', ['content' => $content])->assertOk();
        \Illuminate\Support\Facades\Bus::assertDispatchedAfterResponse(\App\Jobs\RevalidatePartnerPage::class);
        \Illuminate\Support\Facades\Bus::assertNotDispatchedAfterResponse(\App\Jobs\RevalidatePublicProfile::class);

        // Domain going live changes every card's link and QR code.
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\RevalidatePartnerPage::class, \App\Jobs\RevalidatePublicProfile::class]);
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/agents/{$this->agent->id}/site/domain", ['domain' => 'excellentcards.in'])->assertOk();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/admin/agents/{$this->agent->id}/site/domain/activate")->assertOk();
        \Illuminate\Support\Facades\Bus::assertDispatchedAfterResponse(\App\Jobs\RevalidatePublicProfile::class, fn ($job) => $job->username === 'rahul');
    }

    public function test_enquiries_reach_the_agent(): void
    {
        $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/agent/site/publish', ['published' => true])->assertOk();

        $this->postJson('/api/v1/public/partners/excellent-cards/leads', ['name' => 'Asha'])->assertStatus(422);
        $this->postJson('/api/v1/public/partners/excellent-cards/leads', ['name' => 'Bot', 'phone' => '+91 98765 43210', 'company_website' => 'spam'])->assertStatus(422);
        $this->postJson('/api/v1/public/partners/excellent-cards/leads', [
            'name' => 'Asha', 'phone' => '+91 98765 43210', 'package' => 'Business', 'message' => 'Need 2 cards',
        ])->assertStatus(201);

        $this->assertDatabaseHas('agent_leads', ['agent_id' => $this->agent->id, 'name' => 'Asha', 'package' => 'Business']);
        $this->assertTrue(Notification::where('user_id', $this->agent->id)->where('title', 'New enquiry from Asha')->exists());

        $list = $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/agent/leads')->assertOk();
        $list->assertJsonPath('data.unread', 1)->assertJsonPath('data.items.0.name', 'Asha');
        $id = $list->json('data.items.0.id');

        // Other agents can't touch it.
        $this->actingAs($this->otherAgent, 'sanctum')->patchJson("/api/v1/agent/leads/{$id}/read")->assertStatus(404);
        $this->actingAs($this->agent, 'sanctum')->patchJson("/api/v1/agent/leads/{$id}/read")->assertOk();
        $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/agent/leads')->assertJsonPath('data.unread', 0);
        $this->actingAs($this->agent, 'sanctum')->deleteJson("/api/v1/agent/leads/{$id}")->assertOk();
        $this->assertSame(0, AgentLead::count());
    }

    // ── Admin: agent domain ────────────────────────────────────────────────────

    public function test_admin_sets_checks_and_activates_an_agent_domain(): void
    {
        $base = "/api/v1/admin/agents/{$this->agent->id}/site";
        $this->fakeDns(false);

        $this->actingAs($this->admin, 'sanctum')->putJson("{$base}/domain", ['domain' => 'https://bad/'])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->putJson("{$base}/domain", ['domain' => 'ExcellentCards.in'])
            ->assertOk()
            ->assertJsonPath('data.site.domain', 'excellentcards.in')
            ->assertJsonPath('data.site.domain_status', 'pending');

        // Pending: cards still on the main domain.
        $this->actingAs($this->customerCard->user, 'sanctum')->getJson('/api/v1/profile')->assertJsonPath('data.agent_domain', null);

        $this->fakeDns(false, ['1.2.3.4']);
        $this->actingAs($this->admin, 'sanctum')->postJson("{$base}/domain/check")
            ->assertOk()
            ->assertJsonPath('data.site.domain_status', 'failed');
        $this->assertStringContainsString('1.2.3.4', (string) AgentSite::first()->domain_error);

        $this->fakeDns(true, ['76.76.21.21']);
        $this->actingAs($this->admin, 'sanctum')->postJson("{$base}/domain/check")
            ->assertOk()
            ->assertJsonPath('data.site.domain_status', 'active')
            ->assertJsonPath('data.site.page_url', 'https://excellentcards.in');

        // Live: the agent's customers' cards use the agent domain; others don't.
        $this->actingAs($this->customerCard->user, 'sanctum')->getJson('/api/v1/profile')->assertJsonPath('data.agent_domain', 'excellentcards.in');
        $this->getJson('/api/v1/p/rahul')->assertJsonPath('data.agent_domain', 'excellentcards.in');
        $this->getJson('/api/v1/p/meera')->assertJsonPath('data.agent_domain', null);
        $this->getJson('/api/v1/p/direct')->assertJsonPath('data.agent_domain', null);

        // Host routing for the frontend.
        $this->withHeader('x-internal-secret', 'local-internal-service-secret')
            ->getJson('/api/v1/internal/domains/resolve?host=excellentcards.in')
            ->assertOk()
            ->assertJsonPath('data.type', 'agent')
            ->assertJsonPath('data.slug', 'excellent-cards');

        // Remove: back to the main domain.
        $this->actingAs($this->admin, 'sanctum')->deleteJson("{$base}/domain")->assertOk()->assertJsonPath('data.site.domain', null);
        $this->getJson('/api/v1/p/rahul')->assertJsonPath('data.agent_domain', null);
        $this->withHeader('x-internal-secret', 'local-internal-service-secret')
            ->getJson('/api/v1/internal/domains/resolve?host=excellentcards.in')
            ->assertStatus(404);
    }

    public function test_admin_can_activate_without_a_dns_check_and_domains_are_unique(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/agents/{$this->agent->id}/site/domain", ['domain' => 'excellentcards.in'])->assertOk();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/admin/agents/{$this->agent->id}/site/domain/activate")
            ->assertOk()
            ->assertJsonPath('data.site.domain_status', 'active');

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/agents/{$this->otherAgent->id}/site/domain", ['domain' => 'excellentcards.in'])->assertStatus(422);
    }

    public function test_agents_and_users_cannot_manage_domains(): void
    {
        $this->actingAs($this->agent, 'sanctum')->putJson("/api/v1/admin/agents/{$this->agent->id}/site/domain", ['domain' => 'x.in'])->assertStatus(403);
        $this->actingAs($this->customerCard->user, 'sanctum')->getJson("/api/v1/admin/agents/{$this->agent->id}/site")->assertStatus(403);
        // Admin endpoints only accept agents.
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/admin/agents/{$this->customerCard->user_id}/site")->assertStatus(404);
    }

    public function test_partner_is_a_reserved_username(): void
    {
        $this->assertTrue(app(\App\Services\UsernameService::class)->isReserved('partner'));
    }
}
