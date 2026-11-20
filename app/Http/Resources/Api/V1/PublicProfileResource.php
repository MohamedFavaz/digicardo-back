<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Profile
 */
class PublicProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array for public unauthenticated profile rendering.
     * Strictly omits user_id, email, timestamps, and internal metadata.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->user;
        $canRemoveBranding = false;
        if ($user) {
            $entitlementService = app(\App\Services\FeatureEntitlementService::class);
            $canRemoveBranding = $entitlementService->can($user, \App\Enums\FeatureKey::RemoveBranding);
        }

        $userWantsHide = isset($this->theme_tokens['hide_branding']) && (bool) $this->theme_tokens['hide_branding'];
        $showBranding = !($canRemoveBranding && $userWantsHide);

        return [
            'id' => $this->id,
            'username' => $this->username,
            'display_name' => $this->display_name,
            'bio' => $this->bio,
            'avatar_url' => $this->avatar_url,
            'cover_url' => $this->cover_url,
            'template_id' => $this->template_id,
            'theme_tokens' => $this->theme_tokens,
            // Typed card content (ADR-030); null until the profile is converted, in which case
            // the frontend derives it from theme_tokens.custom_options.
            'content' => is_array($this->content) ? \App\Services\CardContentSchema::normalize($this->content) : null,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'seo_keywords' => $this->seo_keywords ?? [],
            'og_title' => $this->og_title,
            'og_description' => $this->og_description,
            'og_image_url' => $this->ogImageMedia?->url,
            'indexable' => (bool) $this->indexable,
            'primary_custom_domain' => $this->primaryDomain?->normalized_domain,
            // Cards made by an agent: the agent's domain (card lives at https://{agent_domain}/{username})
            // and brand (shown instead of "Made with Digicardo").
            'agent_domain' => app(\App\Services\AgentSiteService::class)->activeDomainForUser($user),
            'agent_brand' => app(\App\Services\AgentSiteService::class)->brandForUser($user),
            'show_branding' => $showBranding,
            'created_at' => $this->created_at?->toIso8601String(),
            'stats' => app(\App\Services\ProfileService::class)->getPublicProfileStats($this->resource),
            'blocks' => PublicProfileBlockResource::collection($this->whenLoaded('blocks')),
        ];
    }
}
