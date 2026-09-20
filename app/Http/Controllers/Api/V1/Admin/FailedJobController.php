<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\QueueMonitoringService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FailedJobController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected QueueMonitoringService $queueService
    ) {}

    /**
     * List paginated sanitized failed jobs.
     */
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(50, max(5, (int) $request->query('per_page', 15)));

        $paginator = $this->queueService->listFailedJobs($page, $perPage);

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

    /**
     * Retry a specific failed job.
     */
    public function retry(string $id): JsonResponse
    {
        $success = $this->queueService->retryFailedJob($id);

        if (! $success) {
            return $this->errorResponse(
                'FAILED_JOB_RETRY_ERROR',
                'Failed to retry job. It may no longer exist.',
                400
            );
        }

        return $this->successResponse(
            ['job_id' => $id, 'retried' => true],
            ['message' => "Failed job {$id} queued for retry."]
        );
    }

    /**
     * Retry all failed jobs.
     */
    public function retryAll(): JsonResponse
    {
        $count = $this->queueService->retryAllFailedJobs();

        return $this->successResponse(
            ['retried_count' => $count],
            ['message' => "Successfully queued {$count} failed job(s) for retry."]
        );
    }

    /**
     * Permanently discard a specific failed job.
     */
    public function destroy(string $id): JsonResponse
    {
        $success = $this->queueService->forgetFailedJob($id);

        return $this->successResponse(
            ['job_id' => $id, 'discarded' => $success],
            ['message' => "Failed job {$id} discarded."]
        );
    }

    /**
     * Flush all failed jobs.
     */
    public function flush(): JsonResponse
    {
        $this->queueService->flushFailedJobs();

        return $this->successResponse(
            ['flushed' => true],
            ['message' => 'All failed jobs have been permanently flushed.']
        );
    }
}
