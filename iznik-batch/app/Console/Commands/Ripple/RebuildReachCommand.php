<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\ExpandService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

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
 * Resumable: only rows the new algorithm has not yet touched (reachable_group_ids
 * IS NULL - init/advance populate it) are candidates, so re-run until drained;
 * --all re-sweeps everything. Shardable: --shards N --shard i partitions by
 * msgid % N so disjoint shards run in parallel (each takes its own cache lock;
 * total routing load ~= shards - stay within the routing server's headroom).
 *
 * Run with the reachable gate (RIPPLE_REACHABLE_GATE) on for the full effect; with
 * it off only the polygon is tightened and polygon-based retraction applies.
 */
class RebuildReachCommand extends Command
{
    protected $signature = 'ripple:rebuild-reach
                            {--dry-run : Report what would change without writing}
                            {--limit=500 : Max reach rows to process this run}
                            {--msgid= : Restrict to a single message ID}
                            {--shards= : Total parallel shards (partition by msgid % shards)}
                            {--shard= : This instance\'s shard index, 0..shards-1 (required with --shards)}
                            {--all : Re-sweep rows already rebuilt (default: only untouched rows)}';

    protected $description = 'Rebuild stored reach polygons under the current algorithm and retract copies/memberships no longer covered';

    public function handle(ExpandService $service): int
    {
        if (!config('freegle.ripple.reachable_gate')) {
            $this->warn('freegle.ripple.reachable_gate is OFF: polygons will be tightened but only polygon-based retraction applies.');
        }

        $dryRun = (bool) $this->option('dry-run');
        $msgid = $this->option('msgid') !== null ? (int) $this->option('msgid') : null;

        $shards = $this->option('shards') !== null ? (int) $this->option('shards') : null;
        $shard = $this->option('shard') !== null ? (int) $this->option('shard') : null;
        if (($shards === null) !== ($shard === null)) {
            $this->error('--shards and --shard must be used together.');
            return Command::FAILURE;
        }
        if ($shards !== null && ($shards < 2 || $shard < 0 || $shard >= $shards)) {
            $this->error('--shard must be in 0..shards-1 and --shards >= 2.');
            return Command::FAILURE;
        }

        // One instance per shard (or one unsharded instance): a duplicate would rebuild
        // the same slice. Distinct from ripple:expand's lock so the cron never blocks.
        $lockName = 'ripple:rebuild-reach' . ($shards !== null ? ":shard{$shards}-{$shard}" : '');
        $lock = Cache::lock($lockName, 3600);
        if (!$dryRun && !$lock->get()) {
            $this->warn("Another {$lockName} instance is running; exiting.");
            return Command::SUCCESS;
        }

        try {
            $r = $service->backfillReach(
                $dryRun,
                (int) $this->option('limit'),
                $msgid,
                $shards,
                $shard,
                (bool) $this->option('all')
            );
        } finally {
            if (!$dryRun) {
                $lock->release();
            }
        }

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
