<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ProfileDomain;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProfileDomain
 */
class ProfileDomainResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'profile_id' => $this->profile_id,
            'domain' => $this->domain,
            'normalized_domain' => $this->normalized_domain,
            'status' => $this->status->value,
            'verification_method' => $this->verification_method->value,
            'verification_instructions' => [
                'record_type' => 'TXT',
                'host' => $this->getVerificationHost(),
                'value' => $this->getVerificationValue(),
            ],
            'is_primary' => (bool) $this->is_primary,
            'ssl_status' => $this->ssl_status->value,
            'verified_at' => $this->verified_at?->toISOString(),
            'activated_at' => $this->activated_at?->toISOString(),
            'last_checked_at' => $this->last_checked_at?->toISOString(),
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
