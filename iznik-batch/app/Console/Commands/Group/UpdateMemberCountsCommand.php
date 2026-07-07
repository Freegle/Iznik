<?php

namespace App\Console\Commands\Group;

use App\Services\GroupMaintenanceService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateMemberCountsCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'groups:update-counts
                            {--dry-run : Show what would be updated without actually changing}';

    protected $description = 'Update member and moderator counts for all groups';

    public function handle(GroupMaintenanceService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            Log::info('Starting member count update', ['dry_run' => $dryRun]);
            $this->info('Updating group member counts...');

            $stats = $service->updateMemberCounts($dryRun);

            $this->info("Updated counts for {$stats['groups_updated']} groups.");
            Log::info('Member count update complete', $stats);

            return Command::SUCCESS;
        });
    }
}
