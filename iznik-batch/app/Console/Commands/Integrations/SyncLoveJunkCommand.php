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
            if ($this->option('dry-run')) {
                $this->info('Dry run — no changes made.');
                return Command::SUCCESS;
            }

            Log::info('LoveJunk: Starting sync');

            $result = $service->sync();

            $this->info(sprintf(
                'Sent: %d, Edited: %d, Completed/Deleted: %d, Failed: %d',
                $result['sent'],
                $result['edited'],
                $result['completed_or_deleted'],
                $result['failed']
            ));

            Log::info('LoveJunk: Done', $result);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
