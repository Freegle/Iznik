<?php

namespace App\Console\Commands\Ripple;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\Ripple\ReachMemberReconcileService;
use Illuminate\Console\Command;

/**
 * Daily backstop for the member side of reach mail. See ReachMemberReconcileService.
 */
class ReconcileReachMembersCommand extends Command
{
    use PreventsOverlapping;

    protected $signature = 'ripple:reconcile-reach-members
                            {--dry-run : Report how many members would be queued without queueing them}';

    protected $description = 'Re-queue members whose join or postcode change since yesterday was not followed by reach mail';

    public function handle(ReachMemberReconcileService $service): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');

            return Command::SUCCESS;
        }

        try {
            $dryRun = (bool) $this->option('dry-run');
            $stats = $service->reconcile($dryRun);

            $this->info(($dryRun ? '[dry run] would have ' : '') . "queued {$stats['queued']} member(s) for reach mail");

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
