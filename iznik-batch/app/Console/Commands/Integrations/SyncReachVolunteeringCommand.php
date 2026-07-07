<?php

namespace App\Console\Commands\Integrations;

use App\Services\ReachVolunteeringService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class SyncReachVolunteeringCommand extends Command
{
    protected $signature = 'integrations:sync-reachvolunteering
                            {--dry-run : Log what would change without writing to the database}';

    protected $description = 'Sync Reach Volunteering opportunities into the volunteering table';

    public function handle(ReachVolunteeringService $service): int
    {
        $lock = Cache::lock('integrations:sync-reachvolunteering', 3600);

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
            $this->info("{$prefix} {$result['added']} new, {$result['updated']} updated, {$result['deleted']} deleted.");
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
