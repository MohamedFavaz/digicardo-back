<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\SubmitAbuseReportRequest;
use App\Services\AbuseReportService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PublicReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AbuseReportService $abuseReportService
    ) {}

    /**
     * Submit an abuse report against a public profile or block.
     * Public, rate-limited, anti-spam protected.
     */
    public function submit(SubmitAbuseReportRequest $request, string $username): JsonResponse
    {
        $report = $this->abuseReportService->submitReport(
            $username,
            $request->validated(),
            $request->ip()
        );

        return $this->successResponse(
            [
                'report_id' => $report->id,
                'status' => 'received',
            ],
            ['message' => 'Thank you for your report. Our moderation team will investigate.'],
            Response::HTTP_CREATED
        );
    }
}
