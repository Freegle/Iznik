<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\ExpandService;
use Illuminate\Console\Command;

/**
 * ripple:rebuild-reach — one-off backfill after the reach algorithm change (fine
 * no-smoothing polygon + member-based reachable-group targeting). For every active
 * reach row it re-derives the polygon and slim schedule at the post's CURRENT tick,
 * stores the per-tick reachable-group ids, and retracts what the new targeting no
 * longer covers: the rippled-in copies, and the ripple-created memberships that
 * existed only for them. Held reaches are untouched. Safe to re-run (idempotent)
 * and to run in slices via --limit. --dry-run reports counts without writing.
 *
 * Distinct from ripple:backfill (which SEEDS reach rows for pre-go-live posts):
 * this command REBUILDS existing rows under the current algorithm.
 *
 * Run with the reachable gate (RIPPLE_REACHABLE_GATE) on for the full effect; with
 * it off only the polygon is tightened and polygon-based retraction applies.
 */
class RebuildReachCommand extends Command
{
    protected $signature = 'ripple:rebuild-reach
                            {--dry-run : Report what would change without writing}
                            {--limit=500 : Max reach rows to process this run}
                            {--msgid= : Restrict to a single message ID}';

    protected $description = 'Rebuild stored reach polygons under the current algorithm and retract copies/memberships no longer covered';

    public function handle(ExpandService $service): int
    {
        if (!config('freegle.ripple.reachable_gate')) {
            $this->warn('freegle.ripple.reachable_gate is OFF: polygons will be tightened but only polygon-based retraction applies.');
        }

        $dryRun = (bool) $this->option('dry-run');
        $msgid = $this->option('msgid') !== null ? (int) $this->option('msgid') : null;

        $r = $service->backfillReach($dryRun, (int) $this->option('limit'), $msgid);

        if ($dryRun) {
            $this->info(sprintf(
                'Would rebuild %d/%d reach row(s) (%d skipped) and retract ~%d out-of-reach group-copies.',
                $r['updated'],
                $r['candidates'],
                $r['skipped'],
                $r['would_retract_groups'],
            ));
        } else {
            $this->info(sprintf(
                'Rebuilt %d/%d reach row(s) (%d skipped); retracted %d group-copies; removed %d ripple-join membership(s).',
                $r['updated'],
                $r['candidates'],
                $r['skipped'],
                $r['pulled_out_of_reach'],
                $r['memberships_removed'],
            ));
        }

        return Command::SUCCESS;
    }
}
