<?php

namespace App\Models;

use App\Enums\AccountSecurityEventType;
use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountSecurityEvent extends Model
{
    use HasFactory, HasUlid;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'event_type',
        'metadata',
        'ip_hash',
        'created_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'ip_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => AccountSecurityEventType::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * User who experienced the security event.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
