<?php

namespace App\Models;

use App\Enums\AgentCardStatus;
use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A card (profile) created and managed by an agent on behalf of a customer.
 *
 * Tracks financial details including revenue, agent commission, and admin profit.
 */
class AgentCard extends Model
{
    use HasFactory, HasUlid, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'agent_id',
        'customer_user_id',
        'profile_id',
        'card_name',
        'customer_name',
        'customer_email',
        'customer_phone',
        'status',
        'revenue',
        'agent_commission',
        'admin_profit',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status'           => AgentCardStatus::class,
            'revenue'          => 'decimal:2',
            'agent_commission' => 'decimal:2',
            'admin_profit'     => 'decimal:2',
        ];
    }

    // ── Relationships ──

    /**
     * The agent who created this card.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * The customer user account associated with this card.
     */
    public function customerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    /**
     * The profile (card content) associated with this agent card.
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /**
     * Financial transactions related to this card.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(AgentTransaction::class);
    }
}
