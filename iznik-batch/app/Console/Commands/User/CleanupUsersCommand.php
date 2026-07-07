<?php

namespace App\Console\Commands\User;

use App\Services\UserManagementService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupUsersCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'users:cleanup
                            {--dry-run : Show what would be affected without making changes}';

    protected $description = 'Clean up inactive and deleted users: Yahoo Groups removal, inactive user forget, GDPR grace period processing, fully forgotten user deletion';

    public function handle(UserManagementService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE — no data will be modified.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            $this->info('Running user cleanup...');

            $stats = $service->cleanupUsers($dryRun);

            $prefix = $dryRun ? 'Would ' : '';

            $this->newLine();
            $this->table(
                ['Operation', 'Count'],
                [
                    [$prefix . 'Delete Yahoo Groups users', number_format($stats['yahoo_users_deleted'])],
                    [$prefix . 'Forget inactive users', number_format($stats['inactive_users_forgotten'])],
                    [$prefix . 'Process GDPR forgets', number_format($stats['gdpr_forgets_processed'])],
                    [$prefix . 'Delete fully forgotten users', number_format($stats['forgotten_users_deleted'])],
                ]
            );

            Log::info('User cleanup completed', $stats);

            return Command::SUCCESS;
        });
    }
}
