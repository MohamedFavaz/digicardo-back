<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Block\CreateBlockRequest;
use App\Http\Requests\Block\ReorderBlocksRequest;
use App\Http\Requests\Block\UpdateBlockRequest;
use App\Http\Resources\Api\V1\ProfileBlockResource;
use App\Models\ProfileBlock;
use App\Services\BlockService;
use App\Traits\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProfileBlockController extends Controller
{
    use ApiResponse, AuthorizesRequests;

    public function __construct(
        private readonly BlockService $blockService
    ) {}

    /**
     * List all blocks for the authenticated user's profile.
     */
    public function index(Request $request): JsonResponse
    {
        $profile = $request->user()->profile;

        if (!$profile) {
            return $this->errorResponse(
                'NOT_FOUND',
                'User does not have an active profile.',
                null,
                Response::HTTP_NOT_FOUND
            );
        }

        $blocks = $this->blockService->getForProfile($profile);

        return $this->successResponse(ProfileBlockResource::collection($blocks));
    }

    /**
     * Create a new content block for the authenticated user's profile.
     */
    public function store(CreateBlockRequest $request): JsonResponse
    {
        $profile = $request->user()->profile;

        if (!$profile) {
            return $this->errorResponse(
                'NOT_FOUND',
                'User does not have an active profile.',
                null,
                Response::HTTP_NOT_FOUND
            );
        }

        $block = $this->blockService->createForProfile($profile, $request->validated());

        return $this->successResponse(
            new ProfileBlockResource($block),
            ['message' => 'Block created successfully.'],
            Response::HTTP_CREATED
        );
    }

    /**
     * Retrieve a specific block.
     */
    public function show(Request $request, ProfileBlock $block): JsonResponse
    {
        $this->authorize('view', $block);

        return $this->successResponse(new ProfileBlockResource($block));
    }

    /**
     * Update an existing content block.
     */
    public function update(UpdateBlockRequest $request, ProfileBlock $block): JsonResponse
    {
        $this->authorize('update', $block);

        $updated = $this->blockService->update($block, $request->validated());

        return $this->successResponse(
            new ProfileBlockResource($updated),
            ['message' => 'Block updated successfully.']
        );
    }

    /**
     * Delete a content block.
     */
    public function destroy(Request $request, ProfileBlock $block): JsonResponse
    {
        $this->authorize('delete', $block);

        $this->blockService->delete($block);

        return $this->successResponse(
            ['message' => 'Block deleted successfully.'],
            [],
            Response::HTTP_OK
        );
    }

    /**
     * Reorder content blocks transactionally.
     */
    public function reorder(ReorderBlocksRequest $request): JsonResponse
    {
        $profile = $request->user()->profile;

        if (!$profile) {
            return $this->errorResponse(
                'NOT_FOUND',
                'User does not have an active profile.',
                null,
                Response::HTTP_NOT_FOUND
            );
        }

        $result = $this->blockService->reorder(
            $profile,
            $request->validated('ordered_ids'),
            (int) $request->validated('version')
        );

        return $this->successResponse(
            ProfileBlockResource::collection($result['blocks']),
            ['version' => $result['version'], 'message' => 'Blocks reordered successfully.']
        );
    }
}
