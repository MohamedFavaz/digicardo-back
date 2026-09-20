<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Profile
 */
class ProfileSeoResource extends JsonResource
{
    /**
     * Transform the resource into an array for authenticated SEO settings management.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'seo_keywords' => $this->seo_keywords ?? [],
            'og_title' => $this->og_title,
            'og_description' => $this->og_description,
            'og_image_media_id' => $this->og_image_media_id,
            'og_image_url' => $this->ogImageMedia?->url,
            'indexable' => (bool) $this->indexable,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
