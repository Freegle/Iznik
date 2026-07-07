<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache store that will be used by the
    | framework. This connection is utilized if another isn't explicitly
    | specified when running a cache operation inside the application.
    |
    */

    'default' => env('CACHE_STORE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Scheduler Lock Store
    |--------------------------------------------------------------------------
    |
    | Backend for the scheduler's withoutOverlapping() mutexes (see
    | App\Console\SchedulerMutex).
    |
    | 'flock' (default) uses OS-level file locks that auto-release on process
    | death. Caveat: our jobs all run ->runInBackground(), so the flock is held
    | only by the short-lived schedule:run tick — it is released seconds after a
    | job is *spawned*, NOT when it finishes, giving no real overlap protection
    | across ticks. A hung/slow job (e.g. during a DB stall) is therefore spawned
    | again every tick and can pile up.
    |
    | 'redis' (or any other DEFINED cache store name) uses a fail-open cache mutex
    | (App\Console\ResilientCacheEventMutex) pointed at that store: a TTL lock that
    | lives in the store (redis), so it persists across the per-tick schedule:run
    | processes and genuinely blocks a second spawn while the first is still
    | running, and is independent of the primary DB that can stall. The bounded
    | withoutOverlapping() expiries in routes/console.php cap the TTL so a killed
    | job self-heals in minutes, not the 24h default that originally motivated
    | flock. Note the TTL BOUNDS but does not fully eliminate pileup: a stall
    | longer than a job's expiry lets its lock expire mid-run and a second copy
    | start — capped by TTL/cadence (e.g. ~4/hour for a 15min-bucket everyMinute
    | job) rather than the unbounded every-tick pileup flock allowed.
    |
    | The value must be a store defined in 'stores' below; an unknown/mis-cased
    | value (e.g. 'Redis') falls back to flock with a logged warning rather than
    | crashing every overlap check. If the store is briefly unreachable the mutex
    | fails open (logs + runs the job) instead of aborting the whole schedule:run.
    |
    | Recommended in production (batch): LOCK_STORE=redis.
    |
    */

    'lock_store' => env('LOCK_STORE', 'flock'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    | Supported drivers: "array", "database", "file", "memcached",
    |                    "redis", "dynamodb", "octane",
    |                    "failover", "null"
    |
    */

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE'),
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        'memcached' => [
            'driver' => 'memcached',
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            'sasl' => [
                env('MEMCACHED_USERNAME'),
                env('MEMCACHED_PASSWORD'),
            ],
            'options' => [
                // Memcached::OPT_CONNECT_TIMEOUT => 2000,
            ],
            'servers' => [
                [
                    'host' => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

        'dynamodb' => [
            'driver' => 'dynamodb',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'table' => env('DYNAMODB_CACHE_TABLE', 'cache'),
            'endpoint' => env('DYNAMODB_ENDPOINT'),
        ],

        'octane' => [
            'driver' => 'octane',
        ],

        'failover' => [
            'driver' => 'failover',
            'stores' => [
                'database',
                'array',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing the APC, database, memcached, Redis, and DynamoDB cache
    | stores, there might be other applications using the same cache. For
    | that reason, you may prefix every cache key to avoid collisions.
    |
    */

    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-cache-'),

];
