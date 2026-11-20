<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\RevalidatePublicProfile;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 0 hardening of PUT /profile/appearance (docs/editor-redesign-plan.md §4.4).
 */
class AppearanceSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Profile $profile;

    private const CORE = [
        'color_background' => '#f0f4f8',
        'color_surface' => '#ffffff',
        'color_text_primary' => '#1e293b',
        'color_text_secondary' => '#64748b',
        'color_accent' => '#f97316',
        'font_family' => 'poppins',
        'button_radius' => 'large',
        'button_style' => 'solid',
        'animation' => 'none',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'username' => 'safecard',
            'display_name' => 'Safe Card',
            'template_id' => 'vcard',
            'is_public' => true,
            'version' => 1,
        ]);
    }

    private function saveAppearance(array $customOptions, int $version = 1)
    {
        return $this->actingAs($this->user)->putJson('/api/v1/profile/appearance', [
            'template_id' => 'vcard',
            'version' => $version,
            'theme_tokens' => self::CORE + ['custom_options' => $customOptions],
        ]);
    }

    public function test_javascript_urls_are_never_stored_or_served_publicly(): void
    {
        $this->saveAppearance([
            'map_url' => 'javascript:alert(document.cookie)',
            'social_urls' => ['instagram' => 'javascript:alert(1)'],
            'google_review_url' => 'https://g.page/r/review',
        ])->assertStatus(200);

        $stored = $this->profile->fresh()->theme_tokens['custom_options'];
        $this->assertSame('', $stored['map_url']);
        $this->assertSame('', $stored['social_urls']['instagram']);
        $this->assertSame('https://g.page/r/review', $stored['google_review_url']);

        $public = $this->getJson('/api/v1/p/safecard')->assertStatus(200)->getContent();
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $public);
    }

    public function test_unknown_top_level_keys_and_bad_colours_are_dropped(): void
    {
        $this->actingAs($this->user)->putJson('/api/v1/profile/appearance', [
            'template_id' => 'vcard',
            'version' => 1,
            'theme_tokens' => self::CORE + [
                'raw_css' => 'body{display:none}',
                'custom_options' => ['primary_button_color' => 'red;background:url(//evil)'],
            ],
        ])->assertStatus(200);

        $tokens = $this->profile->fresh()->theme_tokens;
        $this->assertArrayNotHasKey('raw_css', $tokens);
        $this->assertArrayNotHasKey('primary_button_color', $tokens['custom_options']);
    }

    public function test_avatar_and_cover_are_only_synced_from_web_urls(): void
    {
        $this->saveAppearance([
            'profile_image_url' => 'javascript:alert(1)',
            'banner_images' => ['data:image/png;base64,AAAA'],
        ])->assertStatus(200);

        $fresh = $this->profile->fresh();
        $this->assertNull($fresh->avatar_url);
        $this->assertNull($fresh->cover_url);

        $this->saveAppearance([
            'profile_image_url' => 'https://cdn.example/avatar.jpg',
            'banner_images' => ['https://cdn.example/cover.jpg'],
        ], 2)->assertStatus(200);

        $fresh = $this->profile->fresh();
        $this->assertSame('https://cdn.example/avatar.jpg', $fresh->avatar_url);
        $this->assertSame('https://cdn.example/cover.jpg', $fresh->cover_url);
    }

    public function test_stale_version_returns_409_with_current_version(): void
    {
        $this->saveAppearance([], 1)->assertStatus(200);

        $this->saveAppearance([], 1)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT')
            ->assertJsonPath('error.current_version', 2);
    }

    public function test_saving_schedules_public_page_revalidation(): void
    {
        Bus::fake();

        $this->saveAppearance([])->assertStatus(200);

        Bus::assertDispatchedAfterResponse(
            RevalidatePublicProfile::class,
            fn (RevalidatePublicProfile $job) => $job->username === 'safecard'
        );
    }

    public function test_revalidation_job_calls_the_frontend_with_the_secret(): void
    {
        config([
            'services.next.revalidate_secret' => 'test-revalidate-secret',
            'app.frontend_url' => 'https://cards.example',
        ]);
        Http::fake(['cards.example/*' => Http::response(['success' => true])]);

        (new RevalidatePublicProfile('safecard'))->handle();

        Http::assertSent(fn ($request) => $request->url() === 'https://cards.example/api/revalidate'
            && $request->hasHeader('x-revalidate-secret', 'test-revalidate-secret')
            && $request['username'] === 'safecard');
    }

    public function test_revalidation_job_does_nothing_without_a_secret(): void
    {
        config(['services.next.revalidate_secret' => '']);
        Http::fake();

        (new RevalidatePublicProfile('safecard'))->handle();

        Http::assertNothingSent();
    }

    public function test_public_registration_can_be_disabled_by_config(): void
    {
        config(['app.allow_public_registration' => false]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'New Person',
            'email' => 'new@example.com',
            'username' => 'newperson',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ])->assertStatus(403)
            ->assertJsonPath('error.code', 'PUBLIC_REGISTRATION_DISABLED');
    }

    public function test_system_domains_cannot_be_claimed_in_any_case(): void
    {
        foreach (['digicardo.in', 'WWW.Digicardo.in', 'Digicardo.app', 'api.digicardo.app'] as $domain) {
            $this->actingAs($this->user)
                ->postJson('/api/v1/profile/domains', ['domain' => $domain])
                ->assertStatus(422);
        }
    }
}
