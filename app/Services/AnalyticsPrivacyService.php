<?php

namespace App\Services;

class AnalyticsPrivacyService
{
    /**
     * Generate a privacy-preserving daily rotating visitor fingerprint hash.
     * Uses HMAC-SHA256 with the application key and today's date as a rotating salt.
     * The raw IP and User-Agent are NEVER stored in the database.
     */
    public function generateVisitorHash(string $ip, ?string $userAgent): string
    {
        $normalizedIp = trim($ip);
        $limitedUa = substr(strtolower(trim($userAgent ?? '')), 0, 120);

        // Daily rotating salt prevents long-term tracking across multiple days
        $salt = config('app.key', 'Digicardo-analytics-salt') . date('Y-m-d');

        return hash_hmac('sha256', $normalizedIp . '|' . $limitedUa, $salt);
    }

    /**
     * Normalize a referrer URL to its safe domain/hostname or 'direct'.
     * Strips query parameters, search terms, and full path URLs to prevent PII leakage.
     */
    public function normalizeReferrer(?string $referrer): string
    {
        if (empty($referrer)) {
            return 'direct';
        }

        $parsed = parse_url(trim($referrer));
        if (!$parsed || empty($parsed['host'])) {
            return 'direct';
        }

        $host = strtolower($parsed['host']);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return substr($host, 0, 100);
    }

    /**
     * Identify automated health checks, uptime monitors, or headless bots.
     */
    public function isKnownBot(?string $userAgent): bool
    {
        if (empty($userAgent)) {
            return false;
        }

        $ua = strtolower($userAgent);
        $botPatterns = [
            'bot',
            'spider',
            'crawler',
            'uptime',
            'pingdom',
            'curl',
            'postman',
            'kube-probe',
            'headlessextension',
        ];

        foreach ($botPatterns as $pattern) {
            if (str_contains($ua, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
