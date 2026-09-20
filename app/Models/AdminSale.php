<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AdminSale extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'user_id', 'validity_plan_id', 'plan_label',
        'amount', 'currency_symbol', 'sale_date',
    ];

    protected $casts = [
        'amount'    => 'decimal:2',
        'sale_date' => 'date',
    ];

    protected static function booting(): void
    {
        static::creating(fn ($model) => $model->id ??= (string) Str::ulid());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function validityPlan(): BelongsTo
    {
        return $this->belongsTo(ValidityPlan::class, 'validity_plan_id');
    }
}
