<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAgent
{
    /**
     * Handle an incoming request — verify the user holds the Agent role
     * and their account is in an active state.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // If currently impersonating, check if the session was initiated by an agent
        if ($request->hasSession() && $request->session()->has('impersonator_agent_id')) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Unauthenticated.',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        $role = $user->role;
        $isAgent = ($role instanceof UserRole && $role->isAgent()) || $role === 'agent';
        $isAdmin = ($role instanceof UserRole && $role->isAdmin()) || $role === 'admin';

        if (! $isAgent && ! $isAdmin) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'AGENT_PRIVILEGES_REQUIRED',
                    'message' => 'Unauthorized. Agent privileges are required to access this resource.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        // Ensure account is active (not suspended or banned)
        $status = $user->status;
        $isActive = ($status instanceof UserStatus && $status->isActive()) || $status === 'active';

        if (! $isActive) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'AGENT_ACCOUNT_SUSPENDED',
                    'message' => 'Your account has been suspended. Please contact the administrator.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}

