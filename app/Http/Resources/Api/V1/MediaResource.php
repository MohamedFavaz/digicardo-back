<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ProfileMedia
 */
class MediaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Strictly omits server storage path, disk credentials, and internal host details.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'url' => $this->url,
            'width' => $this->width,
            'height' => $this->height,
            'alt_text' => $this->alt_text,
            'size' => $this->size,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
