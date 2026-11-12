<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Services\AgentFinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentEarningsController extends Controller
{
    public function __construct(
        private readonly AgentFinanceService $financeService
    ) {}

    /**
     * Agent's earnings summary.
     */
    public function summary(Request $request): JsonResponse
    {
        $agent = $request->user();
        $earnings = $this->financeService->getAgentEarnings($agent);

        return response()->json([
            'success' => true,
            'data' => $earnings,
        ]);
    }

    /**
     * Agent's transaction history.
     */
    public function transactions(Request $request): JsonResponse
    {
        $agent = $request->user();

        $transactions = $this->financeService->getTransactions($agent, [
            'type' => $request->query('type'),
            'per_page' => $request->query('per_page', 15),
        ]);

        return response()->json([
            'success' => true,
            'data' => $transactions->items(),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }
}

