<?php

namespace App\Models;

use App\Enums\AnalyticsEventType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsEvent extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'analytics_events';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'profile_id',
        'block_id',
        'event_type',
        'visitor_hash',
        'referrer_host',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'event_type' => AnalyticsEventType::class,
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected $hidden = [
        'visitor_hash',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(ProfileBlock::class, 'block_id');
    }
}
