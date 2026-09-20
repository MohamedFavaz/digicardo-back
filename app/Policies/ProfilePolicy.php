<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Profile;
use App\Models\User;

class ProfilePolicy
{
    /**
     * Determine whether the user can view the profile management details.
     */
    public function view(User $user, Profile $profile): bool
    {
        return $user->id === $profile->user_id || $user->role === UserRole::Admin;
    }

    /**
     * Determine whether the user can update the profile.
     */
    public function update(User $user, Profile $profile): bool
    {
        return $user->id === $profile->user_id;
    }

    /**
     * Determine whether the user can delete the profile.
     */
    public function delete(User $user, Profile $profile): bool
    {
        return $user->id === $profile->user_id;
    }
}
