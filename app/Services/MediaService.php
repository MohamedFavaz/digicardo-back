<?php

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Models\Profile;
use App\Models\ProfileMedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MediaService
{
    private string $disk;

    public function __construct(
        protected PublicProfileCacheService $cacheService = new PublicProfileCacheService()
    ) {
        $this->disk = config('filesystems.media_disk', env('MEDIA_DISK', 'public'));
    }

    /**
     * Get the configured storage disk name.
     */
    public function getDisk(): string
    {
        return $this->disk;
    }

    /**
     * Extract dimensions from uploaded image file.
     *
     * @param UploadedFile $file
     * @return array{width: ?int, height: ?int}
     */
    private function getImageDimensions(UploadedFile $file): array
    {
        $dimensions = @getimagesize($file->getRealPath());
        if ($dimensions && is_array($dimensions)) {
            return [
                'width' => (int) ($dimensions[0] ?? null),
                'height' => (int) ($dimensions[1] ?? null),
            ];
        }

        return ['width' => null, 'height' => null];
    }

    /**
     * Store and replace user profile avatar safely.
     *
     * @param User $user
     * @param UploadedFile $file
     * @return ProfileMedia
     */
    public function storeAvatar(User $user, UploadedFile $file): ProfileMedia
    {
        $profile = $user->profile;
        if (!$profile) {
            throw new NotFoundHttpException('Profile not found.');
        }

        $mediaId = (string) Str::ulid();
        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        if ($extension === 'jpeg') $extension = 'jpg';

        $storagePath = "profiles/{$profile->id}/avatar/{$mediaId}.{$extension}";
        $dimensions = $this->getImageDimensions($file);

        // 1. Store the new file first
        Storage::disk($this->disk)->putFileAs(
            "profiles/{$profile->id}/avatar",
            $file,
            "{$mediaId}.{$extension}"
        );

        $oldMediaPaths = [];

        try {
            $media = DB::transaction(function () use ($user, $profile, $mediaId, $storagePath, $file, $dimensions, &$oldMediaPaths) {
                // Find existing avatar media records
                $existingAvatars = ProfileMedia::where('profile_id', $profile->id)
                    ->where('type', 'avatar')
                    ->get();

                foreach ($existingAvatars as $oldMedia) {
                    $oldMediaPaths[] = ['disk' => $oldMedia->disk, 'path' => $oldMedia->path];
                    $oldMedia->delete(); // soft-delete record
                }

                $media = ProfileMedia::create([
                    'id' => $mediaId,
                    'profile_id' => $profile->id,
                    'user_id' => $user->id,
                    'type' => 'avatar',
                    'disk' => $this->disk,
                    'path' => $storagePath,
                    'mime_type' => $file->getMimeType() ?: 'image/jpeg',
                    'size' => $file->getSize() ?: 0,
                    'width' => $dimensions['width'],
                    'height' => $dimensions['height'],
                    'alt_text' => $profile->display_name ?? $profile->username,
                ]);

                $profile->avatar_url = $media->url;
                $profile->version = ((int) $profile->version) + 1;
                $profile->save();

                return $media;
            });

            // 2. Clean up old avatar files from disk on successful commit
            foreach ($oldMediaPaths as $oldFile) {
                if (!empty($oldFile['path'])) {
                    Storage::disk($oldFile['disk'])->delete($oldFile['path']);
                }
            }

            // Invalidate public profile cache
            $this->cacheService->invalidateProfile($profile);

            return $media;
        } catch (\Throwable $e) {
            // Cleanup the newly uploaded file if database transaction failed
            Storage::disk($this->disk)->delete($storagePath);
            throw $e;
        }
    }

    /**
     * Delete user profile avatar.
     */
    public function deleteAvatar(User $user): void
    {
        $profile = $user->profile;
        if (!$profile) {
            throw new NotFoundHttpException('Profile not found.');
        }

        $oldMediaPaths = [];

        DB::transaction(function () use ($profile, &$oldMediaPaths) {
            $existingAvatars = ProfileMedia::where('profile_id', $profile->id)
                ->where('type', 'avatar')
                ->get();

            foreach ($existingAvatars as $oldMedia) {
                $oldMediaPaths[] = ['disk' => $oldMedia->disk, 'path' => $oldMedia->path];
                $oldMedia->delete();
            }

            $profile->avatar_url = null;
            $profile->version = ((int) $profile->version) + 1;
            $profile->save();
        });

        foreach ($oldMediaPaths as $oldFile) {
            if (!empty($oldFile['path'])) {
                Storage::disk($oldFile['disk'])->delete($oldFile['path']);
            }
        }

        $this->cacheService->invalidateProfile($profile);
    }

    /**
     * Store and replace user profile cover/background image.
     */
    public function storeCover(User $user, UploadedFile $file): ProfileMedia
    {
        $profile = $user->profile;
        if (!$profile) {
            throw new NotFoundHttpException('Profile not found.');
        }

        $mediaId = (string) Str::ulid();
        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        if ($extension === 'jpeg') $extension = 'jpg';

        $storagePath = "profiles/{$profile->id}/cover/{$mediaId}.{$extension}";
        $dimensions = $this->getImageDimensions($file);

        Storage::disk($this->disk)->putFileAs(
            "profiles/{$profile->id}/cover",
            $file,
            "{$mediaId}.{$extension}"
        );

        $oldMediaPaths = [];

        try {
            $media = DB::transaction(function () use ($user, $profile, $mediaId, $storagePath, $file, $dimensions, &$oldMediaPaths) {
                $existingCovers = ProfileMedia::where('profile_id', $profile->id)
                    ->where('type', 'cover')
                    ->get();

                foreach ($existingCovers as $oldMedia) {
                    $oldMediaPaths[] = ['disk' => $oldMedia->disk, 'path' => $oldMedia->path];
                    $oldMedia->delete();
                }

                $media = ProfileMedia::create([
                    'id' => $mediaId,
                    'profile_id' => $profile->id,
                    'user_id' => $user->id,
                    'type' => 'cover',
                    'disk' => $this->disk,
                    'path' => $storagePath,
                    'mime_type' => $file->getMimeType() ?: 'image/jpeg',
                    'size' => $file->getSize() ?: 0,
                    'width' => $dimensions['width'],
                    'height' => $dimensions['height'],
                ]);

                $profile->cover_url = $media->url;
                $profile->version = ((int) $profile->version) + 1;
                $profile->save();

                return $media;
            });

            foreach ($oldMediaPaths as $oldFile) {
                if (!empty($oldFile['path'])) {
                    Storage::disk($oldFile['disk'])->delete($oldFile['path']);
                }
            }

            // Invalidate public profile cache
            $this->cacheService->invalidateProfile($profile);

            return $media;
        } catch (\Throwable $e) {
            Storage::disk($this->disk)->delete($storagePath);
            throw $e;
        }
    }

    /**
     * Delete user profile cover image.
     */
    public function deleteCover(User $user): void
    {
        $profile = $user->profile;
        if (!$profile) {
            throw new NotFoundHttpException('Profile not found.');
        }

        $oldMediaPaths = [];

        DB::transaction(function () use ($profile, &$oldMediaPaths) {
            $existingCovers = ProfileMedia::where('profile_id', $profile->id)
                ->where('type', 'cover')
                ->get();

            foreach ($existingCovers as $oldMedia) {
                $oldMediaPaths[] = ['disk' => $oldMedia->disk, 'path' => $oldMedia->path];
                $oldMedia->delete();
            }

            $profile->cover_url = null;
            $profile->version = ((int) $profile->version) + 1;
            $profile->save();
        });

        foreach ($oldMediaPaths as $oldFile) {
            if (!empty($oldFile['path'])) {
                Storage::disk($oldFile['disk'])->delete($oldFile['path']);
            }
        }

        $this->cacheService->invalidateProfile($profile);
    }

    /**
     * Store content block image.
     */
    public function storeImage(User $user, UploadedFile $file, ?string $altText = null): ProfileMedia
    {
        $profile = $user->profile;
        if (!$profile) {
            throw new NotFoundHttpException('Profile not found.');
        }

        $mediaId = (string) Str::ulid();
        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        if ($extension === 'jpeg') $extension = 'jpg';

        $storagePath = "blocks/{$profile->id}/images/{$mediaId}.{$extension}";
        $dimensions = $this->getImageDimensions($file);

        Storage::disk($this->disk)->putFileAs(
            "blocks/{$profile->id}/images",
            $file,
            "{$mediaId}.{$extension}"
        );

        try {
            return DB::transaction(function () use ($user, $profile, $mediaId, $storagePath, $file, $dimensions, $altText) {
                return ProfileMedia::create([
                    'id' => $mediaId,
                    'profile_id' => $profile->id,
                    'user_id' => $user->id,
                    'type' => 'block_image',
                    'disk' => $this->disk,
                    'path' => $storagePath,
                    'mime_type' => $file->getMimeType() ?: 'image/jpeg',
                    'size' => $file->getSize() ?: 0,
                    'width' => $dimensions['width'],
                    'height' => $dimensions['height'],
                    'alt_text' => $altText,
                ]);
            });
        } catch (\Throwable $e) {
            Storage::disk($this->disk)->delete($storagePath);
            throw $e;
        }
    }

    /**
     * Store uploaded PDF document.
     */
    public function storeDocument(User $user, UploadedFile $file, ?string $title = null): ProfileMedia
    {
        $profile = $user->profile;
        if (!$profile) {
            throw new NotFoundHttpException('Profile not found.');
        }

        $mediaId = (string) Str::ulid();
        $storagePath = "documents/{$profile->id}/{$mediaId}.pdf";

        Storage::disk($this->disk)->putFileAs(
            "documents/{$profile->id}",
            $file,
            "{$mediaId}.pdf"
        );

        try {
            return DB::transaction(function () use ($user, $profile, $mediaId, $storagePath, $file, $title) {
                return ProfileMedia::create([
                    'id' => $mediaId,
                    'profile_id' => $profile->id,
                    'user_id' => $user->id,
                    'type' => 'document',
                    'disk' => $this->disk,
                    'path' => $storagePath,
                    'mime_type' => 'application/pdf',
                    'size' => $file->getSize() ?: 0,
                    'width' => null,
                    'height' => null,
                    'alt_text' => $title ?: $file->getClientOriginalName(),
                ]);
            });
        } catch (\Throwable $e) {
            Storage::disk($this->disk)->delete($storagePath);
            throw $e;
        }
    }

    /**
     * Delete an unreferenced media item owned by the user.
     */
    public function deleteMedia(User $user, ProfileMedia $media): void
    {
        if ($media->user_id !== $user->id) {
            throw new AccessDeniedHttpException('You do not own this media item.');
        }

        // Check if media is currently set as avatar or cover
        $profile = $user->profile;
        if ($profile && ($profile->avatar_url === $media->url || $profile->cover_url === $media->url)) {
            throw new ConflictException('Cannot delete media item while it is set as active profile avatar or cover.');
        }

        DB::transaction(function () use ($media) {
            $media->delete();
        });

        Storage::disk($media->disk)->delete($media->path);
    }
}
