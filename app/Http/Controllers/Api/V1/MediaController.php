<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Media\UploadAvatarRequest;
use App\Http\Requests\Media\UploadCoverRequest;
use App\Http\Requests\Media\UploadDocumentRequest;
use App\Http\Requests\Media\UploadImageRequest;
use App\Http\Resources\Api\V1\MediaResource;
use App\Http\Resources\Api\V1\ProfileResource;
use App\Models\ProfileMedia;
use App\Services\MediaService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MediaController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly MediaService $mediaService
    ) {}

    /**
     * Upload and update the authenticated user's profile avatar.
     */
    public function uploadAvatar(UploadAvatarRequest $request): JsonResponse
    {
        $media = $this->mediaService->storeAvatar(
            $request->user(),
            $request->file('image')
        );

        return $this->successResponse(
            new MediaResource($media),
            ['message' => 'Profile avatar uploaded successfully.'],
            Response::HTTP_CREATED
        );
    }

    /**
     * Remove the authenticated user's profile avatar.
     */
    public function deleteAvatar(Request $request): JsonResponse
    {
        $this->mediaService->deleteAvatar($request->user());

        return $this->successResponse(
            null,
            ['message' => 'Profile avatar removed successfully.']
        );
    }

    /**
     * Upload and update the authenticated user's profile cover image.
     */
    public function uploadCover(UploadCoverRequest $request): JsonResponse
    {
        $media = $this->mediaService->storeCover(
            $request->user(),
            $request->file('image')
        );

        return $this->successResponse(
            new MediaResource($media),
            ['message' => 'Profile cover image uploaded successfully.'],
            Response::HTTP_CREATED
        );
    }

    /**
     * Remove the authenticated user's profile cover image.
     */
    public function deleteCover(Request $request): JsonResponse
    {
        $this->mediaService->deleteCover($request->user());

        return $this->successResponse(
            null,
            ['message' => 'Profile cover image removed successfully.']
        );
    }

    /**
     * Upload a content block image.
     */
    public function uploadImage(UploadImageRequest $request): JsonResponse
    {
        $media = $this->mediaService->storeImage(
            $request->user(),
            $request->file('image'),
            $request->input('alt_text')
        );

        return $this->successResponse(
            new MediaResource($media),
            ['message' => 'Image uploaded successfully.'],
            Response::HTTP_CREATED
        );
    }

    /**
     * Upload a PDF document (brochure, profile, catalog).
     */
    public function uploadDocument(UploadDocumentRequest $request): JsonResponse
    {
        $media = $this->mediaService->storeDocument(
            $request->user(),
            $request->file('document'),
            $request->input('title')
        );

        return $this->successResponse(
            new MediaResource($media),
            ['message' => 'Document uploaded successfully.'],
            Response::HTTP_CREATED
        );
    }

    /**
     * Delete an unreferenced media item.
     */
    public function destroy(Request $request, ProfileMedia $media): JsonResponse
    {
        $this->mediaService->deleteMedia($request->user(), $media);

        return $this->successResponse(
            null,
            ['message' => 'Media item deleted successfully.']
        );
    }

    /**
     * List all media items uploaded for the authenticated user's profile.
     */
    public function index(Request $request): JsonResponse
    {
        $profile = $request->user()->profile;

        if (!$profile) {
            return $this->errorResponse(
                'NOT_FOUND',
                'Profile not found.',
                null,
                Response::HTTP_NOT_FOUND
            );
        }

        $mediaItems = ProfileMedia::where('profile_id', $profile->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->successResponse(MediaResource::collection($mediaItems));
    }
}
