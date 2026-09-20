<?php

namespace Tests\Feature\Api\V1;

use App\Models\Profile;
use App\Models\User;
use App\Services\PublicProfileCacheService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PublicProfileCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_cache_service_invalidates_keys(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'cachetester',
            'display_name' => 'Cache Tester',
        ]);

        Cache::put('profile:cachetester', 'cached_data', 600);
        Cache::put("profile_id:{$profile->id}", 'cached_id_data', 600);

        $this->assertTrue(Cache::has('profile:cachetester'));
        $this->assertTrue(Cache::has("profile_id:{$profile->id}"));

        $service = app(PublicProfileCacheService::class);
        $service->invalidateProfile($profile);

        $this->assertFalse(Cache::has('profile:cachetester'));
        $this->assertFalse(Cache::has("profile_id:{$profile->id}"));
    }

    public function test_profile_update_clears_cache(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'johndoe',
            'display_name' => 'John Doe',
        ]);

        Cache::put('profile:johndoe', 'some_cached_content', 600);

        $this->actingAs($user)->patchJson('/api/v1/profile', [
            'display_name' => 'John Updated',
        ]);

        $this->assertFalse(Cache::has('profile:johndoe'));
    }
}
