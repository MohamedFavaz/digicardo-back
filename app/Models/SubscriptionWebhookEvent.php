<?php

namespace App\Models;

use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionWebhookEvent extends Model
{
    use HasFactory, HasUlid;

    protected $table = 'subscription_webhook_events';

    protected $fillable = [
        'id',
        'provider',
        'provider_event_id',
        'event_type',
        'payload_hash',
        'payload',
        'processed_at',
        'failed_at',
        'failure_reason',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /**
     * Mark event as successfully processed.
     */
    public function markAsProcessed(): void
    {
        $this->update([
            'processed_at' => now(),
            'failed_at' => null,
            'failure_reason' => null,
        ]);
    }

    /**
     * Mark event as failed.
     */
    public function markAsFailed(string $reason): void
    {
        $this->update([
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);
    }
}
