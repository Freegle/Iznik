<?php

namespace App\Console\Commands\Integrations;

use App\Services\RestartProjectService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class SyncRestartProjectCommand extends Command
{
    protected $signature = 'integrations:sync-restartproject
                            {--dry-run : Log what would change without writing to the database}';

    protected $description = 'Sync upcoming Restart Project repair events into community events';

    public function handle(RestartProjectService $service): int
    {
        $lock = Cache::lock('integrations:sync-restartproject', 3600);

        try {
            $lock->block(0);
        } catch (LockTimeoutException) {
            $this->warn('Another instance is already running.');
            return Command::SUCCESS;
        }

        try {
            $dryRun = (bool) $this->option('dry-run');
            $result = $service->sync($dryRun);

            $prefix = $dryRun ? '[DRY RUN] Would process' : 'Processed';
            $this->info("{$prefix} {$result['added']} new event(s), {$result['updated']} updated, {$result['deleted']} deleted.");
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
