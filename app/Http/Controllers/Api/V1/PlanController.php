<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PlanResource;
use App\Models\Plan;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PlanController extends Controller
{
    use ApiResponse;

    /**
     * List all active subscription plans.
     */
    public function index(): JsonResponse
    {
        $plans = Plan::where('is_active', true)
            ->orderBy('sort_order', 'asc')
            ->get();

        return $this->successResponse(
            PlanResource::collection($plans),
            [],
            Response::HTTP_OK
        );
    }
}
