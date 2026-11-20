<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A message an admin or agent sent to a group of people. Delivered as notifications.
 */
class BroadcastMessage extends Model
{
    use HasUlids;

    protected $fillable = [
        'sender_id',
        'sender_role',
        'audience',
        'audience_meta',
        'title',
        'body',
        'link_url',
        'recipient_count',
        'emailed',
        'retracted_at',
    ];

    protected $casts = [
        'audience_meta' => 'array',
        'recipient_count' => 'integer',
        'emailed' => 'boolean',
        'retracted_at' => 'datetime',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'broadcast_id');
    }
}
