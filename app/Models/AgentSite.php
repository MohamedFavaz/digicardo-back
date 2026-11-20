<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agent's landing page and (optional, admin-managed) domain.
 */
class AgentSite extends Model
{
    use HasUlids;

    public const DOMAIN_PENDING = 'pending';
    public const DOMAIN_ACTIVE = 'active';
    public const DOMAIN_FAILED = 'failed';

    protected $fillable = [
        'agent_id',
        'slug',
        'content',
        'published',
        'published_at',
        'landing_disabled',
        'domain',
        'domain_status',
        'domain_error',
        'domain_checked_at',
        'domain_activated_at',
    ];

    protected $casts = [
        'content' => 'array',
        'published' => 'boolean',
        'published_at' => 'datetime',
        'landing_disabled' => 'boolean',
        'domain_checked_at' => 'datetime',
        'domain_activated_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function hasActiveDomain(): bool
    {
        return $this->domain !== null && $this->domain_status === self::DOMAIN_ACTIVE;
    }

    public function isLandingLive(): bool
    {
        return $this->published && ! $this->landing_disabled;
    }
}
