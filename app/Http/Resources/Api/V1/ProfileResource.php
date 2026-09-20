<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Profile
 */
class ProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array for authenticated owner management.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'username' => $this->username,
            'display_name' => $this->display_name,
            'bio' => $this->bio,
            'avatar_url' => $this->avatar_url,
            'cover_url' => $this->cover_url,
            'template_id' => $this->template_id,
            'theme_tokens' => $this->theme_tokens,
            'is_public' => (bool) $this->is_public,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'version' => (int) $this->version,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
