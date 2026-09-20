<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Queue Health Thresholds
    |--------------------------------------------------------------------------
    |
    | Thresholds used to determine if queue health is degraded or critical.
    |
    */
    'queue' => [
        'pending_degraded_threshold' => (int) env('QUEUE_DEGRADED_THRESHOLD', 50),
        'pending_critical_threshold' => (int) env('QUEUE_CRITICAL_THRESHOLD', 500),
        'max_age_degraded_seconds' => (int) env('QUEUE_MAX_AGE_DEGRADED_SECONDS', 180), // 3 minutes
        'max_age_critical_seconds' => (int) env('QUEUE_MAX_AGE_CRITICAL_SECONDS', 900), // 15 minutes
        'failed_last_hour_degraded' => (int) env('QUEUE_FAILED_LAST_HOUR_DEGRADED', 5),
        'failed_last_hour_critical' => (int) env('QUEUE_FAILED_LAST_HOUR_CRITICAL', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Health Check
    |--------------------------------------------------------------------------
    |
    | Timeout settings and probes for component health checks.
    |
    */
    'health' => [
        'timeout_seconds' => (int) env('HEALTH_CHECK_TIMEOUT', 3),
        'disk_usage_warning_percent' => (int) env('HEALTH_DISK_WARNING_PERCENT', 85),
    ],

    /*
    |--------------------------------------------------------------------------
    | Operational Metrics
    |--------------------------------------------------------------------------
    |
    | Cache TTL for operational metrics to prevent slow database queries.
    |
    */
    'metrics' => [
        'cache_ttl_seconds' => (int) env('METRICS_CACHE_TTL', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Tracking Driver
    |--------------------------------------------------------------------------
    |
    | Supported drivers: "null", "log", "sentry"
    |
    */
    'error_tracking' => [
        'driver' => env('ERROR_TRACKING_DRIVER', 'null'),
        'dsn' => env('ERROR_TRACKING_DSN'),
        'sample_rate' => (float) env('ERROR_TRACKING_SAMPLE_RATE', 1.0),
    ],
];
