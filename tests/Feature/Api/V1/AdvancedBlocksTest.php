<?php

namespace Tests\Feature\Api\V1;

use App\Models\Profile;
use App\Models\ProfileBlock;
use App\Models\ProfileMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvancedBlocksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->user = User::factory()->create();

        $proPlan = \App\Models\Plan::where('code', 'pro')->first();
        if ($proPlan) {
            \App\Models\Subscription::create([
                'user_id' => $this->user->id,
                'plan_id' => $proPlan->id,
                'status' => \App\Enums\SubscriptionStatus::Active,
                'billing_interval' => 'monthly',
            ]);
        }

        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'username' => 'creatorjoe',
            'display_name' => 'Creator Joe',
            'template_id' => 'vcard',
            'version' => 1,
        ]);
    }

    public function test_valid_youtube_video_block_created_and_resolved(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'video',
                'config' => [
                    'provider' => 'youtube',
                    'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                    'title' => 'My Favorite Music Video',
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'type' => 'video',
                    'config' => [
                        'provider' => 'youtube',
                        'video_id' => 'dQw4w9WgXcQ',
                    ],
                ],
            ]);
    }

    public function test_valid_vimeo_video_block_created(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'video',
                'config' => [
                    'provider' => 'vimeo',
                    'url' => 'https://vimeo.com/76979871',
                    'title' => 'The New Vimeo Player',
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'type' => 'video',
                    'config' => [
                        'provider' => 'vimeo',
                        'video_id' => '76979871',
                    ],
                ],
            ]);
    }

    public function test_invalid_video_provider_or_malicious_url_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'video',
                'config' => [
                    'provider' => 'youtube',
                    'url' => 'javascript:alert(1)',
                ],
            ]);

        $response->assertStatus(422);

        $response2 = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'video',
                'config' => [
                    'provider' => 'unsupported_provider',
                    'url' => 'https://dailymotion.com/video/x12345',
                ],
            ]);

        $response2->assertStatus(422);
    }

    public function test_valid_spotify_music_block_created(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'music',
                'config' => [
                    'provider' => 'spotify',
                    'url' => 'https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT',
                    'title' => 'Never Gonna Give You Up',
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'type' => 'music',
                ],
            ]);
    }

    public function test_valid_map_block_created(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'map',
                'config' => [
                    'label' => 'Main Studio',
                    'address' => 'Silicon Valley, CA, USA',
                    'latitude' => 37.3861,
                    'longitude' => -122.0839,
                    'map_provider' => 'google_maps',
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'type' => 'map',
                ],
            ]);
    }

    public function test_invalid_map_coordinates_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'map',
                'config' => [
                    'latitude' => 120.5, // Invalid latitude (> 90)
                    'longitude' => -122.0839,
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_contact_block_created(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'contact',
                'config' => [
                    'title' => 'Send Me a Message',
                    'description' => 'Feel free to reach out for collaborations.',
                    'name_enabled' => true,
                    'email_enabled' => true,
                    'phone_enabled' => false,
                    'message_enabled' => true,
                    'button_label' => 'Submit Message',
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'type' => 'contact',
                ],
            ]);
    }

    public function test_email_phone_whatsapp_blocks_created(): void
    {
        // Email
        $res1 = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'email',
                'config' => [
                    'label' => 'Email Inquiry',
                    'email' => 'joe@example.com',
                    'subject' => 'Project Inquiry',
                ],
            ]);
        $res1->assertStatus(201);

        // Phone
        $res2 = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'phone',
                'config' => [
                    'label' => 'Direct Call',
                    'phone' => '+1 (555) 019-2834',
                ],
            ]);
        $res2->assertStatus(201);

        // WhatsApp
        $res3 = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'whatsapp',
                'config' => [
                    'label' => 'Chat on WhatsApp',
                    'phone' => '+15550192834',
                    'message' => 'Hello Joe!',
                ],
            ]);
        $res3->assertStatus(201);
    }

    public function test_booking_block_allows_only_allowlisted_hosts(): void
    {
        // Allowed: Calendly
        $res1 = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'booking',
                'config' => [
                    'provider' => 'calendly',
                    'url' => 'https://calendly.com/creatorjoe/30min',
                    'title' => 'Book 30-min Consultation',
                ],
            ]);
        $res1->assertStatus(201);

        // Disallowed: untrusted external site
        $res2 = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'booking',
                'config' => [
                    'provider' => 'calendly',
                    'url' => 'https://malicious-site.com/fake-booking',
                ],
            ]);
        $res2->assertStatus(422);
    }

    public function test_faq_block_created_and_validated(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'faq',
                'config' => [
                    'title' => 'FAQ',
                    'items' => [
                        [
                            'id' => 'faq-1',
                            'question' => 'How can I work with you?',
                            'answer' => 'Send me a message using the contact block above.',
                        ],
                    ],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'type' => 'faq',
                ],
            ]);
    }

    public function test_gallery_block_requires_own_media_and_rejects_foreign_media(): void
    {
        $media1 = ProfileMedia::create([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'profile_id' => $this->profile->id,
            'user_id' => $this->user->id,
            'type' => 'block_image',
            'disk' => 'public',
            'path' => 'blocks/test/1.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1000,
        ]);

        $user2 = User::factory()->create();
        $profile2 = Profile::create([
            'user_id' => $user2->id,
            'username' => 'otheruser',
            'version' => 1,
        ]);
        $foreignMedia = ProfileMedia::create([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'profile_id' => $profile2->id,
            'user_id' => $user2->id,
            'type' => 'block_image',
            'disk' => 'public',
            'path' => 'blocks/test/2.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1000,
        ]);

        // Own media: success
        $res1 = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'gallery',
                'config' => [
                    'layout' => 'grid',
                    'media_ids' => [$media1->id],
                ],
            ]);
        $res1->assertStatus(201);

        // Foreign media: rejected
        $res2 = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'gallery',
                'config' => [
                    'layout' => 'grid',
                    'media_ids' => [$media1->id, $foreignMedia->id],
                ],
            ]);
        $res2->assertStatus(422);

        // Duplicate media IDs: rejected
        $res3 = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'gallery',
                'config' => [
                    'layout' => 'grid',
                    'media_ids' => [$media1->id, $media1->id],
                ],
            ]);
        $res3->assertStatus(422);
    }

    public function test_countdown_block_created_and_validated(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'countdown',
                'config' => [
                    'title' => 'Product Launch',
                    'target_date' => '2026-12-31T23:59:59Z',
                    'expired_message' => 'We are live now!',
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'type' => 'countdown',
                ],
            ]);
    }

    public function test_cta_block_created_and_validates_urls(): void
    {
        // Valid HTTPS
        $res1 = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'cta',
                'config' => [
                    'title' => 'Subscribe to Newsletter',
                    'description' => 'Get weekly exclusive updates directly to your inbox.',
                    'button_label' => 'Subscribe Today',
                    'url' => 'https://newsletter.example.com',
                    'style' => 'primary',
                ],
            ]);
        $res1->assertStatus(201);

        // Disallowed javascript: scheme
        $res2 = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'cta',
                'config' => [
                    'title' => 'Exploit',
                    'button_label' => 'Click',
                    'url' => 'javascript:alert(1)',
                ],
            ]);
        $res2->assertStatus(422);
    }

    public function test_public_profile_resolves_gallery_images(): void
    {
        $media = ProfileMedia::create([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'profile_id' => $this->profile->id,
            'user_id' => $this->user->id,
            'type' => 'block_image',
            'disk' => 'public',
            'path' => 'blocks/test/gallery1.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 2048,
            'alt_text' => 'Gallery Photo',
        ]);

        ProfileBlock::create([
            'profile_id' => $this->profile->id,
            'type' => 'gallery',
            'config' => [
                'layout' => 'masonry',
                'media_ids' => [$media->id],
            ],
            'sort_order' => 0,
            'is_visible' => true,
        ]);

        $response = $this->getJson(route('api.v1.public.profile', ['username' => 'creatorjoe']));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'blocks' => [
                        '*' => [
                            'type',
                            'config' => [
                                'images' => [
                                    '*' => [
                                        'id',
                                        'url',
                                        'alt_text',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
    }
}
