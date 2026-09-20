<?php

namespace App\Models;

use App\Enums\DomainSslStatus;
use App\Enums\DomainStatus;
use App\Enums\DomainVerificationMethod;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProfileDomain extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'profile_domains';

    protected $fillable = [
        'id',
        'profile_id',
        'user_id',
        'domain',
        'normalized_domain',
        'status',
        'verification_method',
        'verification_token',
        'verified_at',
        'activated_at',
        'last_checked_at',
        'failure_reason',
        'is_primary',
        'ssl_status',
    ];

    protected function casts(): array
    {
        return [
            'status' => DomainStatus::class,
            'verification_method' => DomainVerificationMethod::class,
            'ssl_status' => DomainSslStatus::class,
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
            'activated_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the DNS TXT record host required for verification.
     */
    public function getVerificationHost(): string
    {
        return "_Digicardo-verify.{$this->normalized_domain}";
    }

    /**
     * Get the DNS TXT record value expected for verification.
     */
    public function getVerificationValue(): string
    {
        return "Digicardo-verification={$this->verification_token}";
    }
}
