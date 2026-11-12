<?php

namespace App\Models;

use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Agent card creation quota management.
 *
 * Tracks how many cards an agent is allowed to create
 * and how many have been used.
 */
class AgentQuota extends Model
{
    use HasFactory, HasUlid, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'agent_id',
        'card_limit',
        'cards_used',
        'restore_on_delete',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'card_limit'        => 'integer',
            'cards_used'        => 'integer',
            'restore_on_delete' => 'boolean',
        ];
    }

    // ── Relationships ──

    /**
     * The agent user this quota belongs to.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    // ── Helper Methods ──

    /**
     * Check if the agent has remaining card credits.
     */
    public function hasAvailableCredits(): bool
    {
        return $this->cards_used < $this->card_limit;
    }

    /**
     * Get the number of remaining card credits.
     */
    public function remainingCredits(): int
    {
        return max(0, $this->card_limit - $this->cards_used);
    }
}
