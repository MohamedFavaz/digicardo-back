<?php

namespace Tests\Feature\Api\V1;

use App\Models\Profile;
use App\Models\ProfileBlock;
use App\Models\ProfileMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /profile/editor and PATCH /profile/content (ADR-030, plan §4.2).
 */
class ProfileContentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'username' => 'aisha',
            'display_name' => 'Aisha Rahman',
            'bio' => 'Calm, practical homes.',
            'avatar_url' => 'https://cdn.example.com/legacy-avatar.jpg',
            'template_id' => 'vcard',
            'theme_tokens' => [
                'color_accent' => '#0F766E',
                'custom_options' => [
                    'whatsapp_number' => '+91 98450 12345',
                    'social_urls' => ['instagram' => 'https://instagram.com/studionoor'],
                    'services' => [['id' => 's1', 'name' => 'Home consultation', 'price' => '₹2,500']],
                ],
            ],
            'is_public' => true,
            'version' => 3,
        ]);
    }

    private function patchContent(array $sections, int $version = 3)
    {
        return $this->actingAs($this->user)->patchJson('/api/v1/profile/content', [
            'version' => $version,
            'sections' => $sections,
        ]);
    }

    public function test_editor_endpoint_loads_everything_in_one_call(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/profile/editor')->assertOk();

        $response->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.template_id', 'vcard')
            ->assertJsonPath('data.content_stored', false)
            ->assertJsonPath('data.content.identity.name', 'Aisha Rahman')
            ->assertJsonPath('data.content.contact.whatsapp', '+91 98450 12345')
            ->assertJsonPath('data.content.socials.0.platform', 'instagram')
            ->assertJsonPath('data.content.catalog.services.0.name', 'Home consultation')
            ->assertJsonPath('data.templates.0.id', 'vcard')
            ->assertJsonPath('data.templates.0.name', 'Classic');

        $this->assertNotEmpty($response->json('data.templates.1.presets'));
    }

    public function test_editor_requires_authentication(): void
    {
        $this->getJson('/api/v1/profile/editor')->assertStatus(401);
        $this->patchJson('/api/v1/profile/content', ['version' => 1, 'sections' => ['contact' => []]])->assertStatus(401);
    }

    public function test_saving_one_part_keeps_the_others_and_syncs_profile_columns(): void
    {
        $response = $this->patchContent([
            'identity' => [
                'name' => 'Aisha R.',
                'role' => 'Interior Designer',
                'company' => 'Studio Noor',
                'bio' => '',
                'avatar_url' => 'https://cdn.example.com/legacy-avatar.jpg',
                'cover_images' => [],
                'badges' => ['Since 2015'],
                'verified_badge' => true,
            ],
        ])->assertOk();

        $response->assertJsonPath('data.version', 4)
            ->assertJsonPath('data.content.identity.role', 'Interior Designer')
            // untouched parts came over from the legacy data
            ->assertJsonPath('data.content.contact.whatsapp', '+91 98450 12345')
            ->assertJsonPath('data.content.catalog.services.0.price', '₹2,500');

        $fresh = $this->profile->fresh();
        $this->assertSame('Aisha R.', $fresh->display_name);
        $this->assertNull($fresh->bio);
        $this->assertSame(4, $fresh->version);
        $this->assertIsArray($fresh->content);
        $this->assertSame('#0F766E', $fresh->theme_tokens_legacy['color_accent']);
        $this->assertTrue($fresh->theme_tokens['template_style']['cover_slideshow']);
    }

    public function test_first_save_hides_contact_blocks_that_moved_into_the_content(): void
    {
        $phone = ProfileBlock::create([
            'profile_id' => $this->profile->id,
            'type' => 'phone',
            'sort_order' => 1,
            'config' => ['label' => 'Office', 'phone' => '+91 80 4000 1000'],
            'is_visible' => true,
            'version' => 1,
        ]);
        $text = ProfileBlock::create([
            'profile_id' => $this->profile->id,
            'type' => 'text',
            'sort_order' => 2,
            'config' => ['content' => 'Hello'],
            'is_visible' => true,
            'version' => 1,
        ]);

        $this->patchContent(['actions' => ['save_contact' => true, 'share' => true, 'qr' => false]])
            ->assertOk()
            ->assertJsonPath('data.content.contact.phone', '+91 80 4000 1000')
            ->assertJsonPath('data.content.actions.qr', false);

        $this->assertFalse((bool) $phone->fresh()->is_visible);
        $this->assertTrue((bool) $text->fresh()->is_visible);
    }

    public function test_stale_version_returns_409_with_current_version(): void
    {
        $this->patchContent(['contact' => ['phone' => '+91 98450 00000']], 2)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT')
            ->assertJsonPath('error.current_version', 3);

        $this->assertNull($this->profile->fresh()->content);
    }

    public function test_unsafe_and_malformed_values_are_rejected_with_friendly_messages(): void
    {
        $this->patchContent([
            'contact' => ['website' => 'javascript:alert(1)', 'phone' => '12', 'email' => 'not-an-email'],
        ])->assertStatus(422)->assertJsonValidationErrors([
            'sections.contact.website',
            'sections.contact.phone',
            'sections.contact.email',
        ], 'error.details');

        $this->patchContent([
            'payments' => ['upi_id' => 'not a upi', 'bank' => ['ifsc' => 'HDFC123']],
        ])->assertStatus(422)->assertJsonValidationErrors([
            'sections.payments.upi_id',
            'sections.payments.bank.ifsc',
        ], 'error.details');

        $this->patchContent([
            'socials' => [['id' => 'a', 'platform' => 'myspace', 'url' => 'https://myspace.com/x']],
        ])->assertStatus(422)->assertJsonValidationErrors(['sections.socials.0.platform'], 'error.details');

        $this->assertNull($this->profile->fresh()->content);
    }

    public function test_unknown_parts_are_rejected(): void
    {
        $this->patchContent(['theme' => ['color' => '#000000']])->assertStatus(422);
    }

    public function test_ifsc_is_uppercased_and_upi_accepted(): void
    {
        $this->patchContent([
            'payments' => [
                'upi_id' => 'studionoor@okhdfc',
                'bank' => ['holder' => 'Studio Noor', 'account_number' => '50100123456789', 'ifsc' => 'hdfc0001234'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.content.payments.bank.ifsc', 'HDFC0001234')
            ->assertJsonPath('data.content.payments.upi_id', 'studionoor@okhdfc');
    }

    public function test_images_must_be_uploaded_by_the_owner(): void
    {
        $this->patchContent(['gallery' => ['title' => 'Work', 'images' => ['https://evil.example.com/a.jpg']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sections.gallery.images.0'], 'error.details');

        $media = ProfileMedia::create([
            'profile_id' => $this->profile->id,
            'user_id' => $this->user->id,
            'type' => 'image',
            'disk' => 'public',
            'path' => 'media/gallery-1.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1000,
        ]);

        $this->patchContent(['gallery' => ['title' => 'Work', 'images' => [$media->url]]])
            ->assertOk()
            ->assertJsonPath('data.content.gallery.images.0', $media->url);
    }

    public function test_services_can_have_an_owner_uploaded_photo(): void
    {
        $service = ['id' => 'svc1', 'name' => 'Home visit', 'description' => '', 'price' => '', 'duration' => ''];
        $catalog = fn (array $svc) => ['catalog' => ['services_label' => 'Services', 'products_label' => 'Shop', 'services' => [$svc], 'products' => [], 'enquiry_message' => '']];

        $this->patchContent($catalog($service + ['image_url' => 'https://evil.example.com/a.jpg']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sections.catalog.services.0.image_url'], 'error.details');

        $media = ProfileMedia::create([
            'profile_id' => $this->profile->id,
            'user_id' => $this->user->id,
            'type' => 'image',
            'disk' => 'public',
            'path' => 'media/service-1.png',
            'mime_type' => 'image/png',
            'size' => 1000,
        ]);

        $version = $this->patchContent($catalog($service + ['image_url' => $media->url]))
            ->assertOk()
            ->assertJsonPath('data.content.catalog.services.0.image_url', $media->url)
            ->json('data.version');

        // Services saved before photos existed still load, with an empty photo.
        $this->patchContent($catalog($service), $version)
            ->assertOk()
            ->assertJsonPath('data.content.catalog.services.0.image_url', '');
    }

    public function test_list_limits_are_enforced(): void
    {
        $socials = [];
        for ($i = 0; $i < 26; $i++) {
            $socials[] = ['id' => "s{$i}", 'platform' => 'website', 'url' => "https://example.com/{$i}"];
        }

        $this->patchContent(['socials' => $socials])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sections.socials'], 'error.details');
    }

    public function test_layout_controls_order_and_visibility(): void
    {
        $this->patchContent([
            'layout' => [
                ['key' => 'gallery', 'visible' => true],
                ['key' => 'services', 'visible' => false],
            ],
        ])->assertOk()
            ->assertJsonPath('data.content.layout.0.key', 'gallery')
            ->assertJsonPath('data.content.layout.1.key', 'services')
            ->assertJsonPath('data.content.layout.1.visible', false)
            // sections that were not sent are appended so nothing disappears
            ->assertJsonCount(8, 'data.content.layout');
    }

    public function test_design_saves_do_not_overwrite_a_converted_cards_identity(): void
    {
        $this->patchContent(['identity' => ['name' => 'Aisha R.', 'verified_badge' => true]])->assertOk();

        $this->actingAs($this->user)->putJson('/api/v1/profile/appearance', [
            'template_id' => 'botanical',
            'version' => 4,
            'theme_tokens' => [
                'color_background' => '#F2F5F1', 'color_surface' => '#FFFFFF', 'color_text_primary' => '#1B4332',
                'color_text_secondary' => '#2D6A4F', 'color_accent' => '#2D6A4F', 'font_family' => 'inter',
                'button_radius' => 'large', 'button_style' => 'solid', 'animation' => 'fade',
                'custom_options' => ['display_name_override' => 'Stale legacy name'],
            ],
        ])->assertOk();

        $this->assertSame('Aisha R.', $this->profile->fresh()->display_name);
    }

    public function test_public_card_serves_the_saved_content(): void
    {
        $this->getJson('/api/v1/p/aisha')->assertOk()->assertJsonPath('data.content', null);

        $this->patchContent(['contact' => ['phone' => '+91 98450 12345', 'website' => 'https://studionoor.in']])->assertOk();

        $this->getJson('/api/v1/p/aisha')
            ->assertOk()
            ->assertJsonPath('data.content.contact.website', 'https://studionoor.in')
            ->assertJsonPath('data.content.socials.0.url', 'https://instagram.com/studionoor');
    }

    public function test_saving_on_an_unmigrated_database_says_what_to_do(): void
    {
        // Simulate a database where the content migration hasn't been run yet.
        \Illuminate\Support\Facades\Schema::table('profiles', function ($table) {
            $table->dropColumn('theme_tokens_legacy');
        });

        $this->patchContent(['identity' => ['name' => 'Aisha R', 'role' => '', 'company' => '', 'bio' => '', 'avatar' => null, 'cover' => null, 'verified' => false, 'tagline' => '']])
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'DATABASE_OUTDATED');
    }
}
