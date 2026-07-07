<?php

// When PHPUnit runs tests in separate processes (#[RunTestsInSeparateProcesses]),
// this bootstrap is executed in each child process as well as the main process.
// Running migrations 41+ times would be catastrophically slow.
//
// Detection: the main process writes a PID lock file after completing migrations.
// Child processes (spawned by the main process) find the lock file and see the
// main process is still alive → skip the heavy setup. The lock expires automatically
// when the main process exits, so the next test run always gets a fresh migration.
$migrationLockFile = sys_get_temp_dir().'/phpunit_iznik_migrations_'.md5(__DIR__).'.lock';

$runSetup = true;
if (file_exists($migrationLockFile)) {
    $lockData = @json_decode(@file_get_contents($migrationLockFile), true);
    if (
        isset($lockData['pid']) &&
        function_exists('posix_kill') &&
        posix_kill((int) $lockData['pid'], 0)
    ) {
        $runSetup = false;
    }
}

// Clear config cache before loading Laravel.
// When running tests, we need phpunit.xml env vars to take effect.
// If config is cached, Laravel ignores env vars and uses the cached values.
$configCachePath = __DIR__.'/../bootstrap/cache/config.php';
if (file_exists($configCachePath)) {
    @unlink($configCachePath);
}

require __DIR__.'/../vendor/autoload.php';

if (! $runSetup) {
    // Child process: autoloader is all that's needed. setUp() creates the app.
    return;
}

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

// Write lock file so child processes (spawned by this process) skip migrations.
file_put_contents($migrationLockFile, json_encode(['pid' => getmypid(), 'time' => time()]));
