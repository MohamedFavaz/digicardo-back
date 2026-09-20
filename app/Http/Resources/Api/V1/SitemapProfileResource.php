<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Profile
 */
class SitemapProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array for sitemap generator.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'username' => $this->username,
            'primary_custom_domain' => $this->primaryDomain?->normalized_domain,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
