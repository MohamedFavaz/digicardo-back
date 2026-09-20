<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminSalesService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSalesController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AdminSalesService $salesService
    ) {}

    /** Overview: today + this month + all time revenue. */
    public function overview(): JsonResponse
    {
        return $this->successResponse($this->salesService->getOverview());
    }

    /** Monthly bar chart data (last 12 months). */
    public function monthlyChart(Request $request): JsonResponse
    {
        $months = min(24, max(1, (int) $request->query('months', 12)));
        return $this->successResponse([
            'chart' => $this->salesService->getMonthlyChart($months),
        ]);
    }

    /** Daily line chart data (last N days). */
    public function dailyChart(Request $request): JsonResponse
    {
        $days = min(90, max(7, (int) $request->query('days', 30)));
        return $this->successResponse([
            'chart' => $this->salesService->getDailyChart($days),
        ]);
    }

    /** Paginated sales table for Accounts menu. */
    public function salesTable(Request $request): JsonResponse
    {
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(5, (int) $request->query('per_page', 20)));
        $from    = $request->query('from');
        $to      = $request->query('to');

        return $this->successResponse(
            $this->salesService->getSalesTable($page, $perPage, $from, $to)
        );
    }
}
