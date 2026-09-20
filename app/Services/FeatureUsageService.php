<?php

namespace App\Services;

use App\Enums\DomainStatus;
use App\Enums\FeatureKey;
use App\Models\Profile;
use App\Models\ProfileDomain;
use App\Models\User;

class FeatureUsageService
{
    /**
     * Compute current database-authoritative usage for a given feature key and user.
     */
    public function usage(User $user, FeatureKey|string $feature): int
    {
        $key = $feature instanceof FeatureKey ? $feature : FeatureKey::tryFrom($feature);

        if (!$key) {
            return 0;
        }

        return match ($key) {
            FeatureKey::ProfileCount => $this->getProfileCount($user),
            FeatureKey::CustomDomainCount => $this->getCustomDomainCount($user),
            default => 0,
        };
    }

    /**
     * Count active (non-deleted) profiles owned by the user.
     */
    public function getProfileCount(User $user): int
    {
        return Profile::where('user_id', $user->id)->count();
    }

    /**
     * Count active/registered (non-deleted) custom domains owned by the user.
     */
    public function getCustomDomainCount(User $user): int
    {
        return ProfileDomain::where('user_id', $user->id)
            ->where('status', '!=', DomainStatus::Disabled->value)
            ->count();
    }
}
