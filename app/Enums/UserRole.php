<?php

namespace App\Enums;

enum UserRole: string
{
    case User = 'user';
    case Moderator = 'moderator';
    case Admin = 'admin';

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }

    public function isModerator(): bool
    {
        return $this === self::Moderator || $this === self::Admin;
    }
}
