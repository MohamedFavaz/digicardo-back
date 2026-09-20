<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateReportStatusRequest;
use App\Services\AbuseReportService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AbuseReportService $abuseReportService
    ) {}

    /**
     * List abuse reports with filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(5, (int) $request->query('per_page', 15)));

        $filters = [
            'status' => $request->query('status'),
            'reason' => $request->query('reason'),
            'profile_id' => $request->query('profile_id'),
        ];

        $paginator = $this->abuseReportService->listReports($filters, $page, $perPage);

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
     * Get specific report details.
     */
    public function show(string $id): JsonResponse
    {
        $report = $this->abuseReportService->getReportDetails($id);

        return $this->successResponse($report);
    }

    /**
     * Update abuse report resolution status.
     */
    public function update(UpdateReportStatusRequest $request, string $id): JsonResponse
    {
        $report = $this->abuseReportService->updateReportStatus(
            $request->user(),
            $id,
            $request->validated('status'),
            $request->validated('resolution_notes')
        );

        $statusValue = $report->status instanceof \App\Enums\AbuseReportStatus
            ? $report->status->value
            : (string) $report->status;

        return $this->successResponse(
            [
                'report_id' => $report->id,
                'status' => $statusValue,
                'resolved_at' => $report->resolved_at?->toIso8601String(),
            ],
            ['message' => "Abuse report updated to {$statusValue}."]
        );
    }
}
