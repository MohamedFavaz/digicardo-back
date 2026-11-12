<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAgentController extends Controller
{
    public function __construct(
        private readonly AgentService $agentService
    ) {
    }

    /**
     * List all agents with pagination, search, and filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $agents = $this->agentService->listAgents([
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'per_page' => $request->query('per_page', 15),
        ]);

        return response()->json([
            'success' => true,
            'data' => $agents->items(),
            'meta' => [
                'current_page' => $agents->currentPage(),
                'last_page' => $agents->lastPage(),
                'per_page' => $agents->perPage(),
                'total' => $agents->total(),
            ],
        ]);
    }

    /**
     * Create a new agent.
     */
    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'name' => 'required|string|min:2|max:100',
            'email' => [
                'required',
                'email',
                \Illuminate\Validation\Rule::unique('users', 'email')->where(function ($query) {
                    return $query->whereNull('deleted_at');
                }),
            ],
            'mobile' => 'nullable|string|min:10|max:20',
            'password' => 'required|string|min:8',
            'card_limit' => 'required|integer|min:1|max:100000',
            'card_price' => 'nullable|numeric|min:0',
            'agent_commission' => 'nullable|numeric|min:0',
            'restore_on_delete' => 'nullable|boolean',
        ]);

        $agent = $this->agentService->createAgent($validated);

        return response()->json([
            'success' => true,
            'data' => $agent,
            'message' => 'Agent created successfully.',
        ], Response::HTTP_CREATED);
    }

    /**
     * Get single agent details with performance data.
     */
    public function show(string $id): JsonResponse
    {
        $agent = User::where('role', 'agent')->findOrFail($id);
        $agent->load(['agentQuota', 'commissionConfig']);
        $performance = $this->agentService->getAgentPerformance($agent);

        return response()->json([
            'success' => true,
            'data' => [
                'agent' => $agent,
                'performance' => $performance,
            ],
        ]);
    }

    /**
     * Update agent details.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $agent = User::where('role', 'agent')->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|min:2|max:100',
            'email' => [
                'sometimes',
                'email',
                \Illuminate\Validation\Rule::unique('users', 'email')
                    ->ignore($agent->id)
                    ->where(fn($q) => $q->whereNull('deleted_at')),
            ],
            'mobile' => 'nullable|string|min:10|max:20',
            'password' => 'nullable|string|min:8',
            'status' => 'sometimes|string|in:active,suspended,banned',
            'card_limit' => 'sometimes|integer|min:0|max:100000',
            'card_price' => 'nullable|numeric|min:0',
            'agent_commission' => 'nullable|numeric|min:0',
            'restore_on_delete' => 'nullable|boolean',
        ]);

        $agent = $this->agentService->updateAgent($agent, $validated);

        return response()->json([
            'success' => true,
            'data' => $agent,
            'message' => 'Agent updated successfully.',
        ]);
    }

    /**
     * Delete (soft-delete) an agent.
     */
    public function destroy(string $id): JsonResponse
    {
        $agent = User::where('role', 'agent')->findOrFail($id);
        $this->agentService->deleteAgent($agent);

        return response()->json([
            'success' => true,
            'message' => 'Agent deleted successfully.',
        ]);
    }

    /**
     * Activate an agent.
     */
    public function activate(string $id): JsonResponse
    {
        $agent = User::where('role', 'agent')->findOrFail($id);
        $agent = $this->agentService->activateAgent($agent);

        return response()->json([
            'success' => true,
            'data' => $agent,
            'message' => 'Agent activated successfully.',
        ]);
    }

    /**
     * Deactivate (suspend) an agent.
     */
    public function deactivate(string $id): JsonResponse
    {
        $agent = User::where('role', 'agent')->findOrFail($id);
        $agent = $this->agentService->deactivateAgent($agent);

        return response()->json([
            'success' => true,
            'data' => $agent,
            'message' => 'Agent deactivated successfully.',
        ]);
    }

    /**
     * Get agent performance dashboard stats for admin.
     */
    public function dashboard(): JsonResponse
    {
        $stats = $this->agentService->getAdminAgentDashboard();
        $performanceTable = $this->agentService->getAgentPerformanceTable();

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => $stats,
                'performance_table' => $performanceTable,
            ],
        ]);
    }
}

