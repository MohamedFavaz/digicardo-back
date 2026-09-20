<?php

namespace Tests\Feature\Api\V1;

use App\Enums\NotificationCategory;
use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_own_notifications(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $service = app(NotificationService::class);
        $service->createForUser($user, NotificationType::Welcome, NotificationCategory::System, 'Welcome', 'Welcome to Digicardo');
        $service->createForUser($user, NotificationType::ContactReceived, NotificationCategory::Contact, 'Contact Msg', 'New message');
        $service->createForUser($otherUser, NotificationType::Welcome, NotificationCategory::System, 'Other Welcome', 'Welcome other user');

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/notifications');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.items');
    }

    public function test_user_can_filter_notifications_by_category(): void
    {
        $user = User::factory()->create();
        $service = app(NotificationService::class);
        $service->createForUser($user, NotificationType::Welcome, NotificationCategory::System, 'Welcome', 'Welcome');
        $service->createForUser($user, NotificationType::ContactReceived, NotificationCategory::Contact, 'Contact Msg', 'New message');

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/notifications?category=contact');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.category', 'contact');
    }

    public function test_user_can_get_unread_count(): void
    {
        $user = User::factory()->create();
        $service = app(NotificationService::class);
        $n1 = $service->createForUser($user, NotificationType::Welcome, NotificationCategory::System, 'N1', 'B1');
        $service->createForUser($user, NotificationType::Welcome, NotificationCategory::System, 'N2', 'B2');

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/notifications/unread-count');
        $response->assertStatus(200)->assertJsonPath('data.count', 2);

        $service->markAsRead($user, $n1->id);

        $response2 = $this->actingAs($user, 'sanctum')->getJson('/api/v1/notifications/unread-count');
        $response2->assertStatus(200)->assertJsonPath('data.count', 1);
    }

    public function test_user_can_mark_notification_as_read(): void
    {
        $user = User::factory()->create();
        $service = app(NotificationService::class);
        $notif = $service->createForUser($user, NotificationType::Welcome, NotificationCategory::System, 'N1', 'B1');

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/v1/notifications/{$notif->id}/read");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_read', true);
    }

    public function test_user_cannot_access_or_modify_another_users_notification(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $service = app(NotificationService::class);
        $otherNotif = $service->createForUser($otherUser, NotificationType::Welcome, NotificationCategory::System, 'N1', 'B1');

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/v1/notifications/{$otherNotif->id}/read");
        $response->assertStatus(404);

        $deleteResponse = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/notifications/{$otherNotif->id}");
        $deleteResponse->assertStatus(404);
    }

    public function test_user_can_mark_all_notifications_as_read(): void
    {
        $user = User::factory()->create();
        $service = app(NotificationService::class);
        $service->createForUser($user, NotificationType::Welcome, NotificationCategory::System, 'N1', 'B1');
        $service->createForUser($user, NotificationType::Welcome, NotificationCategory::System, 'N2', 'B2');

        $response = $this->actingAs($user, 'sanctum')->patchJson('/api/v1/notifications/read-all');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.updated', 2);

        $this->assertEquals(0, $service->unreadCount($user));
    }

    public function test_user_can_delete_notification(): void
    {
        $user = User::factory()->create();
        $service = app(NotificationService::class);
        $notif = $service->createForUser($user, NotificationType::Welcome, NotificationCategory::System, 'N1', 'B1');

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/notifications/{$notif->id}");

        $response->assertStatus(200)->assertJsonPath('success', true);
        $this->assertDatabaseMissing('notifications', ['id' => $notif->id]);
    }
}
