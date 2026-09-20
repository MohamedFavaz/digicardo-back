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
        private readonly DomainService $domainService
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
        $expectedSecret = (string) config('services.internal.secret', env('INTERNAL_SERVICE_SECRET', 'local-internal-service-secret'));
        $providedSecret = (string) $request->header('x-internal-secret', $request->header('X-Internal-Secret', ''));

        if (empty($providedSecret) || !hash_equals($expectedSecret, $providedSecret)) {
            throw new AccessDeniedHttpException('Unauthorized internal service access.');
        }

        $host = (string) $request->query('host', '');

        if (empty($host)) {
            return $this->errorResponse('INVALID_HOST', 'Hostname query parameter is required.', Response::HTTP_BAD_REQUEST);
        }

        $resolved = $this->domainService->resolveHost($host);

        if (!$resolved) {
            throw new NotFoundHttpException('No active custom domain profile found for this host.');
        }

        return $this->successResponse($resolved);
    }
}
