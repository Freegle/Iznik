<?php

namespace App\Console\Commands\Group;

use App\Services\GroupMaintenanceService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FixSkewedLocationsCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'locations:fix-skewed
                            {--dry-run : Show what would be fixed without actually updating}';

    protected $description = 'Fix locations where latitude and longitude are swapped';

    public function handle(GroupMaintenanceService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            Log::info('Starting skewed locations fix', ['dry_run' => $dryRun]);

            $stats = $service->fixSkewedLocations($dryRun);

            $this->info("Fixed {$stats['locations_fixed']} locations, {$stats['messages_fixed']} messages.");
            Log::info('Skewed locations fix complete', $stats);

            return Command::SUCCESS;
        });
    }
}
