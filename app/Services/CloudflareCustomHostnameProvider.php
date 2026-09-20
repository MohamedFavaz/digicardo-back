<?php

namespace App\Services;

use App\Contracts\CustomHostnameProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudflareCustomHostnameProvider implements CustomHostnameProviderInterface
{
    private string $apiToken;
    private string $zoneId;
    private string $baseUrl = 'https://api.cloudflare.com/client/v4';

    public function __construct()
    {
        $this->apiToken = (string) config('services.cloudflare.api_token', env('CLOUDFLARE_API_TOKEN', ''));
        $this->zoneId = (string) config('services.cloudflare.zone_id', env('CLOUDFLARE_ZONE_ID', ''));
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiToken) && ! empty($this->zoneId);
    }

    public function provisionCustomHostname(string $domain): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'ssl_status' => 'pending',
                'status' => 'pending',
                'details' => ['error' => 'Cloudflare API credentials not configured.'],
            ];
        }

        try {
            $response = Http::withToken($this->apiToken)
                ->post("{$this->baseUrl}/zones/{$this->zoneId}/custom_hostnames", [
                    'hostname' => $domain,
                    'ssl' => [
                        'method' => 'http',
                        'type' => 'dv',
                        'settings' => ['min_tls_version' => '1.2'],
                    ],
                ]);

            if ($response->successful()) {
                $data = $response->json('result', []);
                return [
                    'success' => true,
                    'ssl_status' => $data['ssl']['status'] ?? 'pending',
                    'status' => $data['status'] ?? 'pending',
                    'details' => $data,
                ];
            }

            Log::error('Cloudflare custom hostname provisioning failed', [
                'domain' => $domain,
                'response' => $response->json(),
            ]);

            return [
                'success' => false,
                'ssl_status' => 'pending',
                'status' => 'failed',
                'details' => $response->json('errors', []),
            ];
        } catch (\Throwable $e) {
            Log::error('Cloudflare API exception', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'ssl_status' => 'pending',
                'status' => 'failed',
                'details' => ['error' => $e->getMessage()],
            ];
        }
    }

    public function getCustomHostnameStatus(string $domain): array
    {
        if (! $this->isConfigured()) {
            return [
                'ssl_status' => 'pending',
                'status' => 'pending',
            ];
        }

        try {
            $response = Http::withToken($this->apiToken)
                ->get("{$this->baseUrl}/zones/{$this->zoneId}/custom_hostnames", [
                    'hostname' => $domain,
                ]);

            if ($response->successful()) {
                $results = $response->json('result', []);
                if (! empty($results)) {
                    $item = $results[0];
                    return [
                        'ssl_status' => $item['ssl']['status'] ?? 'pending',
                        'status' => $item['status'] ?? 'pending',
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::error('Cloudflare API status check exception', ['error' => $e->getMessage()]);
        }

        return ['ssl_status' => 'pending', 'status' => 'pending'];
    }

    public function deleteCustomHostname(string $domain): bool
    {
        if (! $this->isConfigured()) {
            return true;
        }

        try {
            // Find hostname ID
            $lookup = Http::withToken($this->apiToken)
                ->get("{$this->baseUrl}/zones/{$this->zoneId}/custom_hostnames", [
                    'hostname' => $domain,
                ]);

            if ($lookup->successful()) {
                $results = $lookup->json('result', []);
                if (! empty($results) && isset($results[0]['id'])) {
                    $hostnameId = $results[0]['id'];
                    $deleteRes = Http::withToken($this->apiToken)
                        ->delete("{$this->baseUrl}/zones/{$this->zoneId}/custom_hostnames/{$hostnameId}");
                    return $deleteRes->successful();
                }
            }
        } catch (\Throwable $e) {
            Log::error('Cloudflare API delete exception', ['error' => $e->getMessage()]);
        }

        return false;
    }
}
