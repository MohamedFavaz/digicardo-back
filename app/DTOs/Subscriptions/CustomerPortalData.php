<?php

namespace App\DTOs\Subscriptions;

class CustomerPortalData
{
    public function __construct(
        public readonly string $portalUrl,
        public readonly string $provider = 'null',
    ) {}

    public function toArray(): array
    {
        return [
            'portal_url' => $this->portalUrl,
            'provider' => $this->provider,
        ];
    }
}
