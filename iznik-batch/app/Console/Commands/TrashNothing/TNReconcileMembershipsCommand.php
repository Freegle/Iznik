<?php

namespace App\Console\Commands\TrashNothing;

use App\Services\TrashNothing\Ingestion\TnMembershipReconcileService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Writes the membership a recent TN API post should have behind it, when ingestion failed to.
 * See TnMembershipReconcileService.
 */
#[AsCommand(name: 'tn:reconcile-memberships')]
class TNReconcileMembershipsCommand extends Command
{
    protected $signature = 'tn:reconcile-memberships
                            {--dry-run : Report the missing memberships without writing them}';

    protected $description = 'Write the membership a recent TN API post should have behind it, when it is missing.';

    public function handle(TnMembershipReconcileService $service): int
    {
        $stats = $service->reconcile(!$this->option('dry-run'));

        $this->info("Missing: {$stats['missing']}, repaired: {$stats['repaired']}, failed: {$stats['failed']}");

        return $stats['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
