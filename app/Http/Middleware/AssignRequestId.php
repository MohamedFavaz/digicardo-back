<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    /**
     * Handle an incoming request and assign a clean, traceable Request ID.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incomingId = $request->header('X-Request-ID') ?: $request->header('X-Correlation-ID');

        // Sanitize incoming ID or generate a new one
        if ($incomingId && is_string($incomingId) && strlen($incomingId) <= 64 && preg_match('/^[a-zA-Z0-9_\-]+$/', $incomingId)) {
            $requestId = $incomingId;
        } else {
            $requestId = 'req_' . Str::ulid();
        }

        // Store on request attributes
        $request->attributes->set('request_id', $requestId);

        // Inject into structured logging context
        Log::withContext([
            'request_id' => $requestId,
        ]);

        /** @var Response $response */
        $response = $next($request);

        // Attach X-Request-ID to the outgoing response headers
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
