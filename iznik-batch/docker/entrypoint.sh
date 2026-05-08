#!/bin/bash
set -e

echo "=== Laravel Batch Container Starting ==="

# Laravel configuration comes from environment variables in docker-compose.yml
# No .env file is needed - APP_KEY and all other config is passed via environment
echo "Using environment variables for configuration (no .env file needed)"

# Always clear service/package manifests before any composer/artisan bootstrap.
# These files can be stale across environments and reference dev-only providers.
rm -f /var/www/html/bootstrap/cache/services.php
rm -f /var/www/html/bootstrap/cache/packages.php

# Always run composer install to ensure vendor matches composer.lock.
# The host bind-mount directory may contain a stale vendor/ from a previous run
# (git clean -fd skips gitignored paths, and /vendor is gitignored). Running
# composer install unconditionally is fast when nothing changed and ensures
# newly added packages are always present.
echo "Installing/updating PHP dependencies..."
composer install --no-interaction --prefer-dist --optimize-autoloader

# Wait for database server to be ready (connect without specifying database)
echo "Waiting for database server..."
maxTries=30
while [ $maxTries -gt 0 ]; do
    if php -r "try { new PDO('mysql:host='.getenv('DB_HOST'), getenv('DB_USERNAME'), getenv('DB_PASSWORD')); echo 'OK'; exit(0); } catch(Exception \$e) { exit(1); }" 2>/dev/null; then
        echo "Database server is ready!"
        break
    fi
    maxTries=$((maxTries - 1))
    echo "Waiting for database server... ($maxTries tries remaining)"
    sleep 2
done

if [ $maxTries -eq 0 ]; then
    echo "Could not connect to database server!"
    exit 1
fi

# Create the Laravel databases if they don't exist (main + test)
echo "Ensuring databases exist..."
php -r "
\$pdo = new PDO('mysql:host='.getenv('DB_HOST'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
\$pdo->exec('CREATE DATABASE IF NOT EXISTS '.getenv('DB_DATABASE'));
\$pdo->exec('CREATE DATABASE IF NOT EXISTS '.getenv('DB_DATABASE').'_test');
echo 'Database ready: '.getenv('DB_DATABASE').PHP_EOL;
echo 'Test database ready: '.getenv('DB_DATABASE').'_test'.PHP_EOL;
"

# Run migrations only if explicitly enabled (disabled by default for safety).
# Set RUN_MIGRATIONS=true in docker-compose.yml for development environments.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "Running migrations (RUN_MIGRATIONS=true)..."
    migrationAttempts=3
    while [ $migrationAttempts -gt 0 ]; do
        if php artisan migrate --force 2>&1; then
            echo "Migrations completed successfully."
            break
        fi
        migrationAttempts=$((migrationAttempts - 1))
        if [ $migrationAttempts -gt 0 ]; then
            echo "Migration failed, retrying... ($migrationAttempts attempts remaining)"
            sleep 5
        else
            echo "WARNING: Migrations failed after all retries, continuing anyway..."
        fi
    done
else
    echo "Skipping migrations (RUN_MIGRATIONS not set to true)."
    echo "To run migrations manually: docker exec freegle-batch php artisan migrate --force"
fi

# Ensure storage directories exist with correct permissions
echo "Ensuring storage directories exist..."
mkdir -p /var/www/html/storage/framework/{cache,sessions,views,command-locks,scheduler-locks}
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/storage/spool/mail/{pending,sending,sent,failed}
chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

# Clear environment-specific bootstrap cache files.
# These contain resolved paths/env values that differ between environments.
# services.php and packages.php are handled at startup before bootstrap.
echo "Cleaning environment-specific bootstrap cache..."
rm -f /var/www/html/bootstrap/cache/config.php
rm -f /var/www/html/bootstrap/cache/routes-v7.php
rm -f /var/www/html/bootstrap/cache/events.php

# Clear application caches
echo "Clearing application caches..."
php artisan cache:clear || true
php artisan config:clear || true

echo "=== Laravel batch container ready ==="

# Create ready marker file to signal healthcheck that startup is complete
touch /tmp/laravel-ready

# In CI mode, don't start supervisor.
# Supervisor launches multiple processes (scheduler, queue workers, mail spooler) that all
# bootstrap Laravel simultaneously. If services.php needs regeneration, multiple processes
# writing to it at once corrupts the file. See: https://github.com/orchestral/testbench/issues/202
# By skipping supervisor in CI, we eliminate this race condition entirely.
if [ "${CI:-false}" = "true" ]; then
    echo "CI mode: skipping supervisor to prevent Laravel bootstrap race conditions"
    exec sleep infinity
fi

exec "$@"
