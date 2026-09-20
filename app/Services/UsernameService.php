<?php

namespace App\Services;

class UsernameService
{
    /**
     * Regex pattern for valid username:
     * - Length 3 to 30 characters
     * - Must begin with lowercase letter or number
     * - May contain lowercase letters, numbers, hyphens, and underscores
     * - Cannot start with hyphen or underscore
     */
    public const USERNAME_REGEX = '/^[a-z0-9][a-z0-9_-]{2,29}$/';

    /**
     * Reserved username slugs protected from user registration.
     *
     * @var array<int, string>
     */
    public const RESERVED_USERNAMES = [
        'admin',
        'api',
        'www',
        'app',
        'dashboard',
        'login',
        'register',
        'logout',
        'settings',
        'analytics',
        'profile',
        'support',
        'help',
        'pricing',
        'about',
        'terms',
        'privacy',
        'favicon.ico',
        'robots.txt',
        'sitemap.xml',
        'p',
        'auth',
        'health',
        'explore',
        'docs',
        'assets',
        'static',
        'null',
        'undefined',
    ];

    /**
     * Normalize username string (trimmed and lowercased).
     */
    public static function normalize(string $username): string
    {
        return strtolower(trim($username));
    }

    /**
     * Check if the given username matches format constraints.
     */
    public static function isValidFormat(string $username): bool
    {
        $normalized = self::normalize($username);
        return (bool) preg_match(self::USERNAME_REGEX, $normalized);
    }

    /**
     * Check if the username is in the reserved keywords list.
     */
    public static function isReserved(string $username): bool
    {
        $normalized = self::normalize($username);
        return in_array($normalized, self::RESERVED_USERNAMES, true);
    }
}
