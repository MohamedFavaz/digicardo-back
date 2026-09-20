<?php

namespace App\Services;

use App\Models\Profile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PublicProfileCacheService
{
    /**
     * Invalidate all public caches associated with a profile across local cache and edge layers.
     */
    public function invalidateProfile(Profile|string $profileOrUsername): void
    {
        $username = $profileOrUsername instanceof Profile
            ? strtolower($profileOrUsername->username)
            : strtolower($profileOrUsername);

        // 1. Invalidate local application cache
        Cache::forget("profile:{$username}");
        Cache::forget("public_profile:{$username}");

        if ($profileOrUsername instanceof Profile) {
            Cache::forget("profile_id:{$profileOrUsername->id}");

            // Invalidate associated primary custom domain cache if present
            if ($profileOrUsername->primaryDomain) {
                Cache::forget("domain:{$profileOrUsername->primaryDomain->normalized_domain}");
            }
        }

        Log::info("Public profile cache invalidated for [{$username}].");
    }
}
