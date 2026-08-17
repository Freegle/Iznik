<?php

namespace App\Providers;

use App\Console\FlockEventMutex;
use App\Console\ResilientCacheEventMutex;
use App\Console\SchedulerMutex;
use App\Database\DeadlockRetryConnection;
use App\Database\FailoverConnectionFactory;
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
        // Replace the default ConnectionFactory with our FailoverConnectionFactory so
        // that when all read-replica hosts are unreachable, Laravel automatically falls
        // back to the write host instead of throwing. This must be registered before
        // the 'db' service provider resolves 'db.factory'.
        $this->app->singleton('db.factory', function ($app) {
            return new FailoverConnectionFactory($app);
        });

        // Use DeadlockRetryConnection for MySQL — automatically retries
        // deadlocked statements at autocommit level with exponential backoff.
        \Illuminate\Database\Connection::resolverFor('mysql', function ($connection, $database, $prefix, $config) {
            return new DeadlockRetryConnection($connection, $database, $prefix, $config);
        });

        // Register LokiService as a singleton.
        $this->app->singleton(LokiService::class, function ($app) {
            return new LokiService();
        });

        // How HostHealthCheck reaches the estate's hosts (monitor:scheduled-
        // outcomes). Bound here so tests can swap in a fake runner.
        $this->app->bind(\App\Monitoring\HostCommandRunner::class, function () {
            return new \App\Monitoring\SshHostCommandRunner(
                (string) config('freegle.monitoring.host_ssh_key', '/etc/monitoring-ssh-key'),
                (int) config('freegle.monitoring.host_ssh_timeout_seconds', 30),
            );
        });

        // Scheduler overlap mutex backend. Default (LOCK_STORE=flock) binds
        // FlockEventMutex — OS-level flock that auto-releases on process death.
        // Setting LOCK_STORE=redis (or another cache store) binds a TTL-based cache
        // mutex instead; routes/console.php points it at that store. See
        // App\Console\SchedulerMutex for why (flock does not protect
        // ->runInBackground() jobs across ticks). We bind our own
        // ResilientCacheEventMutex rather than the framework default so a store
        // outage fails open (logs + runs the job) instead of throwing out of the
        // schedule:run filter and killing the whole tick.
        if (SchedulerMutex::usesFlock()) {
            $this->app->singleton(EventMutex::class, function ($app) {
                return new FlockEventMutex();
            });
        } else {
            $this->app->singleton(EventMutex::class, function ($app) {
                return new ResilientCacheEventMutex($app->make('cache'));
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
        $this->stampLogsWithCommandLine();
    }

    /**
     * Stamp every log record (and the Sentry scope) with this process's
     * command line. A fatal error carries no PHP stack — 21-28 memory-
     * exhaustion fatals a day appeared in Sentry from 2026-08-12 as bare
     * "Allowed memory size exhausted at Connection.php:413" with no way to
     * tell which of the ~90 scheduled commands had died. With argv on the
     * record, the crash names its process.
     */
    protected function stampLogsWithCommandLine(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $argv = implode(' ', array_slice($_SERVER['argv'] ?? [], 0, 6));
        if ($argv === '') {
            return;
        }

        Log::getLogger()->pushProcessor(function ($record) use ($argv) {
            $record->extra['argv'] = $argv;

            return $record;
        });

        // Same guard as the other Sentry call sites (EmailHealthCommand):
        // the helpers aren't loaded in every environment.
        if (function_exists('\Sentry\configureScope')) {
            \Sentry\configureScope(function ($scope) use ($argv) {
                $scope->setTag('argv', mb_substr($argv, 0, 200));
            });
        }
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
