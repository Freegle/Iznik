<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Worker Pool Configuration
    |--------------------------------------------------------------------------
    |
    | Configure bounded worker pools for various services. Each pool uses
    | Redis BLPOP for back pressure - when all permits are in use, new
    | requests will block until a permit becomes available.
    |
    */

    'mjml' => [
        // Maximum concurrent MJML compilations
        'max' => env('POOL_MJML_MAX', 20),

        // Timeout in seconds (0 = block forever)
        'timeout' => env('POOL_MJML_TIMEOUT', 30),

        // Throttle Sentry alerts to once per N seconds
        'sentry_throttle' => env('POOL_MJML_SENTRY_THROTTLE', 300),
    ],

    'digest' => [
        // Maximum concurrent digest workers
        'max' => env('POOL_DIGEST_MAX', 10),

        // Timeout in seconds (0 = block forever for digest work)
        'timeout' => env('POOL_DIGEST_TIMEOUT', 0),

        // Throttle Sentry alerts to once per N seconds
        'sentry_throttle' => env('POOL_DIGEST_SENTRY_THROTTLE', 300),
    ],
];
