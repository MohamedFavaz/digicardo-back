<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscription extends Model
{
    use HasFactory, HasUlid, SoftDeletes;

    protected $table = 'subscriptions';

    protected $fillable = [
        'id',
        'user_id',
        'plan_id',
        'provider',
        'provider_customer_id',
        'provider_subscription_id',
        'provider_price_id',
        'status',
        'billing_interval',
        'current_period_start',
        'current_period_end',
        'cancel_at_period_end',
        'canceled_at',
        'trial_ends_at',
        'grace_period_ends_at',
        'metadata',
    ];

    protected $casts = [
        'status' => SubscriptionStatus::class,
        'cancel_at_period_end' => 'boolean',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'canceled_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'grace_period_ends_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * User owning the subscription.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Plan for this subscription.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /**
     * Check if subscription is actively entitling benefits.
     */
    public function isActive(): bool
    {
        $status = $this->status instanceof SubscriptionStatus ? $this->status : SubscriptionStatus::tryFrom((string) $this->status);

        if (!$status) {
            return false;
        }

        // Active or Trialing
        if (in_array($status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing])) {
            if ($this->current_period_end && $this->current_period_end->isPast()) {
                return false;
            }
            return true;
        }

        // Grace Period or PastDue with active grace period window
        if (in_array($status, [SubscriptionStatus::GracePeriod, SubscriptionStatus::PastDue])) {
            return $this->grace_period_ends_at !== null && $this->grace_period_ends_at->isFuture();
        }

        return false;
    }

    /**
     * Determine if subscription is in an active grace period window.
     */
    public function isInGracePeriod(): bool
    {
        return $this->grace_period_ends_at !== null && $this->grace_period_ends_at->isFuture();
    }
}
