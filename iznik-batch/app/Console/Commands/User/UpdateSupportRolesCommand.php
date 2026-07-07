<?php

namespace App\Console\Commands\User;

use App\Services\UserManagementService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateSupportRolesCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'users:update-support-roles
                            {--dry-run : Show what would be changed without actually updating}';

    protected $description = 'Grant or remove Support Tools access based on team membership';

    public function handle(UserManagementService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            Log::info('Starting support roles update', ['dry_run' => $dryRun]);
            $this->info('Updating support roles...');

            $stats = $service->updateSupportRoles($dryRun);

            $this->info("Granted: {$stats['granted']}, Removed: {$stats['removed']}.");
            Log::info('Support roles update complete', $stats);

            return Command::SUCCESS;
        });
    }
}
