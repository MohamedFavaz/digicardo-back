<?php

namespace App\Services\Subscriptions;

use App\Contracts\SubscriptionProviderInterface;
use App\Exceptions\InvalidWebhookSignatureException;
use App\Jobs\ProcessSubscriptionWebhookJob;
use App\Models\SubscriptionWebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class SubscriptionWebhookService
{
    public function __construct(
        protected SubscriptionProviderInterface $provider
    ) {}

    /**
     * Handle incoming webhook with signature verification, duplicate deduplication, and queue dispatch.
     */
    public function handleWebhook(string $rawPayload, string $signatureHeader, string $providerName = 'stripe'): array
    {
        $secret = (string) config("services.{$providerName}.webhook_secret", '');

        // 1. Signature Verification using exact raw payload
        $isValid = $this->provider->verifyWebhookSignature($rawPayload, $signatureHeader, $secret);

        if (!$isValid) {
            Log::warning('[SubscriptionWebhook] Invalid webhook signature rejected', [
                'provider' => $providerName,
            ]);
            throw new InvalidWebhookSignatureException('Webhook signature verification failed.');
        }

        $payload = json_decode($rawPayload, true);

        if (!is_array($payload)) {
            throw new InvalidWebhookSignatureException('Malformed JSON payload.');
        }

        $eventId = (string) ($payload['id'] ?? ('evt_' . uniqid()));
        $eventType = (string) ($payload['type'] ?? 'unknown');
        $payloadHash = hash('sha256', $rawPayload);

        // 2. Idempotency Check & Logging
        try {
            $eventRecord = SubscriptionWebhookEvent::create([
                'provider' => $providerName,
                'provider_event_id' => $eventId,
                'event_type' => $eventType,
                'payload_hash' => $payloadHash,
                'payload' => $payload,
                'processed_at' => null,
            ]);
        } catch (QueryException $e) {
            // Duplicate event code (23000 in SQL)
            if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate')) {
                Log::info('[SubscriptionWebhook] Duplicate webhook event received, skipping execution', [
                    'provider' => $providerName,
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                ]);

                return [
                    'status' => 'duplicate',
                    'event_id' => $eventId,
                    'message' => 'Event already received and processed.',
                ];
            }

            throw $e;
        }

        // 3. Dispatch Async Queue Job
        ProcessSubscriptionWebhookJob::dispatch(
            provider: $providerName,
            eventId: $eventId,
            eventType: $eventType,
            payload: $payload
        );

        return [
            'status' => 'acknowledged',
            'event_id' => $eventId,
        ];
    }
}
