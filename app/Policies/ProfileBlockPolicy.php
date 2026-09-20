<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ProfileBlock;
use App\Models\User;

class ProfileBlockPolicy
{
    /**
     * Determine whether the user can view the block.
     */
    public function view(User $user, ProfileBlock $block): bool
    {
        return $user->id === $block->profile?->user_id || $user->role === UserRole::Admin;
    }

    /**
     * Determine whether the user can update the block.
     */
    public function update(User $user, ProfileBlock $block): bool
    {
        return $user->id === $block->profile?->user_id;
    }

    /**
     * Determine whether the user can delete the block.
     */
    public function delete(User $user, ProfileBlock $block): bool
    {
        return $user->id === $block->profile?->user_id;
    }
}
