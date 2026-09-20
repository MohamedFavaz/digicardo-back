<?php

namespace App\Models;

use App\Enums\NotificationCategory;
use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Notification extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'notifications';

    protected $fillable = [
        'user_id',
        'type',
        'category',
        'title',
        'body',
        'data',
        'read_at',
    ];

    protected $casts = [
        'type' => NotificationType::class,
        'category' => NotificationCategory::class,
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function emailDeliveries(): HasMany
    {
        return $this->hasMany(EmailDelivery::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
