<?php

namespace App\Console\Commands\FirstReply;

use App\Services\FirstReply\ScoutService;
use App\Services\FirstReply\Rollout;
use Illuminate\Console\Command;

/**
 * firstreply:scout - tell a few likely-interested people about posts nobody has
 * replied to.
 *
 * See ScoutService for what "likely-interested" means and why the list is
 * deliberately tiny.
 */
class ScoutCommand extends Command
{
    protected $signature = 'firstreply:scout
                            {--dry-run : Pick and report scouts without mailing anyone}';

    protected $description = 'Notify likely-interested members about posts with no replies';

    public function handle(ScoutService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Same DB-backed lock pattern as ripple:expand, because the scheduler's
        // withoutOverlapping demonstrably did not stop overlapping runs here:
        // 40+ concurrent scouts piled up in the first hour live, each wedging
        // on a MySQL connection the server had idle-closed under it. The lock
        // is owned by this process and auto-expires, so a crashed run cannot
        // wedge it forever. Dry runs are exempt - they do bounded read-only
        // work and are how the config is sanity-checked.
        $lock = $dryRun ? null : \Cache::lock('firstreply:scout:run', 600);
        if ($lock !== null && !$lock->get()) {
            $this->info('firstreply:scout skipped: another run already holds the lock');

            return Command::SUCCESS;
        }

        try {
            $this->info(Rollout::describe());

            // Attribute first, so a scout mailed on the previous run is credited
            // before this run reports. Cheap, and the only thing that says whether
            // any of this works.
            $attributed = $dryRun ? 0 : $service->attributeReplies();

            $stats = $service->run($dryRun);

            $this->info(sprintf(
                '%sscouts: considered %d posts, scouted %d, mailed %d, newly replied %d',
                $dryRun ? '[DRY RUN] ' : '',
                $stats['considered'],
                $stats['posts_scouted'],
                $stats['mailed'],
                $attributed
            ));

            return Command::SUCCESS;
        } finally {
            $lock?->release();
        }
    }
}
