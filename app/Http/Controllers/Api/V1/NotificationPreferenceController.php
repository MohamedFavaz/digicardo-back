<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\UpdateNotificationPreferencesRequest;
use App\Http\Resources\Api\V1\NotificationPreferenceResource;
use App\Services\NotificationPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function __construct(
        protected NotificationPreferenceService $preferenceService
    ) {}

    /**
     * Get authenticated user's notification preferences.
     */
    public function show(Request $request): JsonResponse
    {
        $prefs = $this->preferenceService->getPreferences($request->user());

        return response()->json([
            'success' => true,
            'data' => new NotificationPreferenceResource($prefs),
        ]);
    }

    /**
     * Update authenticated user's notification preferences.
     */
    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        $prefs = $this->preferenceService->updatePreferences($request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'data' => new NotificationPreferenceResource($prefs),
            'message' => 'Notification preferences updated successfully.',
        ]);
    }
}
