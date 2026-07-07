<?php

namespace App\Console\Commands\Integrations;

use App\Services\RepairCafeWalesService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class SyncRepairCafeWalesCommand extends Command
{
    protected $signature = 'integrations:sync-repaircafewales
                            {--dry-run : Log what would change without writing to the database}';

    protected $description = 'Sync upcoming Repair Cafe Wales events into community events';

    public function handle(RepairCafeWalesService $service): int
    {
        $lock = Cache::lock('integrations:sync-repaircafewales', 3600);

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
