<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Subscriptions\SubscriptionWebhookService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected SubscriptionWebhookService $webhookService
    ) {}

    /**
     * Handle incoming Stripe webhook notifications.
     */
    public function handleStripe(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();
        $signatureHeader = (string) (
            $request->header('Stripe-Signature')
            ?: $request->header('stripe-signature')
            ?: $request->header('X-Webhook-Signature')
            ?: ''
        );

        $result = $this->webhookService->handleWebhook(
            rawPayload: $rawPayload,
            signatureHeader: $signatureHeader,
            providerName: 'stripe'
        );

        return $this->successResponse(
            $result,
            [],
            Response::HTTP_OK
        );
    }
}
