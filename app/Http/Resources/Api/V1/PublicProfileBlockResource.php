<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ProfileMedia;
use App\Services\UrlSecurityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ProfileBlock
 */
class PublicProfileBlockResource extends JsonResource
{
    /**
     * Transform the resource into an array for public unauthenticated profile rendering.
     * Strictly omits profile_id, user_id, timestamps, and internal version.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $config = $this->config ?? [];
        $urlService = app(UrlSecurityService::class);

        if ($this->type === 'image' && !empty($config['media_id'])) {
            $media = ProfileMedia::find($config['media_id']);
            if ($media) {
                $config['url'] = $media->url;
                $config['width'] = $media->width;
                $config['height'] = $media->height;
                if (empty($config['alt_text']) && !empty($media->alt_text)) {
                    $config['alt_text'] = $media->alt_text;
                }
            }
        } elseif ($this->type === 'link' && !empty($config['thumbnail_media_id'])) {
            $thumbnailMedia = ProfileMedia::find($config['thumbnail_media_id']);
            if ($thumbnailMedia) {
                $config['thumbnail_url'] = $thumbnailMedia->url;
            }
        } elseif ($this->type === 'gallery' && !empty($config['media_ids']) && is_array($config['media_ids'])) {
            $mediaItems = ProfileMedia::whereIn('id', $config['media_ids'])->get()->keyBy('id');
            $resolvedImages = [];
            foreach ($config['media_ids'] as $mediaId) {
                if (isset($mediaItems[$mediaId])) {
                    $item = $mediaItems[$mediaId];
                    $resolvedImages[] = [
                        'id' => $item->id,
                        'url' => $item->url,
                        'width' => $item->width,
                        'height' => $item->height,
                        'alt_text' => $item->alt_text,
                    ];
                }
            }
            $config['images'] = $resolvedImages;
        } elseif ($this->type === 'video' && !empty($config['url'])) {
            $parsed = $urlService->parseVideoUrl($config['url']);
            if ($parsed) {
                $config['video_id'] = $parsed['video_id'];
                $config['embed_url'] = $parsed['embed_url'];
            }
        } elseif ($this->type === 'music' && !empty($config['url'])) {
            $parsed = $urlService->parseMusicUrl($config['url']);
            if ($parsed) {
                $config['embed_url'] = $parsed['embed_url'];
            }
        } elseif ($this->type === 'booking' && !empty($config['url'])) {
            $config['is_allowlisted'] = (bool) $urlService->validateBookingUrl($config['url']);
        }

        return [
            'id' => $this->id,
            'type' => $this->type,
            'sort_order' => (int) $this->sort_order,
            'config' => $config,
        ];
    }
}
