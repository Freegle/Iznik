<?php

// Clear config cache before loading Laravel.
// When running tests, we need phpunit.xml env vars to take effect.
// If config is cached, Laravel ignores env vars and uses the cached values.
$configCachePath = __DIR__.'/../bootstrap/cache/config.php';
if (file_exists($configCachePath)) {
    @unlink($configCachePath);
}

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Force config to use test database
config(['database.connections.mysql.database' => 'iznik_batch_test']);

// Note: Mail driver is set via phpunit.xml <env name="MAIL_MAILER" value="array" force="true"/>
// This works because we clear the config cache above, so Laravel reads from env vars.

// Ensure test database exists
$pdo = new PDO('mysql:host='.env('DB_HOST'), env('DB_USERNAME'), env('DB_PASSWORD'));
$pdo->exec('CREATE DATABASE IF NOT EXISTS iznik_batch_test');

// Use migrate:fresh to guarantee a clean schema on every run.
// Using plain migrate (not fresh) caused accumulated state on the self-hosted CI
// runner whose Percona container is reused across runs: residual test data from
// previous runs was visible to new runs because the DB was never wiped.
echo "Running migrate:fresh on iznik_batch_test...\n";
$exitCode = \Illuminate\Support\Facades\Artisan::call('migrate:fresh', [
    '--database' => 'mysql',
    '--force' => true,
]);

if ($exitCode !== 0) {
    echo \Illuminate\Support\Facades\Artisan::output();
    die("Migration failed with exit code $exitCode\n");
}
echo "Migrations complete.\n";
