<?php

namespace Tests\Feature\Api\V1;

use App\Models\Profile;
use App\Models\ProfileBlock;
use App\Models\ProfileMedia;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->user = User::factory()->create();
        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'username' => 'mediauser',
            'display_name' => 'Media User',
            'template_id' => 'vcard',
            'version' => 1,
        ]);
    }

    public function test_authenticated_user_can_upload_avatar(): void
    {
        $file = UploadedFile::fake()->image('avatar.jpg', 300, 300)->size(500);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.avatar.upload'), [
                'image' => $file,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'type' => 'avatar',
                ],
            ]);

        $mediaId = $response->json('data.id');
        $this->assertDatabaseHas('profile_media', [
            'id' => $mediaId,
            'profile_id' => $this->profile->id,
            'type' => 'avatar',
        ]);

        $freshProfile = $this->profile->fresh();
        $this->assertNotNull($freshProfile->avatar_url);
        $this->assertEquals(2, $freshProfile->version);
    }

    public function test_unauthenticated_user_cannot_upload_avatar(): void
    {
        $file = UploadedFile::fake()->image('avatar.jpg');

        $response = $this->postJson(route('api.v1.profile.avatar.upload'), [
            'image' => $file,
        ]);

        $response->assertStatus(401);
    }

    public function test_invalid_mime_type_rejected(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.avatar.upload'), [
                'image' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                ],
            ]);
    }

    public function test_svg_upload_rejected(): void
    {
        $svgContent = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $file = UploadedFile::fake()->createWithContent('vector.svg', $svgContent);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.avatar.upload'), [
                'image' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                ],
            ]);
    }

    public function test_oversized_file_rejected(): void
    {
        // 6 MB avatar exceeds 5 MB limit
        $file = UploadedFile::fake()->image('large.jpg')->size(6144);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.avatar.upload'), [
                'image' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                ],
            ]);
    }

    public function test_valid_png_and_webp_accepted(): void
    {
        $png = UploadedFile::fake()->image('avatar.png', 200, 200);
        $res1 = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.avatar.upload'), ['image' => $png]);
        $res1->assertStatus(201);

        $webp = UploadedFile::fake()->image('avatar.webp', 200, 200);
        $res2 = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.avatar.upload'), ['image' => $webp]);
        $res2->assertStatus(201);
    }

    public function test_avatar_replacement_deletes_old_file_and_updates_profile_url(): void
    {
        $file1 = UploadedFile::fake()->image('avatar1.jpg', 200, 200);
        $res1 = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.avatar.upload'), ['image' => $file1]);
        $mediaId1 = $res1->json('data.id');

        $file2 = UploadedFile::fake()->image('avatar2.png', 250, 250);
        $res2 = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.avatar.upload'), ['image' => $file2]);
        $mediaId2 = $res2->json('data.id');

        // Old media should be soft-deleted in database
        $this->assertSoftDeleted('profile_media', ['id' => $mediaId1]);
        // New media active
        $this->assertDatabaseHas('profile_media', ['id' => $mediaId2, 'deleted_at' => null]);
    }

    public function test_authenticated_user_can_delete_avatar(): void
    {
        $file = UploadedFile::fake()->image('avatar.jpg');
        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.avatar.upload'), ['image' => $file]);

        $this->assertNotNull($this->profile->fresh()->avatar_url);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson(route('api.v1.profile.avatar.delete'));

        $response->assertStatus(200);
        $this->assertNull($this->profile->fresh()->avatar_url);
    }

    public function test_authenticated_user_can_upload_and_delete_cover(): void
    {
        $file = UploadedFile::fake()->image('cover.jpg', 1200, 400)->size(1024);

        $res1 = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.cover.upload'), ['image' => $file]);

        $res1->assertStatus(201);
        $this->assertNotNull($this->profile->fresh()->cover_url);

        $res2 = $this->actingAs($this->user, 'sanctum')
            ->deleteJson(route('api.v1.profile.cover.delete'));

        $res2->assertStatus(200);
        $this->assertNull($this->profile->fresh()->cover_url);
    }

    public function test_user_cannot_delete_another_users_media(): void
    {
        $user2 = User::factory()->create();
        $file = UploadedFile::fake()->image('item.jpg');

        $res = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.media.upload.image'), ['image' => $file]);
        $mediaId = $res->json('data.id');

        $response = $this->actingAs($user2, 'sanctum')
            ->deleteJson(route('api.v1.media.destroy', ['media' => $mediaId]));

        $response->assertStatus(403);
    }

    public function test_user_cannot_attach_another_users_media_to_image_block(): void
    {
        $user2 = User::factory()->create();
        $profile2 = Profile::create([
            'user_id' => $user2->id,
            'username' => 'user2',
            'version' => 1,
        ]);

        $file = UploadedFile::fake()->image('user2img.jpg');
        $res = $this->actingAs($user2, 'sanctum')
            ->postJson(route('api.v1.media.upload.image'), ['image' => $file]);
        $foreignMediaId = $res->json('data.id');

        // User 1 tries to attach User 2's media ID
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'image',
                'config' => [
                    'media_id' => $foreignMediaId,
                    'alt_text' => 'Stolen Image',
                ],
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                ],
            ]);
    }

    public function test_user_can_create_image_block_with_owned_media(): void
    {
        $file = UploadedFile::fake()->image('myphoto.jpg', 800, 600);
        $res = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.media.upload.image'), [
                'image' => $file,
                'alt_text' => 'My Artwork',
            ]);

        $mediaId = $res->json('data.id');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'image',
                'config' => [
                    'media_id' => $mediaId,
                    'alt_text' => 'My Artwork',
                    'caption' => 'Created in 2026',
                    'link_url' => 'https://artstation.com',
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'type' => 'image',
                ],
            ]);
    }

    public function test_public_profile_serializes_resolved_image_block_url(): void
    {
        $file = UploadedFile::fake()->image('myphoto.jpg', 800, 600);
        $res = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.media.upload.image'), [
                'image' => $file,
                'alt_text' => 'Public Photo',
            ]);
        $mediaId = $res->json('data.id');

        ProfileBlock::create([
            'profile_id' => $this->profile->id,
            'type' => 'image',
            'config' => [
                'media_id' => $mediaId,
                'alt_text' => 'Public Photo',
            ],
            'sort_order' => 0,
            'is_visible' => true,
        ]);

        $response = $this->getJson(route('api.v1.public.profile', ['username' => 'mediauser']));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'username',
                    'blocks' => [
                        '*' => [
                            'id',
                            'type',
                            'config' => [
                                'media_id',
                                'url',
                            ],
                        ],
                    ],
                ],
            ]);

        $this->assertNotEmpty($response->json('data.blocks.0.config.url'));
    }

    public function test_cannot_delete_media_while_set_as_active_avatar(): void
    {
        $file = UploadedFile::fake()->image('avatar.jpg');
        $res = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.avatar.upload'), ['image' => $file]);
        $mediaId = $res->json('data.id');

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson(route('api.v1.media.destroy', ['media' => $mediaId]));

        $response->assertStatus(409);
    }

    public function test_orphan_cleanup_command_removes_old_trashed_records(): void
    {
        $media = ProfileMedia::create([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'profile_id' => $this->profile->id,
            'user_id' => $this->user->id,
            'type' => 'avatar',
            'disk' => 'public',
            'path' => 'profiles/test/old.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
        ]);

        Storage::disk('public')->put('profiles/test/old.jpg', 'fake-bytes');
        $media->delete();

        // Simulate deleted 48 hours ago
        $media->deleted_at = Carbon::now()->subHours(48);
        $media->saveQuietly();

        $this->artisan('media:cleanup-orphans --hours=24')
            ->assertSuccessful();

        $this->assertDatabaseMissing('profile_media', ['id' => $media->id]);
        $this->assertFalse(Storage::disk('public')->exists('profiles/test/old.jpg'));
    }

    public function test_authenticated_user_can_upload_pdf_document(): void
    {
        $file = UploadedFile::fake()->create('company-brochure.pdf', 1024, 'application/pdf');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.media.upload.document'), [
                'document' => $file,
                'title' => 'Corporate Brochure',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'type' => 'document',
                    'mime_type' => 'application/pdf',
                    'alt_text' => 'Corporate Brochure',
                ],
            ]);

        $mediaId = $response->json('data.id');
        $this->assertDatabaseHas('profile_media', [
            'id' => $mediaId,
            'profile_id' => $this->profile->id,
            'type' => 'document',
            'mime_type' => 'application/pdf',
        ]);
    }

    public function test_invalid_document_type_rejected(): void
    {
        $file = UploadedFile::fake()->create('script.exe', 100, 'application/x-msdownload');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.media.upload.document'), [
                'document' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                ],
            ]);
    }
}
