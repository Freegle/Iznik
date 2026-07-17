<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\ReachBoundsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ripple:backfill-reach-bounds — one-off backfill of the sandwich-bounds sibling table
 * (rippling_reach_bounds) for reach rows that predate the bounds writers
 * (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md).
 *
 * Resumable by construction: candidates are selected by anti-join (reach rows with no
 * bounds row), so an interrupted run picks up where it left off and a completed backlog
 * converges to "Backfilled 0". Paced (--sleep-ms between rows) and bounded (--limit) so
 * it can run off-peak without loading the Galera cluster — each row writes ~30 KB of
 * derived geometry. Steady state needs no cron: the expander writers keep bounds in
 * sync from here on.
 */
class BackfillReachBoundsCommand extends Command
{
    protected $signature = 'ripple:backfill-reach-bounds
                            {--limit=0 : Max rows to backfill this run (0 = no limit)}
                            {--sleep-ms=0 : Pause between rows, to pace replication off-peak}';

    protected $description = 'Backfill sandwich bounds (rippling_reach_bounds) for existing reach rows';

    public function handle(ReachBoundsService $bounds): int
    {
        $limit = (int) $this->option('limit');
        $sleepMs = (int) $this->option('sleep-ms');

        $q = DB::table('rippling_reach as rr')
            ->leftJoin('rippling_reach_bounds as b', 'b.msgid', '=', 'rr.msgid')
            ->whereNull('b.msgid')
            ->orderBy('rr.msgid')
            ->select('rr.msgid');
        if ($limit > 0) {
            $q->limit($limit);
        }

        $done = 0;
        foreach ($q->pluck('rr.msgid') as $msgid) {
            $bounds->syncFromPolygon((int) $msgid);
            $done++;
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        // Posts that were ALREADY completed (messages_spatial.successful = 1) never get
        // the outcome hook's 0→1 degrade, so degrade them here in one set-based pass —
        // the completed-post candidate pruning is half the point of the bounds table,
        // and without this the backfill would permanently forfeit it for the backlog.
        $degraded = 0;
        try {
            $degraded = DB::update(
                'UPDATE rippling_reach_bounds b
                   JOIN rippling_reach rr ON rr.msgid = b.msgid
                   JOIN messages_spatial ms ON ms.msgid = b.msgid AND ms.successful = 1
                    SET b.outer_bound = ST_SRID(POINT(rr.lng, rr.lat), 3857),
                        b.inner_bound = NULL
                  WHERE ST_GeometryType(b.outer_bound) <> \'POINT\''
            );
        } catch (\Throwable $e) {
            Log::warning("ripple: backfill bounds degrade pass failed: {$e->getMessage()}");
        }

        $this->info("Backfilled {$done} bounds rows; degraded {$degraded} for completed posts.");

        return Command::SUCCESS;
    }
}
