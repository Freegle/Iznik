<?php

namespace App\Console\Commands\FirstReply;

use App\Services\FirstReply\MaxReachService;
use App\Traits\SingleInstanceLock;
use Illuminate\Console\Command;

/**
 * firstreply:maxreach - fill in rippling_reach.max_polygon.
 *
 * Separate from ripple:expand on purpose. The expander is the hot single-writer
 * loop that keeps every live post's reach current, and it is already the thing
 * that gets blamed when rippling falls behind; a backfill that occasionally has
 * to call the routing server has no business inside it. Rows this has not reached
 * simply have no max reach yet, and every reader of max_polygon treats that as
 * "unknown" and falls back to current-reach behaviour.
 */
class MaxReachCommand extends Command
{
    protected $signature = 'firstreply:maxreach
                            {--limit=200 : Reach rows to consider this run}
                            {--routing-budget=20 : Most routing-server calls this run may make. Most rows need none, because the final tick geometry is already inline in the cached schedule; this bounds the ones that do so a bad batch cannot turn into a fan-out}';

    protected $description = 'Populate the eventual (max) reach polygon for rippling posts';

    use SingleInstanceLock;

    public function handle(MaxReachService $service): int
    {
        // everyMinute() + runInBackground() means withoutOverlapping() does not
        // actually prevent overlap (see SingleInstanceLock); once a run outlives
        // its minute the pile compounds, and each run calls the routing server.
        return $this->runSingleInstance('firstreply:maxreach:run', 900, fn (): int => $this->runGuarded($service));
    }

    private function runGuarded(MaxReachService $service): int
    {
        $stats = $service->populate(
            (int) $this->option('limit'),
            (int) $this->option('routing-budget')
        );

        $this->info(sprintf(
            'max reach: cumulative filled for %d labelled rows',
            $stats['labelled_cumulative'] ?? 0
        ));

        // Same command because it is the same knowledge: this is the only place
        // that already parses tick schedules, and sizing a passthrough means
        // asking which tick would have covered the replier.
        $saved = $service->computePassthroughSavings();

        if ($saved['scanned'] > 0) {
            $this->info(sprintf(
                'passthrough sizing: scanned %d, sized %d, unanswerable %d',
                $saved['scanned'],
                $saved['computed'],
                $saved['unknown']
            ));
        }

        return Command::SUCCESS;
    }
}
