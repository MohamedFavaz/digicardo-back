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
        'partner',
        'partners',
        'partner-preview',
        'api',
        'www',
        'app',
        'dashboard',
        'setup', // first-run wizard route (frontend /setup)
        'admin-login', // admin sign-in route (frontend /admin-login)
        'agent-login', // agent sign-in route (frontend /agent-login)
        'agent', // agent portal (frontend /agent/*)
        'internal-admin-sign-in', // hidden admin sign-in's internal route
        'forgot-password',
        'reset-password',
        'verify-email',
        'account-deleted',
        'storage', // uploaded files are served from /storage/*
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
     * Validation rule (closure) that refuses reserved names: a card there would clash with a
     * page of the site itself, e.g. /login.
     */
    public static function notReservedRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && self::isReserved($value)) {
                $fail('This name is reserved for a Digicardo page. Please choose another.');
            }
        };
    }

    /**
     * A valid starting username made from a person's or business name: "Rohan Mehta" → "rohan-mehta".
     * Only a-z, 0-9 and hyphens, 3–30 characters, starting with a letter or digit; "user" when
     * nothing usable is left. (normalize() only lowercases, which kept spaces in auto-created cards.)
     */
    public static function fromName(string $name, int $maxLength = 30): string
    {
        $slug = trim(substr(\Illuminate\Support\Str::slug($name), 0, $maxLength), '-_');
        if ($slug === '' || !preg_match('/^[a-z0-9]/', $slug)) {
            return 'user';
        }

        return strlen($slug) < 3 ? str_pad($slug, 3, '0') : $slug;
    }

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
