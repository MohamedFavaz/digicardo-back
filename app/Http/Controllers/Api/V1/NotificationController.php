<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NotificationResource;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NotificationController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * List notifications for authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $categoryParam = $request->query('category');
        $category = $categoryParam ? NotificationCategory::tryFrom(strtolower($categoryParam)) : null;
        $unreadOnly = $request->boolean('unread_only', false);
        $perPage = min((int) $request->query('per_page', 15), 50);

        $paginator = $this->notificationService->listForUser($user, $category, $unreadOnly, $perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'items' => NotificationResource::collection($paginator->items()),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'has_more' => $paginator->hasMorePages(),
                ],
            ],
        ]);
    }

    /**
     * Get unread notifications count.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->notificationService->unreadCount($request->user());

        return response()->json([
            'success' => true,
            'data' => [
                'count' => $count,
            ],
        ]);
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->notificationService->markAsRead($request->user(), $id);

        return response()->json([
            'success' => true,
            'data' => new NotificationResource($notification),
        ]);
    }

    /**
     * Mark all notifications as read for authenticated user.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $updated = $this->notificationService->markAllAsRead($request->user());

        return response()->json([
            'success' => true,
            'data' => [
                'updated' => $updated,
            ],
        ]);
    }

    /**
     * Delete a notification.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->notificationService->delete($request->user(), $id);

        return response()->json([
            'success' => true,
            'data' => null,
            'message' => 'Notification deleted successfully.',
        ]);
    }
}
