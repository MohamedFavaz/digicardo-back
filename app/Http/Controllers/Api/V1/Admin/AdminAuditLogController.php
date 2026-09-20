<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminAuditService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuditLogController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AdminAuditService $adminAuditService
    ) {}

    /**
     * List administrative audit logs with filters and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(5, (int) $request->query('per_page', 20)));

        $filters = [
            'action' => $request->query('action'),
            'target_type' => $request->query('target_type'),
            'actor_id' => $request->query('actor_id'),
            'request_id' => $request->query('request_id'),
        ];

        $paginator = $this->adminAuditService->getLogs($filters, $page, $perPage);

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
