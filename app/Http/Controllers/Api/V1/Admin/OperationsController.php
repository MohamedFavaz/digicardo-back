<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\HealthCheckService;
use App\Services\OperationalMetricsService;
use App\Services\QueueMonitoringService;
use App\Services\ScheduledTaskMonitoringService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class OperationsController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected HealthCheckService $healthService,
        protected QueueMonitoringService $queueService,
        protected ScheduledTaskMonitoringService $schedulerService,
        protected OperationalMetricsService $metricsService
    ) {}

    /**
     * Get detailed operational health check.
     */
    public function health(): JsonResponse
    {
        return $this->successResponse(
            $this->healthService->getAdminHealth()
        );
    }

    /**
     * Get background queue metrics and queue health.
     */
    public function queue(): JsonResponse
    {
        return $this->successResponse(
            $this->queueService->getQueueHealth()
        );
    }

    /**
     * Get scheduled tasks status and execution history.
     */
    public function scheduler(): JsonResponse
    {
        return $this->successResponse([
            'tasks' => $this->schedulerService->getScheduledTasks(),
        ]);
    }

    /**
     * Get aggregated operational metrics.
     */
    public function metrics(): JsonResponse
    {
        return $this->successResponse(
            $this->metricsService->getMetrics()
        );
    }

    /**
     * Refresh cached metrics immediately.
     */
    public function refreshMetrics(): JsonResponse
    {
        return $this->successResponse(
            $this->metricsService->refreshMetrics(),
            ['message' => 'Operational metrics refreshed successfully.']
        );
    }
}
