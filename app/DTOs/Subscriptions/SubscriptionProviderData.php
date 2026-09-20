<?php

namespace App\DTOs\Subscriptions;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonInterface;

class SubscriptionProviderData
{
    public function __construct(
        public readonly string $provider,
        public readonly ?string $providerCustomerId,
        public readonly ?string $providerSubscriptionId,
        public readonly ?string $providerPriceId,
        public readonly SubscriptionStatus $status,
        public readonly string $planCode,
        public readonly string $billingInterval = 'monthly',
        public readonly ?CarbonInterface $currentPeriodStart = null,
        public readonly ?CarbonInterface $currentPeriodEnd = null,
        public readonly bool $cancelAtPeriodEnd = false,
        public readonly ?CarbonInterface $canceledAt = null,
        public readonly ?CarbonInterface $trialEndsAt = null,
        public readonly ?CarbonInterface $gracePeriodEndsAt = null,
        public readonly ?array $metadata = null,
    ) {}
}
