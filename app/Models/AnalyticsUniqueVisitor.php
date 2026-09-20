<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsUniqueVisitor extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'analytics_unique_visitors';

    public $incrementing = false;
    protected $keyType = 'string';

    const UPDATED_AT = null; // Insert-only table

    protected $fillable = [
        'id',
        'profile_id',
        'visitor_hash',
        'date',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    protected $hidden = [
        'visitor_hash',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
