<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ValidityPlan extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'name', 'months', 'price', 'currency_symbol', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'price'     => 'decimal:2',
        'is_active' => 'boolean',
        'months'    => 'integer',
    ];

    protected static function booting(): void
    {
        static::creating(fn ($model) => $model->id ??= (string) Str::ulid());
    }

    public function sales(): HasMany
    {
        return $this->hasMany(AdminSale::class, 'validity_plan_id');
    }

    /** Human-readable price string e.g. "₹799" */
    public function formattedPrice(): string
    {
        return $this->currency_symbol . number_format((float) $this->price, 0);
    }
}
