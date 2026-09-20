<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Enums\NotificationType;
use App\Jobs\SendEmailJob;
use App\Models\Notification;
use App\Models\User;
use App\Services\Email\EmailDeliveryService;
use App\Services\Email\EmailTemplateService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function __construct(
        protected NotificationPreferenceService $preferenceService,
        protected EmailDeliveryService $deliveryService,
        protected EmailTemplateService $templateService
    ) {
    }

    /**
     * Create a notification and optionally queue an asynchronous email.
     *
     * @param User $user
     * @param NotificationType $type
     * @param NotificationCategory $category
     * @param string $title
     * @param string $body
     * @param array<string, mixed>|null $data
     * @param bool $sendEmail
     * @param string|null $customSubject
     * @param string|null $emailHtml
     * @param string|null $emailText
     * @return Notification
     */
    public function createForUser(
        User $user,
        NotificationType $type,
        NotificationCategory $category,
        string $title,
        string $body,
        ?array $data = null,
        bool $sendEmail = true,
        ?string $customSubject = null,
        ?string $emailHtml = null,
        ?string $emailText = null
    ): Notification {
        $prefs = $this->preferenceService->getPreferences($user);

        // 1. Create in-app notification if in_app_enabled is true
        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'category' => $category,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'read_at' => null,
        ]);

        // 2. Determine if email delivery is permitted based on user preferences
        if ($sendEmail && $this->shouldSendEmail($prefs, $category)) {
            $subject = $customSubject ?: $title . ' — Digicardo';
            $html = $emailHtml ?: $this->templateService->renderLayout($title, "<p>{$body}</p>");
            $text = $emailText ?: "{$title}\n\n{$body}\n\nDigicardo";

            $delivery = $this->deliveryService->createDelivery(
                recipient: $user->email,
                subject: $subject,
                type: $type->value,
                provider: config('mail.default', 'default'),
                userId: $user->id,
                notificationId: $notification->id
            );

            // Queue email delivery asynchronously (non-blocking)
            SendEmailJob::dispatch($delivery->id, $html, $text);

            Log::info('[NotificationService] Email delivery queued', [
                'user_id' => $user->id,
                'type' => $type->value,
                'delivery_id' => $delivery->id,
            ]);
        }

        return $notification;
    }

    /**
     * List notifications for authenticated user with pagination and optional filters.
     */
    public function listForUser(
        User $user,
        ?NotificationCategory $category = null,
        bool $unreadOnly = false,
        int $perPage = 15
    ): LengthAwarePaginator {
        $query = Notification::where('user_id', $user->id)->latest('created_at');

        if ($category) {
            $query->where('category', $category->value);
        }

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        return $query->paginate($perPage);
    }

    /**
     * Get unread notifications count for a user.
     */
    public function unreadCount(User $user): int
    {
        return Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Mark a single notification as read, ensuring ownership.
     */
    public function markAsRead(User $user, string $notificationId): Notification
    {
        $notification = Notification::where('user_id', $user->id)
            ->where('id', $notificationId)
            ->firstOrFail();

        if (!$notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return $notification;
    }

    /**
     * Mark all unread notifications for a user as read.
     */
    public function markAllAsRead(User $user): int
    {
        return Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Delete a notification ensuring user ownership.
     */
    public function delete(User $user, string $notificationId): void
    {
        $notification = Notification::where('user_id', $user->id)
            ->where('id', $notificationId)
            ->firstOrFail();

        $notification->delete();
    }

    /**
     * Determine if email delivery is permitted by category and user preferences.
     */
    protected function shouldSendEmail($prefs, NotificationCategory $category): bool
    {
        // Security category is ALWAYS delivered
        if ($category === NotificationCategory::Security) {
            return true;
        }

        // Master toggle
        if (!$prefs->email_enabled) {
            return false;
        }

        return match ($category) {
            NotificationCategory::Contact => $prefs->contact_email_enabled,
            NotificationCategory::Subscription => $prefs->subscription_email_enabled,
            NotificationCategory::Domain => $prefs->domain_email_enabled,
            NotificationCategory::Marketing => $prefs->marketing_email_enabled,
            NotificationCategory::System => true,
            default => true,
        };
    }
}
