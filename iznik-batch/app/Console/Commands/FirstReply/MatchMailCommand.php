<?php

namespace App\Console\Commands\FirstReply;

use App\Services\FirstReply\MatchMailService;
use App\Services\FirstReply\Rollout;
use Illuminate\Console\Command;

/**
 * firstreply:matchmail - tell the people who have actually asked for an item
 * about a post that matches, straight away.
 *
 * A match means an open post of the opposite type, or a saved search: something
 * the member asked to hear about. See MatchMailService for how they are picked
 * and why nothing weaker qualifies.
 */
class MatchMailCommand extends Command
{
    protected $signature = 'firstreply:matchmail
                            {--dry-run : Pick and report matches without mailing anyone}';

    protected $description = 'Mail members whose own post or saved search matches a new post';

    public function handle(MatchMailService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Same DB-backed lock pattern as ripple:expand, because the scheduler's
        // withoutOverlapping demonstrably did not stop overlapping runs here:
        // 40+ concurrent runs piled up in the first hour live, each wedging
        // on a MySQL connection the server had idle-closed under it. The lock
        // is owned by this process and auto-expires, so a crashed run cannot
        // wedge it forever. Dry runs are exempt - they do bounded read-only
        // work and are how the config is sanity-checked.
        $lock = $dryRun ? null : \Cache::lock('firstreply:matchmail:run', 600);
        if ($lock !== null && !$lock->get()) {
            $this->info('firstreply:matchmail skipped: another run already holds the lock');

            return Command::SUCCESS;
        }

        try {
            $this->info(Rollout::describe());

            // Attribute first, so someone mailed on the previous run is credited
            // before this run reports. Cheap, and the only thing that says whether
            // any of this works.
            $attributed = $dryRun ? 0 : $service->attributeReplies();

            $stats = $service->run($dryRun);

            $this->info(sprintf(
                '%smatch mail: considered %d posts, matched %d, mailed %d, newly replied %d',
                $dryRun ? '[DRY RUN] ' : '',
                $stats['considered'],
                $stats['posts_matched'],
                $stats['mailed'],
                $attributed
            ));

            return Command::SUCCESS;
        } finally {
            $lock?->release();
        }
    }
}
