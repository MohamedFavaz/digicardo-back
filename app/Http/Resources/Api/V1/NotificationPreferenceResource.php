<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationPreferenceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'email_enabled' => (bool) $this->email_enabled,
            'security_email_enabled' => true, // locked
            'marketing_email_enabled' => (bool) $this->marketing_email_enabled,
            'contact_email_enabled' => (bool) $this->contact_email_enabled,
            'subscription_email_enabled' => (bool) $this->subscription_email_enabled,
            'domain_email_enabled' => (bool) $this->domain_email_enabled,
            'in_app_enabled' => (bool) $this->in_app_enabled,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
