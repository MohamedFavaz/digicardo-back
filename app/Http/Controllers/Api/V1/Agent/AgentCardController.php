<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\AgentCard;
use App\Services\AgentCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AgentCardController extends Controller
{
    public function __construct(
        private readonly AgentCardService $cardService
    ) {
    }

    /**
     * List agent's own cards with pagination, search, and filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $agent = $request->user();

        $cards = $this->cardService->listCards($agent, [
            'search' => $request->query('search'),
            'status' => $request->query('status'),
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
     * Create a new digital card for a customer.
     * Enforces card creation quota.
     */
    public function store(Request $request): JsonResponse
    {
        $agent = $request->user();

        $validated = $request->validate([
            'customer_name' => 'required|string|min:2|max:150',
            'customer_email' => 'required|email|unique:users,email',
            'customer_phone' => 'nullable|string|max:20',
            'username' => 'required|string|min:3|max:30|regex:/^[a-z0-9_-]+$/|unique:profiles,username',
            'display_name' => 'sometimes|string|min:2|max:150',
            'card_name' => 'sometimes|string|max:150',
            'bio' => 'nullable|string|max:500',
            'template_id' => 'nullable|string',
            'password' => 'required|string|min:8',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $card = $this->cardService->createCard($agent, $validated);

            return response()->json([
                'success' => true,
                'data' => $card,
                'message' => 'Digital card created successfully.',
            ], Response::HTTP_CREATED);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CARD_LIMIT_EXCEEDED',
                    'message' => $e->getMessage(),
                ],
            ], Response::HTTP_FORBIDDEN);
        }
    }

    /**
     * Get a single card's details.
     * Agent can only view their own cards.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $agent = $request->user();
        $card = AgentCard::where('agent_id', $agent->id)
            ->with(['customerUser', 'profile.blocks'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $card,
        ]);
    }

    /**
     * Update a card's metadata.
     * Agent can only update their own cards.
     * Editing does NOT consume credits.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $agent = $request->user();
        $card = AgentCard::where('agent_id', $agent->id)->findOrFail($id);

        $validated = $request->validate([
            'card_name' => 'sometimes|string|max:150',
            'customer_name' => 'sometimes|string|max:150',
            'customer_email' => 'sometimes|email',
            'customer_phone' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:500',
        ]);

        $card = $this->cardService->updateCard($card, $validated);

        return response()->json([
            'success' => true,
            'data' => $card,
            'message' => 'Card updated successfully.',
        ]);
    }

    /**
     * Delete a card (soft-delete).
     * Agent can only delete their own cards.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $agent = $request->user();
        $card = AgentCard::where('agent_id', $agent->id)->findOrFail($id);

        $this->cardService->deleteCard($card);

        return response()->json([
            'success' => true,
            'message' => 'Card deleted successfully.',
        ]);
    }

    /**
     * Publish (activate) a card.
     */
    public function publish(Request $request, string $id): JsonResponse
    {
        $agent = $request->user();
        $card = AgentCard::where('agent_id', $agent->id)->findOrFail($id);

        $card = $this->cardService->activateCard($card);

        return response()->json([
            'success' => true,
            'data' => $card,
            'message' => 'Card published successfully.',
        ]);
    }

    /**
     * Unpublish (deactivate) a card.
     */
    public function unpublish(Request $request, string $id): JsonResponse
    {
        $agent = $request->user();
        $card = AgentCard::where('agent_id', $agent->id)->findOrFail($id);

        $card = $this->cardService->deactivateCard($card);

        return response()->json([
            'success' => true,
            'data' => $card,
            'message' => 'Card unpublished successfully.',
        ]);
    }

    /**
     * Get agent's card creation limits.
     */
    public function limits(Request $request): JsonResponse
    {
        $agent = $request->user();
        $quota = $agent->agentQuota;

        return response()->json([
            'success' => true,
            'data' => [
                'card_limit' => $quota?->card_limit ?? 0,
                'cards_used' => $quota?->cards_used ?? 0,
                'remaining_credits' => $quota?->remainingCredits() ?? 0,
                'restore_on_delete' => $quota?->restore_on_delete ?? true,
            ],
        ]);
    }

    /**
     * Impersonate a customer user to customize their card in the studio.
     * Strictly restricted to the agent owning the specific card.
     */
    public function impersonate(Request $request, string $id): JsonResponse
    {
        $agent = $request->user();
        $result = $this->cardService->impersonateCustomer($agent, $id);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($result['user']),
                'card' => $result['card'],
                'redirect_url' => $result['redirect_url'],
            ],
            'message' => "Successfully signed in as customer {$result['user']->name}.",
        ]);
    }
}
