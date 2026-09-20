<?php

namespace App\Services;

use App\Models\Profile;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProfileSeoService
{
    public function __construct(
        protected PublicProfileCacheService $cacheService
    ) {}

    /**
     * Retrieve SEO configuration for authenticated user's profile.
     */
    public function getForUser(User $user): Profile
    {
        $profile = $user->profile()->with('ogImageMedia')->first();

        if (!$profile) {
            throw new NotFoundHttpException('Profile not found for this user.');
        }

        return $profile;
    }

    /**
     * Update SEO configuration for authenticated user's profile.
     */
    public function updateForUser(User $user, array $data): Profile
    {
        $profile = $user->profile;

        if (!$profile) {
            throw new NotFoundHttpException('Profile not found for this user.');
        }

        $fillable = [
            'seo_title',
            'seo_description',
            'seo_keywords',
            'og_title',
            'og_description',
            'og_image_media_id',
            'indexable',
        ];

        $updateData = array_intersect_key($data, array_flip($fillable));

        $profile->fill($updateData);
        $profile->version = (int) $profile->version + 1;
        $profile->save();

        // Invalidate public profile caches
        $this->cacheService->invalidateProfile($profile);

        return $profile->load('ogImageMedia');
    }
}
