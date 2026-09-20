<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileSeoRequest;
use App\Http\Resources\Api\V1\ProfileSeoResource;
use App\Services\ProfileSeoService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProfileSeoController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ProfileSeoService $seoService
    ) {}

    /**
     * Get SEO and Open Graph configuration for authenticated user's profile.
     */
    public function show(Request $request): JsonResponse
    {
        $profile = $this->seoService->getForUser($request->user());

        return $this->successResponse(
            new ProfileSeoResource($profile)
        );
    }

    /**
     * Update SEO and Open Graph configuration for authenticated user's profile.
     */
    public function update(UpdateProfileSeoRequest $request): JsonResponse
    {
        $profile = $this->seoService->updateForUser($request->user(), $request->validated());

        return $this->successResponse(
            new ProfileSeoResource($profile)
        );
    }
}
