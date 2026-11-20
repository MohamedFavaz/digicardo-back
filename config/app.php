<?php

return [

    'name' => env('APP_NAME', 'Digicardo'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    /*
    | Public Next.js site. Used for links in emails (password reset, verification,
    | contact notifications) and for public-page cache revalidation. Without this
    | key those links fell back to the API URL.
    */
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    /*
    | When false, /auth/register returns 403 and accounts are created only by admins
    | or agents. Read through config (not env()) so it works with `config:cache`.
    */
    'allow_public_registration' => (bool) env('ALLOW_PUBLIC_REGISTRATION', true),

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
