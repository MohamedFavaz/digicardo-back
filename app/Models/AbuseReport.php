<?php

namespace App\Models;

use App\Enums\AbuseReportReason;
use App\Enums\AbuseReportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AbuseReport extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'abuse_reports';

    protected $fillable = [
        'profile_id',
        'block_id',
        'reporter_email',
        'reporter_ip_hash',
        'reason',
        'description',
        'status',
        'metadata',
        'resolution_notes',
        'resolved_at',
        'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'reason' => AbuseReportReason::class,
            'status' => AbuseReportStatus::class,
            'metadata' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(ProfileBlock::class, 'block_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
