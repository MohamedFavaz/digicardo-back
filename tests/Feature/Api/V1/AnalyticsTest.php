<?php

namespace Tests\Feature\Api\V1;

use App\Enums\AnalyticsEventType;
use App\Models\AnalyticsDailyMetric;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsUniqueVisitor;
use App\Models\Profile;
use App\Models\ProfileBlock;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Profile $profile;
    private ProfileBlock $block;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'username' => 'analyticspro',
            'display_name' => 'Analytics Pro',
            'is_public' => true,
            'version' => 1,
        ]);

        $this->block = ProfileBlock::create([
            'profile_id' => $this->profile->id,
            'type' => 'link',
            'config' => [
                'title' => 'My Portfolio',
                'url' => 'https://example.com/portfolio',
            ],
            'sort_order' => 0,
            'is_visible' => true,
        ]);
    }

    public function test_public_profile_view_event_accepted(): void
    {
        $response = $this->postJson(route('api.v1.analytics.events'), [
            'profile_id' => $this->profile->id,
            'event_type' => 'profile_view',
            'referrer' => 'https://www.google.com/search?q=Digicardo',
            'occurred_at' => Carbon::now()->toIso8601String(),
        ]);

        $response->assertStatus(202)
            ->assertJson([
                'success' => true,
                'data' => [
                    'accepted' => true,
                ],
            ]);

        $this->assertDatabaseHas('analytics_events', [
            'profile_id' => $this->profile->id,
            'event_type' => 'profile_view',
            'referrer_host' => 'google.com',
        ]);
    }

    public function test_invalid_profile_rejected_with_404(): void
    {
        $response = $this->postJson(route('api.v1.analytics.events'), [
            'profile_id' => '01J5K2NONEXISTENTPROFILE01',
            'event_type' => 'profile_view',
        ]);

        $response->assertStatus(404);
    }

    public function test_invalid_event_type_rejected_with_422(): void
    {
        $response = $this->postJson(route('api.v1.analytics.events'), [
            'profile_id' => $this->profile->id,
            'event_type' => 'invalid_unsupported_type',
        ]);

        $response->assertStatus(422);
    }

    public function test_foreign_block_id_rejected(): void
    {
        $user2 = User::factory()->create();
        $profile2 = Profile::create([
            'user_id' => $user2->id,
            'username' => 'otheruser',
            'version' => 1,
        ]);
        $foreignBlock = ProfileBlock::create([
            'profile_id' => $profile2->id,
            'type' => 'link',
            'config' => ['title' => 'Other', 'url' => 'https://other.com'],
            'sort_order' => 0,
        ]);

        $response = $this->postJson(route('api.v1.analytics.events'), [
            'profile_id' => $this->profile->id,
            'block_id' => $foreignBlock->id,
            'event_type' => 'link_click',
        ]);

        $response->assertStatus(422);
    }

    public function test_valid_block_click_event_accepted(): void
    {
        $response = $this->postJson(route('api.v1.analytics.events'), [
            'profile_id' => $this->profile->id,
            'block_id' => $this->block->id,
            'event_type' => 'link_click',
            'metadata' => [
                'destination_host' => 'example.com',
            ],
        ]);

        $response->assertStatus(202);

        $this->assertDatabaseHas('analytics_events', [
            'profile_id' => $this->profile->id,
            'block_id' => $this->block->id,
            'event_type' => 'link_click',
        ]);
    }

    public function test_unauthenticated_analytics_dashboard_rejected(): void
    {
        $this->getJson(route('api.v1.profile.analytics.overview'))->assertStatus(401);
        $this->getJson(route('api.v1.profile.analytics.timeseries'))->assertStatus(401);
        $this->getJson(route('api.v1.profile.analytics.blocks'))->assertStatus(401);
        $this->getJson(route('api.v1.profile.analytics.referrers'))->assertStatus(401);
    }

    public function test_owner_can_retrieve_analytics_overview_and_timeseries(): void
    {
        // Simulate some events
        $this->postJson(route('api.v1.analytics.events'), [
            'profile_id' => $this->profile->id,
            'event_type' => 'profile_view',
            'referrer' => 'https://twitter.com',
        ]);

        $this->postJson(route('api.v1.analytics.events'), [
            'profile_id' => $this->profile->id,
            'block_id' => $this->block->id,
            'event_type' => 'link_click',
        ]);

        $overviewRes = $this->actingAs($this->user, 'sanctum')
            ->getJson(route('api.v1.profile.analytics.overview', ['period' => '7d']));

        $overviewRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'total_views' => 1,
                    'unique_views' => 1,
                    'total_clicks' => 1,
                    'click_through_rate' => 100.0,
                    'period' => '7d',
                ],
            ]);

        $timeseriesRes = $this->actingAs($this->user, 'sanctum')
            ->getJson(route('api.v1.profile.analytics.timeseries', ['period' => '7d']));

        $timeseriesRes->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'date',
                        'views',
                        'unique_views',
                        'clicks',
                    ],
                ],
            ]);

        $blocksRes = $this->actingAs($this->user, 'sanctum')
            ->getJson(route('api.v1.profile.analytics.blocks', ['period' => '7d']));

        $blocksRes->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'block_id',
                        'block_type',
                        'title',
                        'count',
                    ],
                ],
            ]);

        $referrersRes = $this->actingAs($this->user, 'sanctum')
            ->getJson(route('api.v1.profile.analytics.referrers', ['period' => '7d']));

        $referrersRes->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'referrer',
                        'count',
                        'percentage',
                    ],
                ],
            ]);
    }

    public function test_unique_visitor_counted_once_per_day(): void
    {
        // First view from IP A
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.1', 'HTTP_USER_AGENT' => 'Mozilla/5.0 TestBrowser'])
            ->postJson(route('api.v1.analytics.events'), [
                'profile_id' => $this->profile->id,
                'event_type' => 'profile_view',
            ]);

        // Second view from same IP A on same day
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.1', 'HTTP_USER_AGENT' => 'Mozilla/5.0 TestBrowser'])
            ->postJson(route('api.v1.analytics.events'), [
                'profile_id' => $this->profile->id,
                'event_type' => 'profile_view',
            ]);

        $overviewRes = $this->actingAs($this->user, 'sanctum')
            ->getJson(route('api.v1.profile.analytics.overview', ['period' => 'today']));

        $overviewRes->assertStatus(200)
            ->assertJson([
                'data' => [
                    'total_views' => 2,
                    'unique_views' => 1, // Counted once
                ],
            ]);
    }

    public function test_raw_ip_address_is_not_stored_in_analytics_events(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.195'])
            ->postJson(route('api.v1.analytics.events'), [
                'profile_id' => $this->profile->id,
                'event_type' => 'profile_view',
            ]);

        $event = AnalyticsEvent::latest('created_at')->first();
        $this->assertNotNull($event);
        $this->assertNotNull($event->visitor_hash);
        $this->assertStringNotContainsString('203.0.113.195', json_encode($event->toArray()));
    }

    public function test_cleanup_command_removes_old_events(): void
    {
        // Old event
        $oldDate = Carbon::now()->subDays(100);
        AnalyticsEvent::create([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'profile_id' => $this->profile->id,
            'event_type' => 'profile_view',
            'occurred_at' => $oldDate,
        ]);

        // Recent event
        AnalyticsEvent::create([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'profile_id' => $this->profile->id,
            'event_type' => 'profile_view',
            'occurred_at' => Carbon::now(),
        ]);

        $this->artisan('analytics:cleanup', ['--days' => 90])
            ->assertExitCode(0);

        $this->assertEquals(1, AnalyticsEvent::count());
    }
}
