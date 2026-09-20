<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\HealthCheckService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected HealthCheckService $healthService
    ) {}

    /**
     * Return safe operational health status of the API (never leaks secrets or infrastructure details).
     */
    public function check(): JsonResponse
    {
        $health = $this->healthService->getPublicHealth();

        return $this->successResponse($health);
    }
}
