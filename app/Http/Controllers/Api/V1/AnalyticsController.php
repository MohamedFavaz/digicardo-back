<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\StoreAnalyticsEventRequest;
use App\Services\AnalyticsService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AnalyticsController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AnalyticsService $analyticsService
    ) {}

    /**
     * Public, non-blocking ingestion endpoint for analytics beacons.
     * Returns 202 Accepted immediately while queuing asynchronous aggregation.
     */
    public function ingest(StoreAnalyticsEventRequest $request): JsonResponse
    {
        $this->analyticsService->ingest(
            $request->validated(),
            $request->ip() ?: '127.0.0.1',
            $request->userAgent()
        );

        return $this->successResponse(
            ['accepted' => true],
            [],
            Response::HTTP_ACCEPTED
        );
    }
}
