<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Card\StoreCardRequest;
use App\Http\Requests\Card\UpdateCardRequest;
use App\Http\Resources\Api\V1\CardResource;
use App\Services\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CardController extends Controller
{
    public function __construct(
        protected CardService $cardService
    ) {}

    /**
     * List all cards owned by the authenticated user.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $cards = $this->cardService->listForUser($request->user(), [
            'category' => $request->query('category'),
            'status' => $request->query('status'),
            'search' => $request->query('search'),
        ]);

        return CardResource::collection($cards);
    }

    /**
     * Create a new card.
     */
    public function store(StoreCardRequest $request): JsonResponse
    {
        $card = $this->cardService->createForUser($request->user(), $request->validated());

        return (new CardResource($card))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a specific card.
     */
    public function show(Request $request, string $id): CardResource
    {
        $card = $this->cardService->findForUser($request->user(), $id);

        return new CardResource($card);
    }

    /**
     * Update a specific card.
     */
    public function update(UpdateCardRequest $request, string $id): CardResource
    {
        $card = $this->cardService->findForUser($request->user(), $id);
        $updated = $this->cardService->update($card, $request->validated());

        return new CardResource($updated);
    }

    /**
     * Delete a specific card.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $card = $this->cardService->findForUser($request->user(), $id);
        $this->cardService->delete($request->user(), $card);

        return response()->json(['message' => 'Card deleted successfully.']);
    }

    /**
     * Publish a card (generate live URL and mark active).
     */
    public function publish(Request $request, string $id): CardResource
    {
        $card = $this->cardService->findForUser($request->user(), $id);
        $published = $this->cardService->publish($card);

        return new CardResource($published);
    }

    /**
     * Unpublish a card (set to draft).
     */
    public function unpublish(Request $request, string $id): CardResource
    {
        $card = $this->cardService->findForUser($request->user(), $id);
        $unpublished = $this->cardService->unpublish($card);

        return new CardResource($unpublished);
    }

    /**
     * Duplicate an existing card with all its blocks.
     */
    public function duplicate(Request $request, string $id): JsonResponse
    {
        $sourceCard = $this->cardService->findForUser($request->user(), $id);
        $duplicated = $this->cardService->duplicate($request->user(), $sourceCard);

        return (new CardResource($duplicated))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Set a card as the primary card.
     */
    public function setPrimary(Request $request, string $id): CardResource
    {
        $card = $this->cardService->findForUser($request->user(), $id);
        $primary = $this->cardService->setPrimary($request->user(), $card);

        return new CardResource($primary);
    }
}
