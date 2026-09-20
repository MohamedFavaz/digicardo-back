<?php

namespace Tests\Feature\Api\V1;

use App\Enums\AbuseReportStatus;
use App\Enums\BlockType;
use App\Enums\UserRole;
use App\Models\AbuseReport;
use App\Models\Profile;
use App\Models\ProfileBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AbuseReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitor_can_submit_valid_abuse_report(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'reporteduser',
            'display_name' => 'Reported User',
        ]);

        $response = $this->postJson('/api/v1/p/reporteduser/report', [
            'reason' => 'phishing',
            'description' => 'This profile contains phishing links targeting cryptocurrency users.',
            'reporter_email' => 'victim@example.com',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'received');

        $this->assertDatabaseHas('abuse_reports', [
            'profile_id' => $profile->id,
            'reason' => 'phishing',
            'reporter_email' => 'victim@example.com',
            'status' => 'open',
        ]);
    }

    public function test_honeypot_submission_is_rejected(): void
    {
        $user = User::factory()->create();
        Profile::create([
            'user_id' => $user->id,
            'username' => 'botprofile',
            'display_name' => 'Bot Profile',
        ]);

        $response = $this->postJson('/api/v1/p/botprofile/report', [
            'reason' => 'spam',
            'description' => 'Spamming content description',
            'hp_field' => 'bot-value',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['form']]]);
    }

    public function test_foreign_block_id_cannot_be_attached_to_profile_report(): void
    {
        $user1 = User::factory()->create();
        $profile1 = Profile::create([
            'user_id' => $user1->id,
            'username' => 'profile1',
            'display_name' => 'Profile 1',
        ]);

        $user2 = User::factory()->create();
        $profile2 = Profile::create([
            'user_id' => $user2->id,
            'username' => 'profile2',
            'display_name' => 'Profile 2',
        ]);

        $block2 = ProfileBlock::create([
            'profile_id' => $profile2->id,
            'type' => BlockType::Link,
            'config' => ['url' => 'https://example.com', 'title' => 'Example'],
            'sort_order' => 0,
        ]);

        // Attempt to report profile1 while referencing block2 (which belongs to profile2)
        $response = $this->postJson('/api/v1/p/profile1/report', [
            'reason' => 'malicious_content',
            'description' => 'Malicious payload in content block',
            'block_id' => $block2->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['block_id']]]);
    }

    public function test_admin_can_list_and_update_abuse_report_status(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'targetprofile',
            'display_name' => 'Target Profile',
        ]);

        $report = AbuseReport::create([
            'profile_id' => $profile->id,
            'reason' => 'harassment',
            'description' => 'Target profile contains abusive remarks and harassment.',
            'status' => AbuseReportStatus::Open,
        ]);

        // Admin lists reports
        $listResponse = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/reports?status=open');
        $listResponse->assertStatus(200)
            ->assertJsonCount(1, 'data.items');

        // Admin resolves report
        $updateResponse = $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/admin/reports/{$report->id}", [
            'status' => 'resolved',
            'resolution_notes' => 'Investigated. Content removed and user warned.',
        ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'resolved');

        $report->refresh();
        $this->assertEquals(AbuseReportStatus::Resolved, $report->status);
        $this->assertEquals($admin->id, $report->resolved_by);
        $this->assertNotNull($report->resolved_at);
    }
}
