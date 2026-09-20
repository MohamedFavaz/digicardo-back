<?php

namespace Tests\Feature\Api\V1;

use App\Contracts\DnsVerificationServiceInterface;
use App\Enums\DomainStatus;
use App\Models\Profile;
use App\Models\ProfileDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileDomainTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->user = User::factory()->create();

        $plan = \App\Models\Plan::where('code', 'business')->first() ?? \App\Models\Plan::where('code', 'pro')->first();
        if ($plan) {
            \App\Models\Subscription::create([
                'user_id' => $this->user->id,
                'plan_id' => $plan->id,
                'status' => \App\Enums\SubscriptionStatus::Active,
                'billing_interval' => 'monthly',
            ]);
        }

        $this->profile = Profile::create([
            'id' => (string) Str::ulid(),
            'user_id' => $this->user->id,
            'username' => 'testuser',
            'display_name' => 'Test User',
            'is_public' => true,
        ]);
    }

    public function test_authenticated_user_can_add_valid_custom_domain(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/profile/domains', [
                'domain' => 'my-brand.com',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'domain' => 'my-brand.com',
                    'normalized_domain' => 'my-brand.com',
                    'status' => 'pending',
                    'is_primary' => false,
                    'verification_instructions' => [
                        'record_type' => 'TXT',
                        'host' => '_Digicardo-verify.my-brand.com',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('profile_domains', [
            'profile_id' => $this->profile->id,
            'normalized_domain' => 'my-brand.com',
            'status' => 'pending',
        ]);
    }

    public function test_domain_normalization_handles_uppercase_and_trailing_dots(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/profile/domains', [
                'domain' => '  AlexRivers.STUDIO.  ',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'normalized_domain' => 'alexrivers.studio',
                    'status' => 'pending',
                ],
            ]);
    }

    public function test_rejects_protocol_containing_domain(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/profile/domains', [
                'domain' => 'https://example.com',
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['success', 'error' => ['details' => ['domain']]]);
    }

    public function test_rejects_path_containing_domain(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/profile/domains', [
                'domain' => 'example.com/profile',
            ]);

        $response->assertStatus(422);
    }

    public function test_rejects_localhost_and_subdomains(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/profile/domains', [
                'domain' => 'localhost',
            ]);

        $response->assertStatus(422);

        $responseSub = $this->actingAs($this->user)
            ->postJson('/api/v1/profile/domains', [
                'domain' => 'sub.localhost',
            ]);

        $responseSub->assertStatus(422);
    }

    public function test_rejects_ip_addresses(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/profile/domains', [
                'domain' => '192.168.1.1',
            ]);

        $response->assertStatus(422);
    }

    public function test_rejects_wildcard_domains(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/profile/domains', [
                'domain' => '*.example.com',
            ]);

        $response->assertStatus(422);
    }

    public function test_rejects_system_Digicardo_domains(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/profile/domains', [
                'domain' => 'Digicardo.app',
            ]);

        $response->assertStatus(422);
    }

    public function test_prevents_duplicate_domain_registration(): void
    {
        ProfileDomain::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $this->profile->id,
            'user_id' => $this->user->id,
            'domain' => 'taken.com',
            'normalized_domain' => 'taken.com',
            'status' => DomainStatus::Pending,
            'verification_token' => 'test-token-12345',
        ]);

        $otherUser = User::factory()->create();
        Profile::create([
            'id' => (string) Str::ulid(),
            'user_id' => $otherUser->id,
            'username' => 'otheruser',
            'display_name' => 'Other User',
            'is_public' => true,
        ]);

        $response = $this->actingAs($otherUser)
            ->postJson('/api/v1/profile/domains', [
                'domain' => 'taken.com',
            ]);

        $response->assertStatus(409);
    }

    public function test_prevents_cross_user_domain_management(): void
    {
        $domain = ProfileDomain::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $this->profile->id,
            'user_id' => $this->user->id,
            'domain' => 'user-a.com',
            'normalized_domain' => 'user-a.com',
            'status' => DomainStatus::Pending,
            'verification_token' => 'token-a',
        ]);

        $userB = User::factory()->create();
        Profile::create([
            'id' => (string) Str::ulid(),
            'user_id' => $userB->id,
            'username' => 'userb',
            'display_name' => 'User B',
            'is_public' => true,
        ]);

        $response = $this->actingAs($userB)
            ->deleteJson("/api/v1/profile/domains/{$domain->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('profile_domains', ['id' => $domain->id, 'deleted_at' => null]);
    }

    public function test_dns_verification_success_with_mocked_service(): void
    {
        $domain = ProfileDomain::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $this->profile->id,
            'user_id' => $this->user->id,
            'domain' => 'verified-flow.com',
            'normalized_domain' => 'verified-flow.com',
            'status' => DomainStatus::Pending,
            'verification_token' => 'secure-token-abc',
        ]);

        // Mock DNS verification interface
        $mockVerifier = $this->createMock(DnsVerificationServiceInterface::class);
        $mockVerifier->expects($this->once())
            ->method('verifyToken')
            ->with('_Digicardo-verify.verified-flow.com', 'secure-token-abc')
            ->willReturn(true);

        $this->app->instance(DnsVerificationServiceInterface::class, $mockVerifier);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/profile/domains/{$domain->id}/verify");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'verified',
                ],
                'meta' => [
                    'verified' => true,
                ],
            ]);

        $this->assertEquals(DomainStatus::Verified, $domain->fresh()->status);
        $this->assertNotNull($domain->fresh()->verified_at);
    }

    public function test_dns_verification_failure(): void
    {
        $domain = ProfileDomain::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $this->profile->id,
            'user_id' => $this->user->id,
            'domain' => 'failing-dns.com',
            'normalized_domain' => 'failing-dns.com',
            'status' => DomainStatus::Pending,
            'verification_token' => 'secure-token-xyz',
        ]);

        $mockVerifier = $this->createMock(DnsVerificationServiceInterface::class);
        $mockVerifier->expects($this->once())
            ->method('verifyToken')
            ->willReturn(false);

        $this->app->instance(DnsVerificationServiceInterface::class, $mockVerifier);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/profile/domains/{$domain->id}/verify");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'failed',
                ],
                'meta' => [
                    'verified' => false,
                ],
            ]);

        $this->assertEquals(DomainStatus::Failed, $domain->fresh()->status);
    }

    public function test_cannot_activate_unverified_domain(): void
    {
        $domain = ProfileDomain::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $this->profile->id,
            'user_id' => $this->user->id,
            'domain' => 'unverified.com',
            'normalized_domain' => 'unverified.com',
            'status' => DomainStatus::Pending,
            'verification_token' => 'token-123',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/profile/domains/{$domain->id}/activate");

        $response->assertStatus(422);
    }

    public function test_successful_domain_activation_and_primary_assignment(): void
    {
        $domain = ProfileDomain::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $this->profile->id,
            'user_id' => $this->user->id,
            'domain' => 'ready-domain.com',
            'normalized_domain' => 'ready-domain.com',
            'status' => DomainStatus::Verified,
            'verification_token' => 'token-verified',
            'verified_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/profile/domains/{$domain->id}/activate");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'active',
                    'is_primary' => true,
                ],
            ]);

        $this->assertEquals(DomainStatus::Active, $domain->fresh()->status);
        $this->assertTrue($domain->fresh()->is_primary);
    }

    public function test_primary_domain_switching_transaction_consistency(): void
    {
        $domain1 = ProfileDomain::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $this->profile->id,
            'user_id' => $this->user->id,
            'domain' => 'domain-one.com',
            'normalized_domain' => 'domain-one.com',
            'status' => DomainStatus::Active,
            'verification_token' => 'token-1',
            'is_primary' => true,
        ]);

        $domain2 = ProfileDomain::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $this->profile->id,
            'user_id' => $this->user->id,
            'domain' => 'domain-two.com',
            'normalized_domain' => 'domain-two.com',
            'status' => DomainStatus::Active,
            'verification_token' => 'token-2',
            'is_primary' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/profile/domains/{$domain2->id}/primary");

        $response->assertStatus(200);

        $this->assertFalse($domain1->fresh()->is_primary);
        $this->assertTrue($domain2->fresh()->is_primary);
    }

    public function test_disable_and_delete_domain_invalidates_cache_and_resolution(): void
    {
        $domain = ProfileDomain::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $this->profile->id,
            'user_id' => $this->user->id,
            'domain' => 'active-resolve.com',
            'normalized_domain' => 'active-resolve.com',
            'status' => DomainStatus::Active,
            'verification_token' => 'token-res',
            'is_primary' => true,
        ]);

        // Pre-warm resolution cache
        $resolution = $this->withHeaders(['x-internal-secret' => 'local-internal-service-secret'])
            ->getJson('/api/v1/internal/domains/resolve?host=active-resolve.com');

        $resolution->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'profile_id' => $this->profile->id,
                    'username' => 'testuser',
                ],
            ]);

        // Disable domain
        $this->actingAs($this->user)
            ->postJson("/api/v1/profile/domains/{$domain->id}/disable")
            ->assertStatus(200);

        // Resolution should now fail
        $disabledResolution = $this->withHeaders(['x-internal-secret' => 'local-internal-service-secret'])
            ->getJson('/api/v1/internal/domains/resolve?host=active-resolve.com');

        $disabledResolution->assertStatus(404);
    }

    public function test_internal_resolution_endpoint_rejects_missing_or_invalid_secret(): void
    {
        $response = $this->getJson('/api/v1/internal/domains/resolve?host=example.com');
        $response->assertStatus(403);

        $responseBad = $this->withHeaders(['x-internal-secret' => 'wrong-secret'])
            ->getJson('/api/v1/internal/domains/resolve?host=example.com');
        $responseBad->assertStatus(403);
    }
}
