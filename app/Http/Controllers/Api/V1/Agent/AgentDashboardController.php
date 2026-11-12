<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Services\AgentCardService;
use App\Services\AgentFinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentDashboardController extends Controller
{
    public function __construct(
        private readonly AgentCardService $cardService,
        private readonly AgentFinanceService $financeService
    ) {}

    /**
     * Agent dashboard — stats, charts, recent cards.
     */
    public function index(Request $request): JsonResponse
    {
        $agent = $request->user();
        $dashboard = $this->cardService->getAgentDashboard($agent);
        $earnings = $this->financeService->getAgentEarnings($agent);

        return response()->json([
            'success' => true,
            'data' => array_merge($dashboard, [
                'earnings' => $earnings,
            ]),
        ]);
    }
}

