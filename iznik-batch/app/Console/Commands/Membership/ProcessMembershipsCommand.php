<?php

namespace App\Console\Commands\Membership;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\MembershipsProcessingService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessMembershipsCommand extends Command
{
    use PreventsOverlapping;
    use GracefulShutdown;

    protected $signature = 'memberships:process
                            {--dry-run : Log what would be processed without making changes}';

    protected $description = 'Process pending membership history entries: send per-group welcome emails, flag reviewed members';

    public function handle(MembershipsProcessingService $service): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');
            return Command::SUCCESS;
        }

        try {
            $dryRun = (bool) $this->option('dry-run');

            if ($dryRun) {
                $this->info('DRY RUN — no emails will be sent or records updated.');
            }

            Log::info('Starting membership processing', ['dry_run' => $dryRun]);

            $count = $service->processAll($dryRun);

            $prefix = $dryRun ? '[DRY RUN] Would process' : 'Processed';
            $this->info("{$prefix} {$count} entry/entries.");
            Log::info('Membership processing complete', ['count' => $count, 'dry_run' => $dryRun]);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
