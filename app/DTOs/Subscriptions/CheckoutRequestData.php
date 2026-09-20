<?php

namespace App\DTOs\Subscriptions;

class CheckoutRequestData
{
    public function __construct(
        public readonly string $planCode,
        public readonly string $interval = 'monthly',
        public readonly ?string $successUrl = null,
        public readonly ?string $cancelUrl = null,
    ) {}
}
