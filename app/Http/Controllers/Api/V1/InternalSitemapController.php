<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SitemapProfileResource;
use App\Models\Profile;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class InternalSitemapController extends Controller
{
    use ApiResponse;

    /**
     * Retrieve paginated public, indexable profiles for XML sitemap generation.
     * Protected by internal service secret.
     */
    public function index(Request $request): JsonResponse
    {
        $expectedSecret = (string) (config('services.internal.secret') ?: env('INTERNAL_SERVICE_SECRET'));
        $providedSecret = (string) $request->header('x-internal-secret', $request->header('X-Internal-Secret', ''));

        if (empty($expectedSecret) || empty($providedSecret) || !hash_equals($expectedSecret, $providedSecret)) {
            throw new AccessDeniedHttpException('Unauthorized internal service access.');
        }

        $limit = min((int) $request->query('limit', 500), 1000);
        $cursor = $request->query('cursor');

        $query = Profile::query()
            ->where('is_public', true)
            ->where('indexable', true)
            ->whereNull('deleted_at')
            ->with(['primaryDomain'])
            ->orderBy('id', 'asc');

        if ($cursor) {
            $query->where('id', '>', $cursor);
        }

        $profiles = $query->take($limit)->get();

        $nextCursor = $profiles->count() === $limit ? $profiles->last()->id : null;

        return $this->successResponse(
            SitemapProfileResource::collection($profiles),
            [
                'count' => $profiles->count(),
                'has_more' => $nextCursor !== null,
                'next_cursor' => $nextCursor,
            ],
            Response::HTTP_OK,
            [
                'Cache-Control' => 'public, s-maxage=3600, stale-while-revalidate=86400',
            ]
        );
    }
}
