<?php

namespace App\Services\Email;

use App\Enums\EmailDeliveryStatus;
use App\Models\EmailDelivery;
use Illuminate\Support\Facades\Log;

class EmailDeliveryService
{
    /**
     * Create a new queued email delivery tracking record.
     */
    public function createDelivery(
        string $recipient,
        string $subject,
        string $type,
        string $provider = 'default',
        ?string $userId = null,
        ?string $notificationId = null
    ): EmailDelivery {
        return EmailDelivery::create([
            'user_id' => $userId,
            'notification_id' => $notificationId,
            'type' => $type,
            'recipient' => $recipient,
            'subject' => $subject,
            'provider' => $provider,
            'status' => EmailDeliveryStatus::Queued,
            'attempts' => 0,
        ]);
    }

    /**
     * Mark an email delivery as in progress (sending).
     */
    public function markSending(EmailDelivery $delivery): void
    {
        $delivery->update([
            'status' => EmailDeliveryStatus::Sending,
            'attempts' => $delivery->attempts + 1,
        ]);
    }

    /**
     * Mark an email delivery as successfully sent.
     */
    public function markSent(EmailDelivery $delivery, string $providerMessageId): void
    {
        $delivery->update([
            'status' => EmailDeliveryStatus::Sent,
            'provider_message_id' => $providerMessageId,
            'sent_at' => now(),
            'last_error' => null,
        ]);

        Log::info('[EmailDeliveryService] Email sent successfully', [
            'delivery_id' => $delivery->id,
            'recipient' => $delivery->recipient,
            'subject' => $delivery->subject,
            'message_id' => $providerMessageId,
        ]);
    }

    /**
     * Record a failure attempt or permanent failure.
     */
    public function recordFailure(EmailDelivery $delivery, string $error, bool $permanent = false): void
    {
        $status = $permanent ? EmailDeliveryStatus::PermanentlyFailed : EmailDeliveryStatus::Failed;

        $delivery->update([
            'status' => $status,
            'last_error' => substr($error, 0, 1000), // sanitize & clamp error
            'failed_at' => now(),
        ]);

        Log::warning('[EmailDeliveryService] Email delivery failure recorded', [
            'delivery_id' => $delivery->id,
            'recipient' => $delivery->recipient,
            'subject' => $delivery->subject,
            'status' => $status->value,
            'error' => substr($error, 0, 200),
        ]);
    }
}
