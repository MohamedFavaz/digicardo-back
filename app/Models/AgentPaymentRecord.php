<?php

namespace App\Models;

use App\Enums\AgentPaymentStatus;
use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Record of commission payments made to an agent.
 *
 * Tracks payment method, status, reference numbers, and payment dates.
 */
class AgentPaymentRecord extends Model
{
    use HasFactory, HasUlid, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'agent_id',
        'amount',
        'status',
        'payment_method',
        'reference_number',
        'notes',
        'paid_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount'  => 'decimal:2',
            'status'  => AgentPaymentStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    // ── Relationships ──

    /**
     * The agent this payment was made to.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
