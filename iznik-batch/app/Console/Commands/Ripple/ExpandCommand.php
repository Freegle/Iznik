<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\ExpandService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ripple:expand — maintains the rippling-out reach (rippling_reach) for every
 * active post. Runs every minute in the batch container (see routes/console.php),
 * computing reach via the routing server. Dark in PR A: nothing reads the reach
 * yet, so this only populates rippling_reach.
 */
class ExpandCommand extends Command
{
    use GracefulShutdown;

    protected $signature = 'ripple:expand
                            {--dry-run : Compute and report without writing reach}
                            {--limit=500 : Max posts to initialise/advance this run}';

    protected $description = 'Maintain rippling-out reach (rippling_reach) for active posts';

    public function handle(ExpandService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        if ($dryRun) {
            $this->info('DRY RUN — no reach will be written.');
        }

        Log::info('ripple:expand starting', ['dry_run' => $dryRun, 'limit' => $limit]);

        $stats = $service->process($dryRun, $limit);

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info(sprintf(
            '%sInitialised: %d, Expanded: %d (completed: %d), Removed: %d, Skipped: %d, Errors: %d',
            $prefix,
            $stats['initialized'],
            $stats['expanded'],
            $stats['completed'],
            $stats['removed'],
            $stats['skipped'],
            $stats['errors']
        ));

        if ($stats['errors'] > 0) {
            $this->warn("Errors: {$stats['errors']}");
        }

        Log::info('ripple:expand complete', $stats);

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
