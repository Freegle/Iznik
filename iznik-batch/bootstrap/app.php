<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        \Sentry\Laravel\Integration::handles($exceptions);
    })->create();

// ParaTest support: use per-worker bootstrap cache to prevent race conditions.
// Without this, parallel workers corrupt shared services.php/packages.php files.
$bootstrapCachePath = getenv('PARATEST_BOOTSTRAP_CACHE');
if ($bootstrapCachePath && is_dir($bootstrapCachePath)) {
    $app->useBootstrapPath($bootstrapCachePath);
}

return $app;
