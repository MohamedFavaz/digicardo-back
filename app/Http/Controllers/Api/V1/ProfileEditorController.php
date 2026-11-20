<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProfileBlockResource;
use App\Http\Resources\Api\V1\ProfileResource;
use App\Services\ProfileContentService;
use App\Services\ProfileService;
use App\Services\TemplateManifestService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileEditorController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProfileService $profileService,
        private readonly ProfileContentService $contentService,
        private readonly TemplateManifestService $manifests
    ) {}

    /**
     * GET /profile/editor — everything the card editor needs in one call.
     */
    public function show(Request $request): JsonResponse
    {
        $profile = $this->profileService->getForUser($request->user());

        return $this->successResponse([
            'profile' => new ProfileResource($profile),
            'content' => $this->contentService->contentFor($profile),
            'content_stored' => is_array($profile->content),
            'template_id' => $profile->template_id,
            'theme_tokens' => $profile->theme_tokens,
            'blocks' => ProfileBlockResource::collection($profile->blocks()->get()),
            'templates' => array_values($this->manifests->all()),
            'version' => (int) $profile->version,
        ]);
    }
}
