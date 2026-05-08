<?php

namespace App\Console\Commands\Integrations;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\LoveJunkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Traits\GracefulShutdown;

class SyncLoveJunkCommand extends Command
{
    use PreventsOverlapping;
    use GracefulShutdown;

    protected $signature = 'integrations:sync-lovejunk
                            {--dry-run : Show what would be synced without making changes}';

    protected $description = 'Sync Freegle offer messages with LoveJunk API';

    public function handle(LoveJunkService $service): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');
            return Command::SUCCESS;
        }

        try {
            $dryRun = (bool) $this->option('dry-run');

            if ($dryRun) {
                $this->info('Dry run — counting work but not calling LoveJunk API or writing.');
            } else {
                Log::info('LoveJunk: Starting sync');
            }

            $result = $service->sync($dryRun);

            $verb = $dryRun ? 'Would' : '';
            $this->info(sprintf(
                '%sSent: %d, %sEdited: %d, %sCompleted/Deleted: %d, Failed: %d',
                $verb, $result['sent'],
                $verb, $result['edited'],
                $verb, $result['completed_or_deleted'],
                $result['failed']
            ));

            if (!$dryRun) {
                Log::info('LoveJunk: Done', $result);
            }

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
