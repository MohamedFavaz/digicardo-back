<?php

namespace App\Services;

use App\Contracts\DnsVerificationServiceInterface;
use Illuminate\Support\Facades\Log;

class DnsVerificationService implements DnsVerificationServiceInterface
{
    /**
     * Retrieve all DNS TXT records for a specific hostname.
     *
     * @return list<string>
     */
    public function getTxtRecords(string $hostname): array
    {
        try {
            // Silence warnings if domain does not resolve
            $records = @dns_get_record($hostname, DNS_TXT);

            if (!is_array($records)) {
                return [];
            }

            $txtRecords = [];
            foreach ($records as $record) {
                if (isset($record['txt']) && is_string($record['txt'])) {
                    $txtRecords[] = trim($record['txt']);
                } elseif (isset($record['entries']) && is_array($record['entries'])) {
                    foreach ($record['entries'] as $entry) {
                        if (is_string($entry)) {
                            $txtRecords[] = trim($entry);
                        }
                    }
                }
            }

            return $txtRecords;
        } catch (\Throwable $e) {
            Log::warning("DNS lookup failed for hostname: {$hostname}", [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Check if a specific hostname has a TXT record matching the expected verification token.
     */
    public function verifyToken(string $hostname, string $expectedToken): bool
    {
        $expectedValue = "Digicardo-verification={$expectedToken}";
        $records = $this->getTxtRecords($hostname);

        foreach ($records as $record) {
            if ($record === $expectedValue || $record === $expectedToken) {
                return true;
            }
        }

        return false;
    }
}
