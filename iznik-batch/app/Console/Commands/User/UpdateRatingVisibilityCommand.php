<?php

namespace App\Console\Commands\User;

use App\Services\UserManagementService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateRatingVisibilityCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'users:update-ratings
                            {--dry-run : Show what would be updated without actually changing}';

    protected $description = 'Update rating visibility based on chat interactions';

    public function handle(UserManagementService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            Log::info('Starting rating visibility update', ['dry_run' => $dryRun]);

            $stats = $service->updateRatingVisibility('1 hour ago', $dryRun);

            $this->info("Processed {$stats['processed']} ratings: {$stats['made_visible']} made visible, {$stats['made_hidden']} hidden.");
            Log::info('Rating visibility update complete', $stats);

            return Command::SUCCESS;
        });
    }
}
