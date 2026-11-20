<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services & Subscriptions
    |--------------------------------------------------------------------------
    */

    'subscription' => [
        'provider' => env('SUBSCRIPTION_PROVIDER', 'null'), // 'null', 'stripe', 'lemonsqueezy', 'paddle'
        'grace_period_days' => (int) env('SUBSCRIPTION_GRACE_PERIOD_DAYS', 7),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'prices' => [
            'pro_monthly' => env('STRIPE_PRICE_PRO_MONTHLY', 'price_pro_monthly_test'),
            'pro_yearly' => env('STRIPE_PRICE_PRO_YEARLY', 'price_pro_yearly_test'),
            'business_monthly' => env('STRIPE_PRICE_BUSINESS_MONTHLY', 'price_business_monthly_test'),
            'business_yearly' => env('STRIPE_PRICE_BUSINESS_YEARLY', 'price_business_yearly_test'),
        ],
    ],

    'internal' => [
        'secret' => env('INTERNAL_SERVICE_SECRET'),
    ],

    // On-demand revalidation of public profile pages on the Next.js frontend
    // (POST {frontend_url}/api/revalidate). Leave the secret empty to disable.
    'next' => [
        'revalidate_secret' => env('NEXT_REVALIDATE_SECRET'),
        'revalidate_timeout' => (int) env('NEXT_REVALIDATE_TIMEOUT', 3),
    ],

];
