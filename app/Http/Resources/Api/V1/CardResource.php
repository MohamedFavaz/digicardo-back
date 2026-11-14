<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Profile
 */
class CardResource extends JsonResource
{
    /**
     * Transform the card resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $statusVal = $this->status instanceof \App\Enums\CardStatus
            ? $this->status->value
            : ($this->status ?? 'published');

        $statusLabel = $this->status instanceof \App\Enums\CardStatus
            ? $this->status->label()
            : ucfirst($statusVal);

        $categoryVal = $this->category instanceof \App\Enums\CardCategory
            ? $this->category->value
            : ($this->category ?? 'business');

        $categoryLabel = $this->category instanceof \App\Enums\CardCategory
            ? $this->category->label()
            : ucfirst($categoryVal);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'card_title' => $this->card_title ?: ($this->display_name ?: 'Untitled Card'),
            'username' => $this->username,
            'display_name' => $this->display_name,
            'bio' => $this->bio,
            'avatar_url' => $this->avatar_url,
            'cover_url' => $this->cover_url,
            'template_id' => $this->template_id,
            'category' => [
                'value' => $categoryVal,
                'label' => $categoryLabel,
            ],
            'status' => [
                'value' => $statusVal,
                'label' => $statusLabel,
            ],
            'is_primary' => (bool) $this->is_primary,
            'is_public' => (bool) $this->is_public,
            'published_at' => $this->published_at?->toISOString(),
            'published_url' => $this->published_url ?: ($this->username ? url('/' . $this->username) : null),
            'theme_tokens' => $this->theme_tokens,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'version' => (int) $this->version,
            'blocks_count' => $this->whenCounted('blocks'),
            'blocks' => ProfileBlockResource::collection($this->whenLoaded('blocks')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
