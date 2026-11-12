<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AgentProfileController extends Controller
{
    use ApiResponse;

    /**
     * Get the authenticated agent's profile details.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load(['agentQuota', 'commissionConfig']);

        return $this->successResponse([
            'user' => $user,
            'quota' => $user->agentQuota,
            'commission_config' => $user->commissionConfig,
            'total_cards' => $user->agentCards()->count(),
        ]);
    }

    /**
     * Update the authenticated agent's basic profile details.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|min:2|max:100',
            'mobile' => 'nullable|string|max:20',
        ]);

        $user->update([
            'name' => trim($validated['name']),
            'mobile' => isset($validated['mobile']) ? trim($validated['mobile']) : null,
        ]);

        $user->load(['agentQuota', 'commissionConfig']);

        return $this->successResponse([
            'user' => $user,
            'quota' => $user->agentQuota,
            'commission_config' => $user->commissionConfig,
        ], ['message' => 'Agent profile updated successfully.']);
    }

    /**
     * Upload and update the authenticated agent's profile avatar photo.
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        /** @var User $user */
        $user = $request->user();
        $file = $request->file('image');

        $disk = config('filesystems.media_disk', 'public');
        $mediaId = (string) Str::ulid();
        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $directory = "avatars/agents/{$user->id}";
        $filename = "{$mediaId}.{$extension}";
        $storagePath = "{$directory}/{$filename}";

        // Delete old avatar file if present on the same disk
        if ($user->avatar_url) {
            $oldPath = parse_url($user->avatar_url, PHP_URL_PATH);
            if ($oldPath && str_contains($oldPath, 'storage/')) {
                $relativeOldPath = ltrim(explode('storage/', $oldPath)[1] ?? '', '/');
                if ($relativeOldPath && Storage::disk($disk)->exists($relativeOldPath)) {
                    Storage::disk($disk)->delete($relativeOldPath);
                }
            }
        }

        // Store new avatar file
        Storage::disk($disk)->putFileAs($directory, $file, $filename);

        // Generate public URL
        $customCdnUrl = config("filesystems.disks.{$disk}.url");
        if (!empty($customCdnUrl)) {
            $avatarUrl = rtrim($customCdnUrl, '/') . '/' . ltrim($storagePath, '/');
        } else {
            try {
                $avatarUrl = Storage::disk($disk)->url($storagePath);
            } catch (\Throwable) {
                $avatarUrl = '/storage/' . ltrim($storagePath, '/');
            }
        }

        $user->avatar_url = $avatarUrl;
        $user->save();

        return $this->successResponse([
            'avatar_url' => $avatarUrl,
            'user' => $user->fresh(),
        ], ['message' => 'Profile avatar uploaded successfully.']);
    }

    /**
     * Remove the authenticated agent's profile avatar photo.
     */
    public function deleteAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->avatar_url) {
            $disk = config('filesystems.media_disk', 'public');
            $oldPath = parse_url($user->avatar_url, PHP_URL_PATH);
            if ($oldPath && str_contains($oldPath, 'storage/')) {
                $relativeOldPath = ltrim(explode('storage/', $oldPath)[1] ?? '', '/');
                if ($relativeOldPath && Storage::disk($disk)->exists($relativeOldPath)) {
                    Storage::disk($disk)->delete($relativeOldPath);
                }
            }

            $user->avatar_url = null;
            $user->save();
        }

        return $this->successResponse([
            'avatar_url' => null,
            'user' => $user->fresh(),
        ], ['message' => 'Profile avatar removed successfully.']);
    }

    /**
     * Change the authenticated agent's password.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        /** @var User $user */
        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_CURRENT_PASSWORD',
                    'message' => 'The current password provided is incorrect.',
                    'details' => [
                        'current_password' => ['The current password provided is incorrect.'],
                    ],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        return $this->successResponse(null, ['message' => 'Password updated successfully.']);
    }
}

