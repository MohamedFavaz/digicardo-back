<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\FeatureEntitlementService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EntitlementController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected FeatureEntitlementService $entitlementService
    ) {}

    /**
     * Get all active entitlements, limits, usage, and remaining allowances for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $entitlements = $this->entitlementService->getEntitlements($user);

        return $this->successResponse(
            $entitlements,
            [],
            Response::HTTP_OK
        );
    }
}
