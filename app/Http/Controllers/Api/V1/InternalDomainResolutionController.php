<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DomainService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InternalDomainResolutionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly DomainService $domainService,
        private readonly \App\Services\AgentSiteService $agentSites,
    ) {
    }

    /**
     * Internal server-to-server resolution endpoint for Next.js / OpenNext / Cloudflare edge workers.
     * Resolves incoming Host header to Digicardo profile credentials.
     *
     * Protected by internal service secret.
     */
    public function resolve(Request $request): JsonResponse
    {
        $expectedSecret = (string) (config('services.internal.secret') ?: env('INTERNAL_SERVICE_SECRET'));
        $providedSecret = (string) $request->header('x-internal-secret', $request->header('X-Internal-Secret', ''));

        if (empty($expectedSecret) || empty($providedSecret) || !hash_equals($expectedSecret, $providedSecret)) {
            throw new AccessDeniedHttpException('Unauthorized internal service access.');
        }

        $host = (string) $request->query('host', '');

        if (empty($host)) {
            return $this->errorResponse('INVALID_HOST', 'Hostname query parameter is required.', Response::HTTP_BAD_REQUEST);
        }

        $resolved = $this->domainService->resolveHost($host);
        if ($resolved) {
            return $this->successResponse(['type' => 'profile', ...$resolved]);
        }

        // An agent's domain: landing page at "/", the agent's customers' cards at "/{username}".
        $agentSite = $this->agentSites->resolveHost($host);
        if ($agentSite) {
            return $this->successResponse($agentSite);
        }

        throw new NotFoundHttpException('No active custom domain found for this host.');
    }
}
