<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AccountSecurityEventResource;
use App\Services\AccountSecurityService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountSecurityEventController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AccountSecurityService $securityService
    ) {}

    /**
     * Get paginated security events for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 15), 50);
        $paginator = $this->securityService->getSecurityEvents($request->user(), $perPage);

        return $this->successResponse([
            'items' => AccountSecurityEventResource::collection($paginator->items()),
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
