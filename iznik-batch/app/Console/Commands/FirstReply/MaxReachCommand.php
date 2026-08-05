<?php

namespace App\Console\Commands\FirstReply;

use App\Services\FirstReply\MaxReachService;
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

    public function handle(MaxReachService $service): int
    {
        if (!$service->available()) {
            $this->info('rippling_reach.max_polygon does not exist yet; nothing to do.');

            return Command::SUCCESS;
        }

        $stats = $service->populate(
            (int) $this->option('limit'),
            (int) $this->option('routing-budget')
        );

        $this->info(sprintf(
            'max reach: scanned %d, filled %d (%d needed routing), skipped %d',
            $stats['scanned'],
            $stats['filled'],
            $stats['routed'],
            $stats['skipped']
        ));

        return Command::SUCCESS;
    }
}
