<?php

namespace App\Models;

use App\Enums\ModerationActionType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModerationAction extends Model
{
    use HasFactory, HasUlids;

    public $timestamps = false;

    protected $table = 'moderation_actions';

    protected $fillable = [
        'actor_id',
        'target_type',
        'target_id',
        'action_type',
        'reason',
        'internal_notes',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'action_type' => ModerationActionType::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
