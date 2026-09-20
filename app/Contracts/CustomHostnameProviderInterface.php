<?php

namespace App\Contracts;

interface CustomHostnameProviderInterface
{
    /**
     * Provision or register a custom hostname with the CDN/edge provider.
     *
     * @return array{success: bool, ssl_status: string, status: string, details?: array}
     */
    public function provisionCustomHostname(string $domain): array;

    /**
     * Retrieve edge status and SSL state for a custom hostname.
     *
     * @return array{ssl_status: string, status: string, error?: string}
     */
    public function getCustomHostnameStatus(string $domain): array;

    /**
     * Remove a custom hostname from edge routing.
     */
    public function deleteCustomHostname(string $domain): bool;
}
