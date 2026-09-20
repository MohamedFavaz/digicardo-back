<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
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
        $isAdmin = ($role instanceof UserRole && $role->isAdmin()) || $role === 'admin';

        if (! $isAdmin) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ADMIN_PRIVILEGES_REQUIRED',
                    'message' => 'Unauthorized. Administrator privileges are required to access this resource.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
