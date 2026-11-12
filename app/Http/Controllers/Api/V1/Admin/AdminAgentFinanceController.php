<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AgentFinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAgentFinanceController extends Controller
{
    public function __construct(
        private readonly AgentFinanceService $financeService
    ) {
    }

    /**
     * Financial dashboard aggregates for admin.
     */
    public function dashboard(): JsonResponse
    {
        $data = $this->financeService->getFinancialDashboard();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Revenue report — agent-wise breakdown.
     */
    public function revenue(Request $request): JsonResponse
    {
        $report = $this->financeService->getRevenueReport([
            'agent_id' => $request->query('agent_id'),
            'from_date' => $request->query('from_date'),
            'to_date' => $request->query('to_date'),
            'per_page' => $request->query('per_page', 15),
        ]);

        return response()->json([
            'success' => true,
            'data' => $report->items(),
            'meta' => [
                'current_page' => $report->currentPage(),
                'last_page' => $report->lastPage(),
                'per_page' => $report->perPage(),
                'total' => $report->total(),
            ],
        ]);
    }

    /**
     * Record a commission payment to an agent.
     */
    public function recordPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agent_id' => 'required|string|exists:users,id',
            'amount' => 'required|numeric|min:0.01',
            'status' => 'sometimes|string|in:pending,paid,failed',
            'payment_method' => 'nullable|string|max:100',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        $payment = $this->financeService->recordPayment($validated);

        return response()->json([
            'success' => true,
            'data' => $payment,
            'message' => 'Payment recorded successfully.',
        ], Response::HTTP_CREATED);
    }

    /**
     * Payment history list.
     */
    public function payments(Request $request): JsonResponse
    {
        $payments = $this->financeService->getPaymentHistory([
            'agent_id' => $request->query('agent_id'),
            'status' => $request->query('status'),
            'per_page' => $request->query('per_page', 15),
        ]);

        return response()->json([
            'success' => true,
            'data' => $payments->items(),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }
}
