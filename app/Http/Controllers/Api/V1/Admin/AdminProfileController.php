<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ModerateProfileRequest;
use App\Services\ProfileModerationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminProfileController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ProfileModerationService $profileModerationService
    ) {}

    /**
     * List paginated profiles with moderation filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(5, (int) $request->query('per_page', 15)));
        $sortBy = (string) $request->query('sort_by', 'created_at');
        $sortDir = (string) $request->query('sort_dir', 'desc');

        $filters = [
            'search' => $request->query('search'),
            'moderation_status' => $request->query('moderation_status'),
            'is_public' => $request->query('is_public'),
        ];

        $paginator = $this->profileModerationService->listProfiles($filters, $page, $perPage, $sortBy, $sortDir);

        return $this->successResponse([
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    /**
     * Get detailed profile for moderation.
     */
    public function show(string $id): JsonResponse
    {
        $data = $this->profileModerationService->getProfileDetails($id);

        return $this->successResponse($data);
    }

    /**
     * Moderate a profile's governance status.
     */
    public function moderate(ModerateProfileRequest $request, string $id): JsonResponse
    {
        $profile = $this->profileModerationService->moderateProfile(
            $request->user(),
            $id,
            $request->validated('moderation_status'),
            $request->validated('reason'),
            $request->validated('notes')
        );

        $statusValue = $profile->moderation_status instanceof \App\Enums\ProfileModerationStatus
            ? $profile->moderation_status->value
            : (string) $profile->moderation_status;

        return $this->successResponse(
            [
                'profile_id' => $profile->id,
                'moderation_status' => $statusValue,
                'moderation_reason' => $profile->moderation_reason,
            ],
            ['message' => "Profile moderation status updated to {$statusValue}."]
        );
    }
}
