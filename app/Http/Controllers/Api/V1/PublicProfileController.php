<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PublicProfileResource;
use App\Services\ProfileService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PublicProfileController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProfileService $profileService
    ) {}

    /**
     * Retrieve public profile data by username.
     * Public, unauthenticated, edge-cache friendly.
     */
    public function show(string $username): JsonResponse
    {
        $profile = $this->profileService->getPublicProfile($username);

        if (!$profile) {
            return $this->errorResponse(
                'NOT_FOUND',
                'Profile not found or is currently private.',
                null,
                Response::HTTP_NOT_FOUND
            );
        }

        return $this->successResponse(
            new PublicProfileResource($profile),
            [],
            Response::HTTP_OK,
            [
                'Cache-Control' => 'public, s-maxage=300, stale-while-revalidate=86400',
            ]
        );
    }

    /**
     * Retrieve real-time public profile analytics metrics (views, clicks, actions, days live, engage).
     * Public, lightweight, live-polled.
     */
    public function stats(string $username): JsonResponse
    {
        $normalized = \App\Services\UsernameService::normalize($username);
        $profile = \App\Models\Profile::where('username', $normalized)
            ->where('is_public', true)
            ->first();

        if (!$profile) {
            return $this->errorResponse(
                'NOT_FOUND',
                'Profile not found or is currently private.',
                null,
                Response::HTTP_NOT_FOUND
            );
        }

        $stats = $this->profileService->getPublicProfileStats($profile);

        return $this->successResponse(
            $stats,
            [],
            Response::HTTP_OK,
            [
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]
        );
    }
}
