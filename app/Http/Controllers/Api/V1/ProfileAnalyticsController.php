<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\AnalyticsRangeRequest;
use App\Services\AnalyticsAggregationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ProfileAnalyticsController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AnalyticsAggregationService $aggregationService
    ) {}

    /**
     * Authenticated endpoint returning overall analytics summary metrics.
     */
    public function overview(AnalyticsRangeRequest $request): JsonResponse
    {
        $profile = $request->user()->profile;

        if (!$profile) {
            return $this->errorResponse('NOT_FOUND', 'Profile not found.', null, Response::HTTP_NOT_FOUND);
        }

        $period = $request->input('period', '7d');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $data = $this->aggregationService->getOverview($profile, $period, $startDate, $endDate);

        return $this->successResponse($data);
    }

    /**
     * Authenticated endpoint returning daily time series metrics.
     */
    public function timeseries(AnalyticsRangeRequest $request): JsonResponse
    {
        $profile = $request->user()->profile;

        if (!$profile) {
            return $this->errorResponse('NOT_FOUND', 'Profile not found.', null, Response::HTTP_NOT_FOUND);
        }

        $period = $request->input('period', '7d');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $data = $this->aggregationService->getTimeseries($profile, $period, $startDate, $endDate);

        return $this->successResponse($data);
    }

    /**
     * Authenticated endpoint returning per-block interaction counts.
     */
    public function blocks(AnalyticsRangeRequest $request): JsonResponse
    {
        $profile = $request->user()->profile;

        if (!$profile) {
            return $this->errorResponse('NOT_FOUND', 'Profile not found.', null, Response::HTTP_NOT_FOUND);
        }

        $period = $request->input('period', '7d');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $data = $this->aggregationService->getBlockMetrics($profile, $period, $startDate, $endDate);

        return $this->successResponse($data);
    }

    /**
     * Authenticated endpoint returning normalized referrer distribution.
     */
    public function referrers(AnalyticsRangeRequest $request): JsonResponse
    {
        $profile = $request->user()->profile;

        if (!$profile) {
            return $this->errorResponse('NOT_FOUND', 'Profile not found.', null, Response::HTTP_NOT_FOUND);
        }

        $period = $request->input('period', '7d');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $data = $this->aggregationService->getReferrers($profile, $period, $startDate, $endDate);

        return $this->successResponse($data);
    }
}
