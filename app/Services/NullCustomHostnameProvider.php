<?php

namespace App\Services;

use App\Contracts\CustomHostnameProviderInterface;

class NullCustomHostnameProvider implements CustomHostnameProviderInterface
{
    public function provisionCustomHostname(string $domain): array
    {
        return [
            'success' => true,
            'ssl_status' => 'active',
            'status' => 'active',
            'details' => [
                'provider' => 'local_mock',
                'message' => 'Simulated edge provisioning active for local development.',
            ],
        ];
    }

    public function getCustomHostnameStatus(string $domain): array
    {
        return [
            'ssl_status' => 'active',
            'status' => 'active',
        ];
    }

    public function deleteCustomHostname(string $domain): bool
    {
        return true;
    }
}
