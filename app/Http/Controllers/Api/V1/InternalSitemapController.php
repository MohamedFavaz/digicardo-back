<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SitemapProfileResource;
use App\Models\Profile;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalSitemapController extends Controller
{
    use ApiResponse;

    /**
     * Retrieve paginated public, indexable profiles for XML sitemap generation.
     */
    public function index(Request $request): JsonResponse
    {
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
