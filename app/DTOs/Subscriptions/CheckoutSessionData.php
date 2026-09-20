<?php

namespace App\DTOs\Subscriptions;

class CheckoutSessionData
{
    public function __construct(
        public readonly string $checkoutUrl,
        public readonly ?string $sessionId = null,
        public readonly string $provider = 'null',
        public readonly ?string $message = null,
    ) {}

    public function toArray(): array
    {
        return [
            'checkout_url' => $this->checkoutUrl,
            'session_id' => $this->sessionId,
            'provider' => $this->provider,
            'message' => $this->message,
        ];
    }
}
