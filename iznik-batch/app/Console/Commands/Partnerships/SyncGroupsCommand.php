<?php

namespace App\Console\Commands\Partnerships;

use App\Services\PartnershipGroupsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Re-checks every live partnership's communities against its council boundary, so a
 * community set up inside the boundary after the deal was agreed is covered and shows the
 * sponsor, without anyone having to open the Partnerships page.
 *
 * Communities added or left out by hand are never touched.
 *
 *   php artisan partnerships:sync-groups
 *   php artisan partnerships:sync-groups --dry-run
 */
#[AsCommand(name: 'partnerships:sync-groups')]
class SyncGroupsCommand extends Command
{
    protected $signature = 'partnerships:sync-groups
                            {--dry-run : Report what would change without changing it}';

    protected $description = 'Keep live partnerships covering every community inside their council boundary';

    public function handle(PartnershipGroupsService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $ids = DB::table('partnerships')
            ->whereDate('enddate', '>=', Carbon::today()->toDateString())
            ->orderBy('id')
            ->pluck('id');

        $changed = 0;
        foreach ($ids as $id) {
            $result = $service->sync((int) $id, $dryRun);

            if ($result['added'] || $result['dropped']) {
                $changed++;
                $this->info(sprintf('Partnership %d: %s %s, %s %s.',
                    $id,
                    $dryRun ? 'would cover' : 'now covers',
                    $result['added'] ? implode(', ', $result['added']) : 'nothing new',
                    $dryRun ? 'would drop' : 'dropped',
                    $result['dropped'] ? implode(', ', $result['dropped']) : 'nothing'
                ));
            }
        }

        $this->info(sprintf('Checked %d live %s; %d changed.',
            $ids->count(),
            $ids->count() === 1 ? 'partnership' : 'partnerships',
            $changed
        ));

        return Command::SUCCESS;
    }
}
