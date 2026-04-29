<?php

namespace App\Providers;

use App\Console\FlockEventMutex;
use App\Database\DeadlockRetryConnection;
use App\Listeners\CronJobStatusListener;
use App\Listeners\SpamCheckListener;
use App\Services\LokiService;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Process\ExecutableFinder;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Use DeadlockRetryConnection for MySQL — automatically retries
        // deadlocked statements at autocommit level with exponential backoff.
        \Illuminate\Database\Connection::resolverFor('mysql', function ($connection, $database, $prefix, $config) {
            return new DeadlockRetryConnection($connection, $database, $prefix, $config);
        });

        // Register LokiService as a singleton.
        $this->app->singleton(LokiService::class, function ($app) {
            return new LokiService();
        });

        // Register FlockEventMutex for process-aware scheduler locks.
        // Uses OS-level flock() which auto-releases on process death.
        if (config('cache.lock_store', 'flock') === 'flock') {
            $this->app->singleton(EventMutex::class, function ($app) {
                return new FlockEventMutex();
            });
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->checkRequiredBinaries();
        $this->registerSpamCheckListener();
        $this->blockMigrationsInProduction();
    }

    /**
     * Block artisan migrate commands in production.
     * Migrations on the Galera cluster must only be run manually by the operator.
     */
    protected function blockMigrationsInProduction(): void
    {
        if (!$this->app->environment('production')) {
            return;
        }

        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            $blocked = ['migrate', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback'];

            if (in_array($event->command, $blocked)) {
                $event->output->writeln('<error>BLOCKED: artisan ' . $event->command . ' is not allowed in production.</error>');
                $event->output->writeln('<comment>Migrations must be run manually by the operator on the database directly.</comment>');
                exit(1);
            }
        });
    }

    /**
     * Register spam check listener for outgoing emails.
     * Only active when SPAM_CHECK_ENABLED=true.
     */
    protected function registerSpamCheckListener(): void
    {
        Event::listen(MessageSending::class, SpamCheckListener::class);

        $cronListener = new CronJobStatusListener();
        Event::listen(ScheduledTaskStarting::class, [$cronListener, 'handleStarting']);
        Event::listen(ScheduledTaskFinished::class, [$cronListener, 'handleFinished']);
    }

    /**
     * Check that required external binaries are available.
     */
    protected function checkRequiredBinaries(): void
    {
        $finder = new ExecutableFinder();

        // Check for MJML (required for email rendering).
        // Look in common locations including local node_modules.
        $mjmlPaths = [
            base_path('node_modules/.bin'),
            '/usr/local/bin',
            '/usr/bin',
        ];

        $mjmlBinary = $finder->find('mjml', null, $mjmlPaths);

        if (!$mjmlBinary) {
            $message = 'MJML binary not found. Email templates will not render correctly. ' .
                'Install with: npm install mjml (in project root)';
            Log::warning($message);

            // Warning only - plain text emails (Mail::raw) still work without MJML.
            // Only MJML-based email templates will fail to render.
        }
    }
}
