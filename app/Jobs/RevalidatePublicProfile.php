<?php

namespace App\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the Next.js frontend to rebuild a public profile page right away
 * (POST {app.frontend_url}/api/revalidate), so a saved change is live within
 * seconds instead of after the 60 s ISR window.
 *
 * Dispatched with dispatchAfterResponse(): it runs in the same PHP process after
 * the API response has been sent, so it needs no queue worker (works on shared
 * hosting) and adds no latency for the user. Failures are logged, never thrown —
 * the page still refreshes on its normal ISR schedule.
 */
class RevalidatePublicProfile
{
    use Dispatchable;

    public function __construct(
        public readonly string $username
    ) {}

    public function handle(): void
    {
        $secret = (string) config('services.next.revalidate_secret', '');
        $frontendUrl = rtrim((string) config('app.frontend_url', ''), '/');

        if ($secret === '' || $frontendUrl === '' || $this->username === '') {
            return;
        }

        try {
            $response = Http::timeout(max(1, (int) config('services.next.revalidate_timeout', 3)))
                ->acceptJson()
                ->withHeaders(['x-revalidate-secret' => $secret])
                ->post("{$frontendUrl}/api/revalidate", ['username' => $this->username]);

            if (!$response->successful()) {
                Log::warning('Public profile revalidation was rejected.', [
                    'username' => $this->username,
                    'status' => $response->status(),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('Public profile revalidation failed.', [
                'username' => $this->username,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
