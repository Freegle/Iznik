<?php

namespace App\Console\Commands\User;

use App\Services\UserManagementService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateLastAccessCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'users:update-lastaccess
                            {--dry-run : Show what would be updated without actually changing}
                            {--full : Look at all history rather than only activity since the last run}';

    protected $description = 'Fallback update of user last access timestamps from chat messages and memberships';

    public function handle(UserManagementService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');
        $full = (bool) $this->option('full');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun, $full) {
            Log::info('Starting lastaccess fallback update', ['dry_run' => $dryRun, 'full' => $full]);
            $this->info('Updating user last access timestamps...');

            $stats = $service->updateLastAccess($dryRun, $full);

            $scope = $stats['full'] ? 'all history' : "activity since {$stats['since']}";
            $this->info("Updated lastaccess for {$stats['updated']} of {$stats['candidates']} candidate users ({$scope}).");
            Log::info('Lastaccess update complete', $stats);

            return Command::SUCCESS;
        });
    }
}
