<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Account switches (admin/agent "open card as customer" and "back to admin") replace the browser's
 * session. A request that was already running on the OLD session can finish after the switch;
 * Laravel would then save that old session again and send its cookie, silently putting the browser
 * back on the old account (e.g. the admin's own dashboard opens instead of the customer's).
 *
 * The switch marks the old session as retired. When a request finishes on a retired session, its
 * session is not saved and the response is flagged so the BFF drops its cookies.
 */
class IgnoreRetiredSession
{
    public const HEADER = 'X-Session-Retired';

    public static function retire(?string $sessionId): void
    {
        if ($sessionId) {
            Cache::put("session_retired:{$sessionId}", true, now()->addMinutes(10));
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        $startId = $request->hasSession() ? $request->session()->getId() : null;

        $response = $next($request);

        // Only requests that are still on the session they started with; the switching request
        // itself has already moved to a new session id.
        if ($startId && $request->session()->getId() === $startId && Cache::get("session_retired:{$startId}")) {
            $request->session()->flush();
            $request->session()->invalidate();
            $response->headers->set(self::HEADER, '1');
        }

        return $response;
    }
}
