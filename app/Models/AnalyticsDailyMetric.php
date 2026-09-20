<?php

namespace App\Models;

use App\Enums\AnalyticsEventType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsDailyMetric extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'analytics_daily_metrics';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'profile_id',
        'block_id',
        'date',
        'event_type',
        'total_count',
        'unique_count',
    ];

    protected $casts = [
        'date' => 'date',
        'event_type' => AnalyticsEventType::class,
        'total_count' => 'integer',
        'unique_count' => 'integer',
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
