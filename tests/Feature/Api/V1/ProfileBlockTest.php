<?php

namespace Tests\Feature\Api\V1;

use App\Models\Profile;
use App\Models\ProfileBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProfileBlockTest extends TestCase
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
            'username' => 'blockmaster',
            'display_name' => 'Block Master',
            'version' => 1,
        ]);
    }

    public function test_authenticated_user_can_create_link_block(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'link',
                'config' => [
                    'title' => 'My Portfolio',
                    'url' => 'https://portfolio.example.com',
                    'icon' => 'globe',
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'profile_id' => $this->profile->id,
                    'type' => 'link',
                    'sort_order' => 0,
                    'is_visible' => true,
                    'config' => [
                        'title' => 'My Portfolio',
                        'url' => 'https://portfolio.example.com',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('profile_blocks', [
            'profile_id' => $this->profile->id,
            'type' => 'link',
        ]);
    }

    public function test_authenticated_user_can_create_heading_block(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'heading',
                'config' => [
                    'text' => 'My Work & Projects',
                    'level' => 'h2',
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'type' => 'heading',
                    'config' => [
                        'text' => 'My Work & Projects',
                        'level' => 'h2',
                    ],
                ],
            ]);
    }

    public function test_authenticated_user_can_create_text_block(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'text',
                'config' => [
                    'content' => 'Welcome to my official profile page.',
                    'align' => 'center',
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'type' => 'text',
                    'config' => [
                        'content' => 'Welcome to my official profile page.',
                        'align' => 'center',
                    ],
                ],
            ]);
    }

    public function test_authenticated_user_can_create_divider_block(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'divider',
                'config' => [
                    'style' => 'line',
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'type' => 'divider',
                    'config' => [
                        'style' => 'line',
                    ],
                ],
            ]);
    }

    public function test_authenticated_user_can_create_social_block(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'social',
                'config' => [
                    'platform' => 'github',
                    'url' => 'https://github.com/myusername',
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'type' => 'social',
                    'config' => [
                        'platform' => 'github',
                        'url' => 'https://github.com/myusername',
                    ],
                ],
            ]);
    }

    public function test_unknown_block_type_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'unsupported_custom_embed',
                'config' => ['foo' => 'bar'],
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                ],
            ]);
    }

    public function test_invalid_block_data_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'link',
                'config' => [
                    'title' => '', // missing title
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_invalid_url_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'link',
                'config' => [
                    'title' => 'Invalid Link',
                    'url' => 'not-a-valid-url',
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_javascript_url_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'link',
                'config' => [
                    'title' => 'XSS Attack',
                    'url' => 'javascript:alert(document.cookie)',
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_data_url_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'link',
                'config' => [
                    'title' => 'Data URI',
                    'url' => 'data:text/html,<script>alert(1)</script>',
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_unknown_social_platform_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'social',
                'config' => [
                    'platform' => 'unsupported_platform',
                    'url' => 'https://example.com',
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_unauthenticated_user_cannot_manage_blocks(): void
    {
        $response = $this->getJson(route('api.v1.profile.blocks.index'));
        $response->assertStatus(401);
    }

    public function test_user_cannot_modify_another_users_block(): void
    {
        $otherUser = User::factory()->create();
        $otherProfile = Profile::create([
            'user_id' => $otherUser->id,
            'username' => 'otheruser',
        ]);
        $block = ProfileBlock::create([
            'profile_id' => $otherProfile->id,
            'type' => 'link',
            'config' => ['title' => 'Other', 'url' => 'https://other.com'],
            'version' => 1,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson(route('api.v1.profile.blocks.update', ['block' => $block->id]), [
                'version' => 1,
                'config' => ['title' => 'Hacked', 'url' => 'https://hacked.com'],
            ]);

        $response->assertStatus(403);
    }

    public function test_user_cannot_delete_another_users_block(): void
    {
        $otherUser = User::factory()->create();
        $otherProfile = Profile::create([
            'user_id' => $otherUser->id,
            'username' => 'otheruser_del',
        ]);
        $block = ProfileBlock::create([
            'profile_id' => $otherProfile->id,
            'type' => 'link',
            'config' => ['title' => 'Other', 'url' => 'https://other.com'],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson(route('api.v1.profile.blocks.destroy', ['block' => $block->id]));

        $response->assertStatus(403);
    }

    public function test_user_cannot_reorder_another_users_block(): void
    {
        $otherUser = User::factory()->create();
        $otherProfile = Profile::create([
            'user_id' => $otherUser->id,
            'username' => 'otheruser_reorder',
        ]);
        $otherBlock = ProfileBlock::create([
            'profile_id' => $otherProfile->id,
            'type' => 'link',
            'config' => ['title' => 'Foreign Block', 'url' => 'https://other.com'],
        ]);

        $myBlock = ProfileBlock::create([
            'profile_id' => $this->profile->id,
            'type' => 'link',
            'config' => ['title' => 'My Block', 'url' => 'https://mine.com'],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.reorder'), [
                'version' => (int) $this->profile->version,
                'ordered_ids' => [$myBlock->id, $otherBlock->id],
            ]);

        $response->assertStatus(422);
    }

    public function test_new_block_receives_valid_sequential_ordering(): void
    {
        $block1 = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'heading',
                'config' => ['text' => 'First'],
            ]);
        $this->assertEquals(0, $block1->json('data.sort_order'));

        $block2 = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.store'), [
                'type' => 'heading',
                'config' => ['text' => 'Second'],
            ]);
        $this->assertEquals(1, $block2->json('data.sort_order'));
    }

    public function test_active_block_appears_publicly(): void
    {
        ProfileBlock::create([
            'profile_id' => $this->profile->id,
            'type' => 'link',
            'config' => ['title' => 'Active Link', 'url' => 'https://active.com'],
            'is_visible' => true,
            'sort_order' => 0,
        ]);

        $response = $this->getJson(route('api.v1.public.profile', ['username' => 'blockmaster']));
        $response->assertStatus(200);

        $blocks = $response->json('data.blocks');
        $this->assertCount(1, $blocks);
        $this->assertEquals('Active Link', $blocks[0]['config']['title']);
    }

    public function test_inactive_block_does_not_appear_publicly(): void
    {
        ProfileBlock::create([
            'profile_id' => $this->profile->id,
            'type' => 'link',
            'config' => ['title' => 'Hidden Link', 'url' => 'https://hidden.com'],
            'is_visible' => false,
            'sort_order' => 0,
        ]);

        $response = $this->getJson(route('api.v1.public.profile', ['username' => 'blockmaster']));
        $response->assertStatus(200);
        $this->assertEmpty($response->json('data.blocks'));
    }

    public function test_deleted_block_does_not_appear_publicly(): void
    {
        $block = ProfileBlock::create([
            'profile_id' => $this->profile->id,
            'type' => 'link',
            'config' => ['title' => 'Deleted Link', 'url' => 'https://deleted.com'],
            'is_visible' => true,
            'sort_order' => 0,
        ]);
        $block->delete();

        $response = $this->getJson(route('api.v1.public.profile', ['username' => 'blockmaster']));
        $response->assertStatus(200);
        $this->assertEmpty($response->json('data.blocks'));
    }

    public function test_public_blocks_return_in_correct_sort_order(): void
    {
        ProfileBlock::create([
            'profile_id' => $this->profile->id,
            'type' => 'link',
            'config' => ['title' => 'Second in Line', 'url' => 'https://second.com'],
            'is_visible' => true,
            'sort_order' => 1,
        ]);
        ProfileBlock::create([
            'profile_id' => $this->profile->id,
            'type' => 'link',
            'config' => ['title' => 'First in Line', 'url' => 'https://first.com'],
            'is_visible' => true,
            'sort_order' => 0,
        ]);

        $response = $this->getJson(route('api.v1.public.profile', ['username' => 'blockmaster']));
        $response->assertStatus(200);

        $blocks = $response->json('data.blocks');
        $this->assertEquals('First in Line', $blocks[0]['config']['title']);
        $this->assertEquals('Second in Line', $blocks[1]['config']['title']);
    }

    public function test_reorder_succeeds_and_updates_sort_order(): void
    {
        $b1 = ProfileBlock::create([
            'profile_id' => $this->profile->id,
            'type' => 'heading',
            'config' => ['text' => 'A'],
            'sort_order' => 0,
        ]);
        $b2 = ProfileBlock::create([
            'profile_id' => $this->profile->id,
            'type' => 'heading',
            'config' => ['text' => 'B'],
            'sort_order' => 1,
        ]);

        $this->profile->refresh();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.reorder'), [
                'version' => (int) $this->profile->version,
                'ordered_ids' => [$b2->id, $b1->id],
            ]);

        $response->assertStatus(200);
        $this->assertEquals(0, $b2->fresh()->sort_order);
        $this->assertEquals(1, $b1->fresh()->sort_order);
    }

    public function test_duplicate_ids_in_reorder_rejected(): void
    {
        $b1 = ProfileBlock::create([
            'profile_id' => $this->profile->id,
            'type' => 'heading',
            'config' => ['text' => 'A'],
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.reorder'), [
                'version' => (int) $this->profile->version,
                'ordered_ids' => [$b1->id, $b1->id],
            ]);

        $response->assertStatus(422);
    }

    public function test_foreign_block_id_in_reorder_rejected(): void
    {
        $otherUser = User::factory()->create();
        $otherProfile = Profile::create(['user_id' => $otherUser->id, 'username' => 'other99']);
        $foreignBlock = ProfileBlock::create([
            'profile_id' => $otherProfile->id,
            'type' => 'heading',
            'config' => ['text' => 'Foreign'],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.reorder'), [
                'version' => (int) $this->profile->version,
                'ordered_ids' => [$foreignBlock->id],
            ]);

        $response->assertStatus(422);
    }

    public function test_reorder_is_atomic(): void
    {
        $b1 = ProfileBlock::create(['profile_id' => $this->profile->id, 'type' => 'heading', 'config' => ['text' => 'A'], 'sort_order' => 0]);
        $b2 = ProfileBlock::create(['profile_id' => $this->profile->id, 'type' => 'heading', 'config' => ['text' => 'B'], 'sort_order' => 1]);

        $invalidPayload = [
            'version' => 9999, // invalid version triggers rollback
            'ordered_ids' => [$b2->id, $b1->id],
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.reorder'), $invalidPayload);

        $response->assertStatus(409);
        $this->assertEquals(0, $b1->fresh()->sort_order);
        $this->assertEquals(1, $b2->fresh()->sort_order);
    }

    public function test_stale_reorder_returns_409_conflict(): void
    {
        $b1 = ProfileBlock::create(['profile_id' => $this->profile->id, 'type' => 'heading', 'config' => ['text' => 'A'], 'sort_order' => 0]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.v1.profile.blocks.reorder'), [
                'version' => 999,
                'ordered_ids' => [$b1->id],
            ]);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'CONFLICT',
                ],
            ]);
    }

    public function test_stale_block_update_returns_409_conflict(): void
    {
        $block = ProfileBlock::create([
            'profile_id' => $this->profile->id,
            'type' => 'link',
            'config' => ['title' => 'Initial Title', 'url' => 'https://initial.com'],
            'version' => 2,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson(route('api.v1.profile.blocks.update', ['block' => $block->id]), [
                'version' => 1, // stale version
                'config' => ['title' => 'Stale Update', 'url' => 'https://initial.com'],
            ]);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'CONFLICT',
                ],
            ]);
    }

    public function test_public_response_does_not_expose_private_ownership_data(): void
    {
        ProfileBlock::create([
            'profile_id' => $this->profile->id,
            'type' => 'link',
            'config' => ['title' => 'Safe Link', 'url' => 'https://safe.com'],
            'version' => 5,
        ]);

        $response = $this->getJson(route('api.v1.public.profile', ['username' => 'blockmaster']));
        $response->assertStatus(200);

        $blockData = $response->json('data.blocks.0');
        $this->assertArrayNotHasKey('profile_id', $blockData);
        $this->assertArrayNotHasKey('version', $blockData);
        $this->assertArrayNotHasKey('created_at', $blockData);
        $this->assertArrayNotHasKey('updated_at', $blockData);
        $this->assertArrayNotHasKey('deleted_at', $blockData);
    }

    public function test_public_profile_block_query_avoids_n_plus_one_behavior(): void
    {
        for ($i = 0; $i < 5; $i++) {
            ProfileBlock::create([
                'profile_id' => $this->profile->id,
                'type' => 'link',
                'config' => ['title' => "Link {$i}", 'url' => "https://link{$i}.com"],
                'sort_order' => $i,
            ]);
        }

        DB::enableQueryLog();

        $response = $this->getJson(route('api.v1.public.profile', ['username' => 'blockmaster']));
        $response->assertStatus(200);

        $queries = DB::getQueryLog();
        // Should execute constant bounded queries: 1 for profile and eager-loaded relations (blocks, domains, user, subscription, plan)
        $this->assertLessThanOrEqual(8, count($queries));
    }
}
