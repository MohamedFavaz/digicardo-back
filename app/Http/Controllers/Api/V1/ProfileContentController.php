<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileContentRequest;
use App\Services\ProfileContentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProfileContentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProfileContentService $contentService
    ) {}

    /**
     * PATCH /profile/content — replace the parts sent; returns the full content and new version.
     */
    public function update(UpdateProfileContentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $profile = $this->contentService->updateParts(
            $request->user(),
            (int) $validated['version'],
            $validated['sections']
        );

        return $this->successResponse(
            [
                'content' => $this->contentService->contentFor($profile),
                'version' => (int) $profile->version,
            ],
            ['message' => 'Card saved.']
        );
    }
}
