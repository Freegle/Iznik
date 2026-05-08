<?php

namespace App\Console\Commands\Message;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\MessageSpatialService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateSpatialIndexCommand extends Command
{
    use PreventsOverlapping;
    use GracefulShutdown;

    protected $signature = 'messages:update-spatial-index
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Update the spatial index for recent messages';

    public function handle(MessageSpatialService $service): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');
            return Command::SUCCESS;
        }

        try {
            $dryRun = (bool) $this->option('dry-run');

            if ($dryRun) {
                $this->info('Dry run — counting changes but not writing.');
            } else {
                Log::info('Starting message spatial index update');
            }

            $stats = $service->updateSpatialIndex($dryRun);

            $verb = $dryRun ? 'would update' : 'updated';
            $this->info("{$verb} {$stats['total']} entries:");
            $this->line(sprintf('  upserted_recent:      %d', $stats['upserted_recent']));
            $this->line(sprintf('  outcomes_updated:     %d', $stats['outcomes_updated']));
            $this->line(sprintf('  removed_deleted:      %d', $stats['removed_deleted']));
            $this->line(sprintf('  removed_old:          %d', $stats['removed_old']));
            $this->line(sprintf('  removed_non_approved: %d', $stats['removed_non_approved']));

            if (!$dryRun) {
                Log::info('Message spatial index update complete', $stats);
            }

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
