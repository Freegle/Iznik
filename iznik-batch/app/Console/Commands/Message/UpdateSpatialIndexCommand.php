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
            if ($this->option('dry-run')) {
                $this->info('Dry run — no changes made.');
                return Command::SUCCESS;
            }

            Log::info('Starting message spatial index update');

            $count = $service->updateSpatialIndex();

            $this->info("{$count} row(s) processed");
            Log::info('Message spatial index update complete', ['count' => $count]);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
