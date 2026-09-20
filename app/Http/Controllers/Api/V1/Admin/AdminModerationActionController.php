<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ModerationActionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminModerationActionController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ModerationActionService $moderationActionService
    ) {}

    /**
     * List historical moderation actions with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(5, (int) $request->query('per_page', 20)));

        $filters = [
            'target_type' => $request->query('target_type'),
            'target_id' => $request->query('target_id'),
            'action_type' => $request->query('action_type'),
            'actor_id' => $request->query('actor_id'),
        ];

        $paginator = $this->moderationActionService->listActions($filters, $page, $perPage);

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
}
