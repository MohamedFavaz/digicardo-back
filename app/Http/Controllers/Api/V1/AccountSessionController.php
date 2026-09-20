<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AccountSecurityService;
use App\Services\AccountSessionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountSessionController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AccountSessionService $sessionService,
        protected AccountSecurityService $securityService
    ) {}

    /**
     * List all active sessions for authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $currentSessionId = $request->hasSession() ? $request->session()->getId() : null;
        $sessions = $this->sessionService->listActiveSessions($request->user(), $currentSessionId);

        return $this->successResponse([
            'items' => $sessions,
        ]);
    }

    /**
     * Revoke an individual session.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $currentSessionId = $request->hasSession() ? $request->session()->getId() : null;
        $session = $this->sessionService->revokeSession($request->user(), $id, $currentSessionId);

        return $this->successResponse(
            ['revoked' => true, 'id' => $session->id],
            ['message' => 'Session revoked successfully.']
        );
    }

    /**
     * Revoke all other active sessions (requires recent authentication).
     */
    public function revokeOthers(Request $request): JsonResponse
    {
        // Enforce recent authentication step-up
        $this->securityService->ensureRecentAuth($request);

        $currentSessionId = $request->hasSession() ? $request->session()->getId() : 'current_' . bin2hex(random_bytes(8));
        $count = $this->sessionService->revokeOtherSessions($request->user(), $currentSessionId);

        return $this->successResponse(
            ['revoked_count' => $count],
            ['message' => "Successfully revoked {$count} other session(s)."]
        );
    }
}
