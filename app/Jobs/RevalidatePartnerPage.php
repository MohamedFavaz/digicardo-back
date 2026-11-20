<?php

namespace App\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the Next.js frontend to rebuild an agent's landing page (/partner/{slug}) right away,
 * so publishing, unpublishing, edits and the admin's "turn page off" show within seconds
 * instead of after the 60 s ISR window. Same transport and failure handling as
 * RevalidatePublicProfile (dispatchAfterResponse, no queue worker, never throws).
 */
class RevalidatePartnerPage
{
    use Dispatchable;

    public function __construct(
        public readonly string $slug
    ) {}

    public function handle(): void
    {
        $secret = (string) config('services.next.revalidate_secret', '');
        $frontendUrl = rtrim((string) config('app.frontend_url', ''), '/');

        if ($secret === '' || $frontendUrl === '' || $this->slug === '') {
            return;
        }

        try {
            $response = Http::timeout(max(1, (int) config('services.next.revalidate_timeout', 3)))
                ->acceptJson()
                ->withHeaders(['x-revalidate-secret' => $secret])
                ->post("{$frontendUrl}/api/revalidate", ['partner' => $this->slug]);

            if (! $response->successful()) {
                Log::warning('Partner page revalidation was rejected.', ['slug' => $this->slug, 'status' => $response->status()]);
            }
        } catch (Throwable $e) {
            Log::warning('Partner page revalidation failed.', ['slug' => $this->slug, 'error' => $e->getMessage()]);
        }
    }
}
