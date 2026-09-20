<?php

namespace Tests\Feature\Api\V1;

use App\Models\Profile;
use App\Models\ProfileMedia;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class PublicProfileSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_public_profile_endpoint_returns_seo_and_og_metadata(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'alexcreative',
            'display_name' => 'Alex Creative',
            'bio' => 'Visual Artist and Director.',
            'seo_title' => 'Alex Creative | Visual Artist',
            'seo_description' => 'Official portfolio and links for Alex.',
            'seo_keywords' => ['art', 'visual', 'film'],
            'og_title' => 'Alex Creative - Art & Film',
            'og_description' => 'Explore the creative universe of Alex.',
            'indexable' => true,
        ]);

        $media = ProfileMedia::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'type' => 'block_image',
            'disk' => 'public',
            'path' => 'profiles/' . $profile->id . '/og.png',
            'mime_type' => 'image/png',
            'size' => 2048,
        ]);

        $profile->update(['og_image_media_id' => $media->id]);

        $response = $this->getJson('/api/v1/p/alexcreative');

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('data.seo_title', 'Alex Creative | Visual Artist')
            ->assertJsonPath('data.seo_description', 'Official portfolio and links for Alex.')
            ->assertJsonPath('data.seo_keywords', ['art', 'visual', 'film'])
            ->assertJsonPath('data.og_title', 'Alex Creative - Art & Film')
            ->assertJsonPath('data.og_description', 'Explore the creative universe of Alex.')
            ->assertJsonPath('data.indexable', true)
            ->assertJsonPath('data.og_image_url', $media->url);
    }

    public function test_internal_sitemap_endpoint_returns_indexable_public_profiles(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        // 1. Public & Indexable
        Profile::create([
            'user_id' => $user1->id,
            'username' => 'user_public_indexable',
            'display_name' => 'User 1',
            'is_public' => true,
            'indexable' => true,
        ]);

        // 2. Public but Non-Indexable (should be omitted)
        Profile::create([
            'user_id' => $user2->id,
            'username' => 'user_public_noindex',
            'display_name' => 'User 2',
            'is_public' => true,
            'indexable' => false,
        ]);

        // 3. Private (should be omitted)
        Profile::create([
            'user_id' => $user3->id,
            'username' => 'user_private',
            'display_name' => 'User 3',
            'is_public' => false,
            'indexable' => true,
        ]);

        $response = $this->getJson('/api/v1/internal/sitemap-profiles');

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.username', 'user_public_indexable');
    }
}
