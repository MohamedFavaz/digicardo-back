<?php

namespace Tests\Feature\Api\V1;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_profile(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.profile.store'), [
                'username' => 'janesmith',
                'display_name' => 'Jane Smith',
                'bio' => 'Building cool products.',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'user_id',
                    'username',
                    'display_name',
                    'bio',
                    'template_id',
                    'is_public',
                    'version',
                    'created_at',
                    'updated_at',
                ],
                'meta' => ['message'],
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertEquals('janesmith', $response->json('data.username'));
        $this->assertEquals($user->id, $response->json('data.user_id'));
        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'username' => 'janesmith',
        ]);
    }

    public function test_unauthenticated_user_cannot_create_profile(): void
    {
        $response = $this->postJson(route('api.v1.profile.store'), [
            'username' => 'unauthuser',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                ],
            ]);
    }

    public function test_user_cannot_create_a_second_profile(): void
    {
        $user = User::factory()->create();
        Profile::create([
            'user_id' => $user->id,
            'username' => 'firstprofile',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.profile.store'), [
                'username' => 'secondprofile',
            ]);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'CONFLICT',
                ],
            ]);
    }

    public function test_user_can_retrieve_own_profile(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'myprofile',
            'display_name' => 'My Display Name',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson(route('api.v1.profile.show'));

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $profile->id,
                    'username' => 'myprofile',
                    'display_name' => 'My Display Name',
                ],
            ]);
    }

    public function test_user_can_update_own_profile(): void
    {
        $user = User::factory()->create();
        Profile::create([
            'user_id' => $user->id,
            'username' => 'oldusername',
            'display_name' => 'Old Name',
            'version' => 1,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson(route('api.v1.profile.update'), [
                'username' => 'newusername',
                'display_name' => 'New Name',
                'bio' => 'Updated bio.',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'username' => 'newusername',
                    'display_name' => 'New Name',
                    'bio' => 'Updated bio.',
                    'version' => 2,
                ],
            ]);

        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'username' => 'newusername',
            'display_name' => 'New Name',
        ]);
    }

    public function test_invalid_username_rejected(): void
    {
        $user = User::factory()->create();

        // Testing invalid characters: symbols, spaces, starting with hyphen
        $invalidUsernames = [
            '-starts-with-hyphen',
            'has spaces',
            'has.dots',
            'has/slashes',
            'ab', // too short (< 3)
            'this_username_is_way_too_long_and_exceeds_thirty_chars', // too long (> 30)
            '@twitter_handle',
        ];

        foreach ($invalidUsernames as $invalid) {
            $response = $this->actingAs($user, 'sanctum')
                ->postJson(route('api.v1.profile.store'), [
                    'username' => $invalid,
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

    public function test_reserved_username_rejected(): void
    {
        $user = User::factory()->create();

        $reservedSlugs = ['admin', 'api', 'dashboard', 'login', 'register', 'settings', 'p', 'auth'];

        foreach ($reservedSlugs as $slug) {
            $response = $this->actingAs($user, 'sanctum')
                ->postJson(route('api.v1.profile.store'), [
                    'username' => $slug,
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

    public function test_duplicate_username_rejected(): void
    {
        $user1 = User::factory()->create();
        Profile::create([
            'user_id' => $user1->id,
            'username' => 'existinguser',
        ]);

        $user2 = User::factory()->create();
        $response = $this->actingAs($user2, 'sanctum')
            ->postJson(route('api.v1.profile.store'), [
                'username' => 'existinguser',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                ],
            ]);
    }

    public function test_concurrent_duplicate_username_results_in_one_success_and_one_conflict(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // First user claims username
        $response1 = $this->actingAs($user1, 'sanctum')
            ->postJson(route('api.v1.profile.store'), [
                'username' => 'raceuser',
            ]);
        $response1->assertStatus(201);

        // Second user attempts to claim same username
        $response2 = $this->actingAs($user2, 'sanctum')
            ->postJson(route('api.v1.profile.store'), [
                'username' => 'raceuser',
            ]);
        $response2->assertStatus(422);
    }

    public function test_public_profile_accessible_without_authentication(): void
    {
        $user = User::factory()->create();
        Profile::create([
            'user_id' => $user->id,
            'username' => 'publicstar',
            'display_name' => 'Public Star',
            'bio' => 'Open to everyone',
            'is_public' => true,
        ]);

        $response = $this->getJson(route('api.v1.public.profile', ['username' => 'publicstar']));

        $response->assertStatus(200)
            ->assertHeader('Cache-Control', 'public, s-maxage=300, stale-while-revalidate=86400')
            ->assertJson([
                'success' => true,
                'data' => [
                    'username' => 'publicstar',
                    'display_name' => 'Public Star',
                    'bio' => 'Open to everyone',
                ],
            ]);
    }

    public function test_public_profile_does_not_expose_email_or_user_id(): void
    {
        $user = User::factory()->create(['email' => 'private_email@example.com']);
        Profile::create([
            'user_id' => $user->id,
            'username' => 'safeuser',
            'display_name' => 'Safe User',
            'bio' => 'No leaks',
            'is_public' => true,
        ]);

        $response = $this->getJson(route('api.v1.public.profile', ['username' => 'safeuser']));

        $response->assertStatus(200);
        $payload = $response->getContent();

        $this->assertStringNotContainsString('private_email@example.com', $payload);
        $this->assertStringNotContainsString($user->id, $payload);
        $this->assertArrayNotHasKey('user_id', $response->json('data'));
        $this->assertArrayNotHasKey('email', $response->json('data'));
        $this->assertArrayNotHasKey('id', $response->json('data'));
    }

    public function test_missing_public_profile_returns_404(): void
    {
        $response = $this->getJson(route('api.v1.public.profile', ['username' => 'nonexistent_user']));

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                ],
            ]);
    }

    public function test_private_profile_returns_404_on_public_endpoint(): void
    {
        $user = User::factory()->create();
        Profile::create([
            'user_id' => $user->id,
            'username' => 'privateuser',
            'display_name' => 'Private Profile',
            'is_public' => false,
        ]);

        $response = $this->getJson(route('api.v1.public.profile', ['username' => 'privateuser']));

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                ],
            ]);
    }

    public function test_username_normalization_works_correctly(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.profile.store'), [
                'username' => '  MohamedFavaz  ',
                'display_name' => 'Mohamed Favaz',
            ]);

        $response->assertStatus(201);
        $this->assertEquals('mohamedfavaz', $response->json('data.username'));
        $this->assertDatabaseHas('profiles', ['username' => 'mohamedfavaz']);

        // Case-insensitive public lookup
        $publicRes = $this->getJson(route('api.v1.public.profile', ['username' => 'MOHAMEDFAVAZ']));
        $publicRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'username' => 'mohamedfavaz',
                    'display_name' => 'Mohamed Favaz',
                ],
            ]);
    }
}
