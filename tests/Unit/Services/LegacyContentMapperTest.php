<?php

namespace Tests\Unit\Services;

use App\Models\Profile;
use App\Models\ProfileBlock;
use App\Models\User;
use App\Services\LegacyContentMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Legacy custom_options → typed content (plan §4.1 mapping table).
 * The same fixtures are used by frontend/src/tests/content-legacy.test.ts.
 */
class LegacyContentMapperTest extends TestCase
{
    use RefreshDatabase;

    private function profile(string $template, array $customOptions, array $attributes = []): Profile
    {
        $user = User::factory()->create();

        return Profile::create(array_merge([
            'user_id' => $user->id,
            'username' => 'card' . $user->id,
            'display_name' => 'Owner Name',
            'bio' => null,
            'template_id' => $template,
            'theme_tokens' => ['color_accent' => '#123456', 'custom_options' => $customOptions],
            'is_public' => true,
            'version' => 1,
        ], $attributes));
    }

    public function test_vcard_options_map_into_every_section(): void
    {
        $profile = $this->profile('vcard', [
            'display_name_override' => 'Studio Noor',
            'tagline_override' => 'Calm homes in Bengaluru.',
            'custom_avatar_url' => 'https://cdn.example.com/a.jpg',
            'banner_images' => ['https://cdn.example.com/c1.jpg', 'javascript:alert(1)', 'https://cdn.example.com/c2.jpg'],
            'category_badge_text' => 'Interior design',
            'show_meta_verified' => false,
            'whatsapp_number' => '+91 98450 12345',
            'payment_phone' => '+91 98450 99999',
            'address_text' => '12 MG Road, Bengaluru',
            'map_url' => 'maps.google.com/?q=studio',
            'website_url' => 'studionoor.in',
            'social_urls' => [
                'instagram' => 'https://instagram.com/noor',
                'twitter' => 'https://x.com/noor',
                'youtube' => 'https://youtube.com/@noor',
                'facebook' => '',
            ],
            'active_action_icons' => ['youtube' => false, 'qr' => false],
            'services' => [['id' => 'svc1', 'name' => 'Consultation', 'price' => '₹2,500', 'duration' => '90 min']],
            'products' => [['name' => 'Cushion', 'price' => '₹1,800', 'buy_url' => 'javascript:void(0)']],
            'services_enquiry_template' => 'Hi! I want {service}.',
            'upi_id' => 'noor@okhdfc',
            'bank_holder_name' => 'Studio Noor',
            'bank_ifsc' => 'hdfc0001234',
            'gallery_enabled' => false,
            'gallery_title' => 'Recent work',
            'gallery_images' => ['/storage/media/g1.jpg'],
            'google_review_url' => 'https://g.page/r/noor/review',
            'review_btn_enabled' => true,
            'banner_transition' => false,
            'primary_button_color' => '#00c853',
        ]);

        $result = app(LegacyContentMapper::class)->map($profile);
        $c = $result['content'];

        $this->assertSame('Studio Noor', $c['identity']['name']);
        $this->assertSame('Calm homes in Bengaluru.', $c['identity']['bio']);
        $this->assertSame('https://cdn.example.com/a.jpg', $c['identity']['avatar_url']);
        $this->assertSame(['https://cdn.example.com/c1.jpg', 'https://cdn.example.com/c2.jpg'], $c['identity']['cover_images']);
        $this->assertSame('Interior design', $c['identity']['category_label']);
        $this->assertFalse($c['identity']['verified_badge']);

        $this->assertSame('+91 98450 99999', $c['contact']['phone']);
        $this->assertSame('+91 98450 12345', $c['contact']['whatsapp']);
        $this->assertSame('https://studionoor.in', $c['contact']['website']);
        $this->assertSame('https://maps.google.com/?q=studio', $c['contact']['map_url']);
        $this->assertSame('12 MG Road, Bengaluru', $c['contact']['address']);

        // Empty URLs and toggled-off icons are not carried over; twitter becomes x.
        $this->assertSame(['instagram', 'x'], array_column($c['socials'], 'platform'));

        $this->assertSame('Consultation', $c['catalog']['services'][0]['name']);
        $this->assertSame('90 min', $c['catalog']['services'][0]['duration']);
        $this->assertSame('', $c['catalog']['products'][0]['buy_url']);
        $this->assertSame('Hi! I want {item}.', $c['catalog']['enquiry_message']);

        $this->assertSame('noor@okhdfc', $c['payments']['upi_id']);
        $this->assertSame('HDFC0001234', $c['payments']['bank']['ifsc']);

        $this->assertSame(['/storage/media/g1.jpg'], $c['gallery']['images']);
        $layout = array_column($c['layout'], 'visible', 'key');
        $this->assertFalse($layout['gallery']);
        $this->assertTrue($layout['review']);

        $this->assertFalse($c['actions']['qr']);
        $this->assertSame('https://g.page/r/noor/review', $c['actions']['review_url']);
        $this->assertSame(['cover_slideshow' => false], $result['template_style']);
        $this->assertSame([], $result['unmapped_keys']);
    }

    public function test_botanical_options_map_quick_actions_links_and_style(): void
    {
        $profile = $this->profile('botanical', [
            'executive_role' => 'Managing Partner',
            'company_name' => 'Mehta & Iyer Advisory',
            'bio_override' => 'Helping family businesses plan.',
            'profile_image_url' => 'https://cdn.example.com/rohan.jpg',
            'custom_avatar_url' => 'https://cdn.example.com/old.jpg',
            'trust_badges' => [['id' => 'b1', 'text' => 'Verified business'], ['id' => 'b2', 'text' => 'Hidden', 'enabled' => false]],
            'quick_actions' => [
                'phone' => ['number' => '+91 22 4000 1000'],
                'email' => ['address' => 'rohan@mehta.in', 'enabled' => false],
                'website' => ['url' => 'https://mehta.in'],
            ],
            'social_links' => [
                ['platform' => 'LinkedIn', 'url' => 'https://linkedin.com/in/rohan'],
                ['platform' => 'custom', 'url' => 'https://youtube.com/@mehta'],
                ['platform' => 'instagram', 'url' => 'https://instagram.com/x', 'enabled' => false],
            ],
            'content_links' => [
                ['headline' => 'Book a consultation', 'url' => 'https://cal.com/rohan', 'featured' => true, 'icon' => 'Calendar'],
                ['headline' => 'Bad', 'url' => 'javascript:alert(1)'],
            ],
            'document_block' => ['title' => 'Company profile', 'file_url' => 'https://cdn.example.com/p.pdf', 'file_size' => 2400000],
            'color_palette' => 'enterprise-navy',
            'bg_style' => 'bg-topo',
            'show_qr' => false,
        ]);

        $result = app(LegacyContentMapper::class)->map($profile);
        $c = $result['content'];

        $this->assertSame('Managing Partner', $c['identity']['role']);
        $this->assertSame('Mehta & Iyer Advisory', $c['identity']['company']);
        $this->assertSame('https://cdn.example.com/rohan.jpg', $c['identity']['avatar_url']);
        $this->assertSame(['Verified business'], $c['identity']['badges']);
        $this->assertTrue($c['identity']['verified_badge']);

        $this->assertSame('+91 22 4000 1000', $c['contact']['phone']);
        $this->assertSame('', $c['contact']['email'], 'a button the owner switched off stays off');
        $this->assertSame('', $c['contact']['whatsapp'], 'Botanical never fell back to the phone number');

        $this->assertSame(['linkedin', 'youtube'], array_column($c['socials'], 'platform'));
        $this->assertCount(1, $c['links']);
        $this->assertTrue($c['links'][0]['featured']);
        $this->assertSame('calendar', $c['links'][0]['icon']);
        $this->assertSame('2.3 MB', $c['documents'][0]['size_label']); // 2,400,000 bytes
        $this->assertFalse($c['actions']['qr']);
        $this->assertSame(['palette' => 'navy', 'background' => 'topo'], $result['template_style']);
    }

    public function test_placeholder_bio_is_dropped_and_contact_blocks_are_lifted(): void
    {
        $profile = $this->profile('vcard', [], ['bio' => 'Welcome to my page!']);
        $email = ProfileBlock::create([
            'profile_id' => $profile->id, 'type' => 'email', 'sort_order' => 1,
            'config' => ['label' => 'Mail', 'email' => 'hi@noor.in'], 'is_visible' => true, 'version' => 1,
        ]);
        ProfileBlock::create([
            'profile_id' => $profile->id, 'type' => 'social', 'sort_order' => 2,
            'config' => ['platform' => 'github', 'url' => 'https://github.com/noor'], 'is_visible' => true, 'version' => 1,
        ]);

        $result = app(LegacyContentMapper::class)->map($profile);

        $this->assertSame('', $result['content']['identity']['bio']);
        $this->assertSame('hi@noor.in', $result['content']['contact']['email']);
        $this->assertSame('github', $result['content']['socials'][0]['platform']);
        $this->assertCount(2, $result['lifted_block_ids']);
        $this->assertContains($email->id, $result['lifted_block_ids']);
    }

    public function test_unknown_keys_are_reported(): void
    {
        $profile = $this->profile('vcard', ['mystery_widget' => true, 'upi_id' => 'a@ok']);

        $this->assertSame(['mystery_widget'], app(LegacyContentMapper::class)->map($profile)['unmapped_keys']);
    }
}
