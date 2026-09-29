<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // The image object store (Katapult / any S3-compatible bucket). Written by
        // images:push-spool and images:migrate-legacy; read by nobody here - the
        // edge nginx serves it straight to the image resizer. url is the public
        // base of the bucket, the same value frontend-nginx proxies GETs to.
        'images' => [
            'driver' => 's3',
            'key' => env('IMAGE_STORE_KEY'),
            'secret' => env('IMAGE_STORE_SECRET'),
            'region' => env('IMAGE_STORE_REGION', 'us-east-1'),
            'bucket' => env('IMAGE_STORE_BUCKET', 'images'),
            'url' => env('IMAGE_STORE_PUBLIC_URL'),
            'endpoint' => env('IMAGE_STORE_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('IMAGE_STORE_PATH_STYLE', true),
            // Newer AWS SDKs add CRC checksum headers to every PUT by default and
            // S3-compatible stores that do not know them reject the request.
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'throw' => true,
            'report' => false,
        ],

        // tusd's local upload spool on the edge host (the tusd-spool compose
        // volume). Every upload lands here first; the pusher moves completed
        // ones to the images disk and deletes them.
        'tusd-spool' => [
            'driver' => 'local',
            'root' => env('TUSD_SPOOL_PATH', '/spool'),
            'throw' => true,
            'report' => false,
        ],

        // The legacy NFS upload store, bound read-only into batch-prod for the
        // duration of the migration. Never listed - see the migrator.
        'tusd-legacy' => [
            'driver' => 'local',
            'root' => env('TUSD_LEGACY_PATH', '/images'),
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
