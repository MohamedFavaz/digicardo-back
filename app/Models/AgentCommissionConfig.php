<?php

namespace App\Models;

use App\Enums\AgentPricingModel;
use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Commission configuration for an agent.
 *
 * When agent_id is NULL, this record serves as the global default
 * commission configuration applied to all agents without a specific config.
 */
class AgentCommissionConfig extends Model
{
    use HasFactory, HasUlid, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'agent_id',
        'card_price',
        'agent_commission',
        'admin_profit',
        'pricing_model',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'card_price'       => 'decimal:2',
            'agent_commission' => 'decimal:2',
            'admin_profit'     => 'decimal:2',
            'pricing_model'    => AgentPricingModel::class,
        ];
    }

    // ── Relationships ──

    /**
     * The agent this commission config belongs to.
     * NULL agent_id indicates the global default configuration.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
