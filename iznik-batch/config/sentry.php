<?php

/**
 * Sentry Laravel SDK configuration file.
 *
 * Configured to capture only exceptions and error logs.
 * Performance tracing and breadcrumbs are disabled to minimize noise.
 *
 * @see https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/
 */
return [

    // @see https://docs.sentry.io/product/sentry-basics/dsn-explainer/
    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    // The release version of your application.
    'release' => env('SENTRY_RELEASE'),

    // When left empty or `null` the Laravel environment will be used (usually discovered from `APP_ENV` in your `.env`).
    'environment' => env('SENTRY_ENVIRONMENT'),

    // Capture all exceptions (1.0 = 100%).
    'sample_rate' => 1.0,

    // Disable performance tracing entirely.
    'traces_sample_rate' => 0.0,

    // Disable profiling.
    'profiles_sample_rate' => 0.0,

    // Disable Sentry logs feature (we use Laravel's logging instead).
    'enable_logs' => false,

    // Don't send PII by default.
    'send_default_pii' => false,

    // Disable all breadcrumbs - we only want exceptions and error logs.
    'breadcrumbs' => [
        'logs' => false,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'queue_info' => false,
        'command_info' => false,
        'http_client_requests' => false,
        'notifications' => false,
    ],

    // Disable all performance tracing.
    'tracing' => [
        'queue_job_transactions' => false,
        'queue_jobs' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'sql_origin' => false,
        'views' => false,
        'livewire' => false,
        'http_client_requests' => false,
        'cache' => false,
        'redis_commands' => false,
        'redis_origin' => false,
        'notifications' => false,
        'missing_routes' => false,
        'continue_after_response' => false,
        'default_integrations' => false,
    ],

];

