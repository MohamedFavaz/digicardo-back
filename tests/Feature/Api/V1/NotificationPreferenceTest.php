<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_retrieve_default_notification_preferences(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/profile/notification-preferences');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email_enabled', true)
            ->assertJsonPath('data.security_email_enabled', true)
            ->assertJsonPath('data.marketing_email_enabled', false)
            ->assertJsonPath('data.contact_email_enabled', true)
            ->assertJsonPath('data.subscription_email_enabled', true)
            ->assertJsonPath('data.domain_email_enabled', true)
            ->assertJsonPath('data.in_app_enabled', true);
    }

    public function test_user_can_update_notification_preferences(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->patchJson('/api/v1/profile/notification-preferences', [
            'marketing_email_enabled' => true,
            'contact_email_enabled' => false,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.marketing_email_enabled', true)
            ->assertJsonPath('data.contact_email_enabled', false)
            ->assertJsonPath('data.security_email_enabled', true); // Security remains true
    }

    public function test_security_email_cannot_be_disabled(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->patchJson('/api/v1/profile/notification-preferences', [
            'security_email_enabled' => false,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.security_email_enabled', true);
    }
}
