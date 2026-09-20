<?php

namespace App\Models;

use App\Enums\EmailDeliveryStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailDelivery extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'email_deliveries';

    protected $fillable = [
        'user_id',
        'notification_id',
        'type',
        'recipient',
        'subject',
        'provider',
        'provider_message_id',
        'status',
        'attempts',
        'last_error',
        'sent_at',
        'failed_at',
    ];

    protected $casts = [
        'status' => EmailDeliveryStatus::class,
        'attempts' => 'integer',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
