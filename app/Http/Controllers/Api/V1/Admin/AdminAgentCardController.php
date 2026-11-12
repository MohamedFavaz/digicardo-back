<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentCard;
use App\Services\AgentCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAgentCardController extends Controller
{
    public function __construct(
        private readonly AgentCardService $cardService
    ) {}

    /**
     * List ALL agent-created cards (admin view).
     */
    public function index(Request $request): JsonResponse
    {
        $cards = $this->cardService->listAllCards([
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'agent_id' => $request->query('agent_id'),
            'per_page' => $request->query('per_page', 15),
        ]);

        return response()->json([
            'success' => true,
            'data' => $cards->items(),
            'meta' => [
                'current_page' => $cards->currentPage(),
                'last_page' => $cards->lastPage(),
                'per_page' => $cards->perPage(),
                'total' => $cards->total(),
            ],
        ]);
    }

    /**
     * Get single card details.
     */
    public function show(string $id): JsonResponse
    {
        $card = AgentCard::with(['agent', 'customerUser', 'profile', 'transactions'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $card,
        ]);
    }

    /**
     * Update a card (admin override).
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $card = AgentCard::findOrFail($id);

        $validated = $request->validate([
            'card_name' => 'sometimes|string|max:150',
            'customer_name' => 'sometimes|string|max:150',
            'customer_email' => 'sometimes|email',
            'customer_phone' => 'nullable|string|max:20',
            'status' => 'sometimes|string|in:active,inactive,suspended',
            'notes' => 'nullable|string',
        ]);

        $card = $this->cardService->updateCard($card, $validated);

        return response()->json([
            'success' => true,
            'data' => $card,
            'message' => 'Card updated successfully.',
        ]);
    }

    /**
     * Delete a card (admin).
     */
    public function destroy(string $id): JsonResponse
    {
        $card = AgentCard::findOrFail($id);
        $this->cardService->deleteCard($card);

        return response()->json([
            'success' => true,
            'message' => 'Card deleted successfully.',
        ]);
    }

    /**
     * Activate a card.
     */
    public function activate(string $id): JsonResponse
    {
        $card = AgentCard::findOrFail($id);
        $card = $this->cardService->activateCard($card);

        return response()->json([
            'success' => true,
            'data' => $card,
            'message' => 'Card activated successfully.',
        ]);
    }

    /**
     * Deactivate a card.
     */
    public function deactivate(string $id): JsonResponse
    {
        $card = AgentCard::findOrFail($id);
        $card = $this->cardService->deactivateCard($card);

        return response()->json([
            'success' => true,
            'data' => $card,
            'message' => 'Card deactivated successfully.',
        ]);
    }
}

