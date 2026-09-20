<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Plan extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'plans';

    protected $fillable = [
        'code',
        'slug',
        'name',
        'description',
        'price_monthly_cents',
        'price_yearly_cents',
        'currency',
        'features',
        'limits',
        'is_active',
        'sort_order',
        'stripe_monthly_price_id',
        'stripe_yearly_price_id',
    ];

    protected $casts = [
        'features' => 'array',
        'limits' => 'array',
        'is_active' => 'boolean',
        'price_monthly_cents' => 'integer',
        'price_yearly_cents' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * Subscriptions associated with this plan.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    /**
     * Helper to get feature value with fallback to limits json.
     */
    public function getFeatureValue(string $key): mixed
    {
        if (is_array($this->features) && array_key_exists($key, $this->features)) {
            return $this->features[$key];
        }

        if (is_array($this->limits) && array_key_exists($key, $this->limits)) {
            return $this->limits[$key];
        }

        return null;
    }
}
