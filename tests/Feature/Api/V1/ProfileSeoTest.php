<?php

namespace Tests\Feature\Api\V1;

use App\Models\Profile;
use App\Models\ProfileMedia;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ProfileSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_unauthenticated_request_cannot_access_seo_settings(): void
    {
        $this->getJson('/api/v1/profile/seo')
            ->assertStatus(Response::HTTP_UNAUTHORIZED);

        $this->patchJson('/api/v1/profile/seo', ['seo_title' => 'Test'])
            ->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    public function test_authenticated_user_can_retrieve_default_seo_settings(): void
    {
        $user = User::factory()->create();
        Profile::create([
            'user_id' => $user->id,
            'username' => 'johndoe',
            'display_name' => 'John Doe',
            'bio' => 'Designer & developer.',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/profile/seo');

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.indexable', true)
            ->assertJsonPath('data.seo_keywords', []);
    }

    public function test_authenticated_user_can_update_seo_settings(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'johndoe',
            'display_name' => 'John Doe',
            'bio' => 'Designer & developer.',
        ]);

        $media = ProfileMedia::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'type' => 'block_image',
            'disk' => 'public',
            'path' => 'profiles/' . $profile->id . '/og.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
        ]);

        $payload = [
            'seo_title' => 'John Doe | Creative Designer',
            'seo_description' => 'Portfolio of designer and engineer John Doe.',
            'seo_keywords' => ['design', 'engineering', 'portfolio'],
            'og_title' => 'Explore John Doe’s Work',
            'og_description' => 'Links, projects, and creative updates.',
            'og_image_media_id' => $media->id,
            'indexable' => true,
        ];

        $response = $this->actingAs($user)->patchJson('/api/v1/profile/seo', $payload);

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('data.seo_title', 'John Doe | Creative Designer')
            ->assertJsonPath('data.seo_description', 'Portfolio of designer and engineer John Doe.')
            ->assertJsonPath('data.seo_keywords', ['design', 'engineering', 'portfolio'])
            ->assertJsonPath('data.og_title', 'Explore John Doe’s Work')
            ->assertJsonPath('data.og_description', 'Links, projects, and creative updates.')
            ->assertJsonPath('data.og_image_media_id', $media->id)
            ->assertJsonPath('data.indexable', true);

        $this->assertDatabaseHas('profiles', [
            'id' => $profile->id,
            'seo_title' => 'John Doe | Creative Designer',
            'og_image_media_id' => $media->id,
            'indexable' => 1,
        ]);
    }

    public function test_seo_fields_strip_html_and_sanitize_plain_text(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'johndoe',
            'display_name' => 'John Doe',
        ]);

        $payload = [
            'seo_title' => '<h1>John Doe</h1><script>alert(1)</script>',
            'seo_description' => '<b>Bold description</b> with <a href="#">link</a>',
            'og_title' => '<script>steal()</script>OG Clean Title',
            'og_description' => '<div>Clean OG description</div>',
            'seo_keywords' => ['<i>design</i>', '<b>tag</b>'],
        ];

        $response = $this->actingAs($user)->patchJson('/api/v1/profile/seo', $payload);

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('data.seo_title', 'John Doealert(1)')
            ->assertJsonPath('data.seo_description', 'Bold description with link')
            ->assertJsonPath('data.og_title', 'steal()OG Clean Title')
            ->assertJsonPath('data.og_description', 'Clean OG description')
            ->assertJsonPath('data.seo_keywords', ['design', 'tag']);
    }

    public function test_foreign_media_id_rejected_with_422(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'johndoe',
            'display_name' => 'John Doe',
        ]);
        $otherProfile = Profile::create([
            'user_id' => $otherUser->id,
            'username' => 'otheruser',
            'display_name' => 'Other User',
        ]);

        $otherMedia = ProfileMedia::create([
            'profile_id' => $otherProfile->id,
            'user_id' => $otherUser->id,
            'type' => 'block_image',
            'disk' => 'public',
            'path' => 'profiles/' . $otherProfile->id . '/og.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
        ]);

        $response = $this->actingAs($user)->patchJson('/api/v1/profile/seo', [
            'og_image_media_id' => $otherMedia->id,
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['og_image_media_id']]]);
    }

    public function test_seo_validation_enforces_limits(): void
    {
        $user = User::factory()->create();
        Profile::create([
            'user_id' => $user->id,
            'username' => 'johndoe',
            'display_name' => 'John Doe',
        ]);

        // Exceed seo_title (max 70) and seo_description (max 160)
        $response = $this->actingAs($user)->patchJson('/api/v1/profile/seo', [
            'seo_title' => str_repeat('a', 71),
            'seo_description' => str_repeat('b', 161),
            'og_title' => str_repeat('c', 96),
            'og_description' => str_repeat('d', 201),
            'seo_keywords' => array_fill(0, 11, 'tag'),
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => [
                'seo_title',
                'seo_description',
                'og_title',
                'og_description',
                'seo_keywords',
            ]]]);
    }

    public function test_indexable_flag_can_be_toggled_off(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'johndoe',
            'display_name' => 'John Doe',
            'indexable' => true,
        ]);

        $response = $this->actingAs($user)->patchJson('/api/v1/profile/seo', [
            'indexable' => false,
        ]);

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('data.indexable', false);

        $this->assertDatabaseHas('profiles', [
            'id' => $profile->id,
            'indexable' => 0,
        ]);
    }
}
