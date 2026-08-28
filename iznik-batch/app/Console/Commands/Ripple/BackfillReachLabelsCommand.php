<?php

namespace App\Console\Commands\Ripple;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\Ripple\ReachService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot backfill of reach-engine labels for rippling_reach rows that predate
 * label storage: fetches routing /v1/reach-labels at each post's maximum budget
 * and stores the blob plus its reached-region rows (rippling_reach_leaves).
 *
 * Safe to re-run (rows with labels are skipped) and safe against a routing
 * server without the engine configured (503s are silent no-ops; run again once
 * REACH_DIR is deployed). Paced for Galera like the other ripple backfills.
 */
class BackfillReachLabelsCommand extends Command
{
    use PreventsOverlapping;

    protected $signature = 'ripple:backfill-reach-labels
                            {--dry-run : Report how many rows would be filled without changing anything}
                            {--limit=0 : Max rows to fill (0 = no limit)}
                            {--all : Re-fetch rows that already have labels too — REQUIRED after a partition rebuild, which renumbers the regions every stored label refers to}
                            {--sleep-ms=100 : Pause between rows, to pace Galera replication}';

    protected $description = 'Fetch and store reach-engine labels for posts that predate label storage';

    public function handle(ReachService $reach): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');

            return Command::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $sleepUs = max(0, (int) $this->option('sleep-ms')) * 1000;

        // Every row with a budget is a candidate, whatever its status: labels
        // are the post's permanent reach record, and rows that finished
        // expanding ('done') still get browsed and emailed until their
        // outcome. Purged posts cascade out of the table entirely.
        $query = DB::table('rippling_reach')
            ->select(['msgid', 'lat', 'lng', 'max_drive_min'])
            ->where('max_drive_min', '>', 0)
            ->orderByDesc('updated_at');
        if (!$this->option('all')) {
            $query->whereNull('reach_labels');
        }

        $total = (clone $query)->count();
        $this->info("{$total} rows need labels.");
        if ($limit > 0) {
            $query->limit($limit);
            $total = min($total, $limit);
        }
        if ($this->option('dry-run')) {
            return Command::SUCCESS;
        }

        $done = $failed = 0;
        $startedAt = microtime(true);
        // cursor(): production has millions of reach rows; never load them all.
        foreach ($query->cursor() as $row) {
            if ($reach->storeReachLabels((int) $row->msgid, (float) $row->lat, (float) $row->lng, (float) $row->max_drive_min)) {
                $done++;
            } else {
                $failed++;
            }
            $processed = $done + $failed;
            if ($processed % 500 === 0) {
                $rate = $processed / max(0.001, microtime(true) - $startedAt);
                $eta = $total > $processed && $rate > 0
                    ? gmdate('G\h i\m', (int) (($total - $processed) / $rate))
                    : '-';
                $this->info(sprintf('%d/%d (%d%%), %d failed, %.1f rows/s, ETA %s',
                    $processed, $total, (int) (100 * $processed / max(1, $total)), $failed, $rate, $eta));
            }
            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
        }

        $this->info("Stored labels for {$done} rows; {$failed} failed (retry after the reach engine is deployed).");

        return Command::SUCCESS;
    }
}
