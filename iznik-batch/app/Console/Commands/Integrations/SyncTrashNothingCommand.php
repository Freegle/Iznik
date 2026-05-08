<?php

namespace App\Console\Commands\Integrations;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\TnSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncTrashNothingCommand extends Command
{
    use PreventsOverlapping;

    protected $signature = 'integrations:sync-trashnothing
                            {--dry-run : Log what would change without writing to the database}';

    protected $description = 'Sync ratings and user changes from the TrashNothing API';

    public function handle(TnSyncService $service): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');
            return Command::SUCCESS;
        }

        try {
            $dryRun = (bool) $this->option('dry-run');

            if ($dryRun) {
                $this->info('DRY RUN — no database writes will be made.');
            }

            Log::info('TN sync command starting', ['dry_run' => $dryRun]);

            $result = $service->sync($dryRun);

            $prefix = $dryRun ? '[DRY RUN] Would process' : 'Processed';
            $this->info("{$prefix} {$result['ratings']} rating(s), {$result['user_changes']} user change(s).");

            Log::info('TN sync command complete', $result + ['dry_run' => $dryRun]);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
