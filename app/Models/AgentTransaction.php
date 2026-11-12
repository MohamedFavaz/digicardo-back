<?php

namespace App\Models;

use App\Enums\AgentTransactionType;
use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Financial transaction record for the agent system.
 *
 * Tracks card sales, commission payments, and manual adjustments.
 */
class AgentTransaction extends Model
{
    use HasFactory, HasUlid, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'agent_id',
        'agent_card_id',
        'type',
        'amount',
        'description',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type'   => AgentTransactionType::class,
            'amount' => 'decimal:2',
        ];
    }

    // ── Relationships ──

    /**
     * The agent this transaction belongs to.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * The agent card this transaction is associated with.
     */
    public function agentCard(): BelongsTo
    {
        return $this->belongsTo(AgentCard::class);
    }
}
