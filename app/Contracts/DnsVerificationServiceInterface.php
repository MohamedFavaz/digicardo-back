<?php

namespace App\Contracts;

interface DnsVerificationServiceInterface
{
    /**
     * Retrieve all DNS TXT records for a specific hostname.
     *
     * @return list<string>
     */
    public function getTxtRecords(string $hostname): array;

    /**
     * Check if a specific hostname has a TXT record matching the expected verification token.
     */
    public function verifyToken(string $hostname, string $expectedToken): bool;
}
