<?php

namespace App\Console\Commands\User;

use App\Services\UserApproxLocService;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;

/**
 * No GracefulShutdown: the refresh takes seconds (5,000 members in ~1s locally, so the ~112k
 * active members on live are well inside a minute) and is fully idempotent, so there is nothing
 * for a shutdown handler to protect — being killed mid-run just means tonight's run redoes it.
 */
class UpdateApproxLocsCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'users:update-approx-locs
                            {--dry-run : Report what would change without writing}
                            {--limit= : Stop after considering this many members}';

    protected $description = 'Refresh users_approxlocs, the blurred point cloud of active members that drives rippling reach';

    public function handle(UserApproxLocService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun, $limit) {
            $stats = $service->updateLocations($dryRun, $limit);

            $this->info(sprintf(
                'Approx locations: considered %d, upserted %d, no location %d, pruned %d.',
                $stats['considered'],
                $stats['upserted'],
                $stats['no_location'],
                $stats['pruned']
            ));

            return Command::SUCCESS;
        });
    }
}
