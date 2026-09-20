<?php

namespace App\Console\Commands\Ripple;

use App\Console\Concerns\PreventsOverlapping;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The disk-reclaim step of the labels-truth cutover, in two grades:
 *
 * - max_polygon_cells (~20KB/row measured on production) drains for every
 *   post with a stored label: every max-cells reader (the first-reply
 *   passthrough gate, MaxReachService, MatchMail's band) asks the label
 *   FIRST and only falls back to this grid, so a labelled row's max grid is
 *   dead weight; without it those readers fail closed during a routing
 *   outage, the conservative direction for what are all extra-mail decisions.
 *
 * - polygon_cells (the CURRENT reach grid, ~17.5KB/row) drains only for rows
 *   that ALSO have their road-native union threshold (origin_union_secs,
 *   from the backfill's second pass): the label evaluator then answers
 *   everything this grid did - membership, the origin-group union,
 *   rejections - and the spatial containment index REMOVES drained rows
 *   (containment for them is served by the routing server's discover arm).
 *   The per-tick writers drain expanding rows organically; this command
 *   covers the done/stopped rows no writer touches.
 *
 * Run AFTER ripple:backfill-reach-labels (both its passes) has completed and
 * the labels-truth code has soaked. Follow with OPTIMIZE TABLE
 * rippling_reach (online, InnoDB) to hand the space back.
 *
 * Safe to re-run; rows without labels are never touched, and the maxreach
 * backfill skips labelled rows so drained grids are not rewritten.
 */
class DropCellGridsCommand extends Command
{
    use PreventsOverlapping;

    protected $signature = 'ripple:drop-cell-grids
                            {--dry-run : Report how many rows would be drained without changing anything}
                            {--limit=0 : Max rows to drain (0 = no limit)}
                            {--sleep-ms=50 : Pause between rows, to pace Galera replication}';

    protected $description = 'NULL the max-reach cell grids for posts whose stored labels decide their reach';

    public function handle(): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');

            return Command::SUCCESS;
        }

        if (!\Illuminate\Support\Facades\Schema::hasColumn('rippling_reach', 'max_polygon_cells')) {
            $this->info('Grid columns already dropped; nothing to drain.');

            return Command::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $sleepUs = max(0, (int) $this->option('sleep-ms')) * 1000;

        $query = DB::table('rippling_reach')
            ->select('msgid')
            ->whereNotNull('reach_labels')
            ->where(function ($q) {
                $q->whereNotNull('max_polygon_cells')
                    ->orWhere(function ($q2) {
                        $q2->whereNotNull('polygon_cells')->whereNotNull('origin_union_secs');
                    });
            });

        $total = (clone $query)->count();
        $this->info("{$total} labelled rows still carry a drainable cell grid.");
        if ($this->option('dry-run')) {
            return Command::SUCCESS;
        }
        if ($limit > 0) {
            $query->limit($limit);
            $total = min($total, $limit);
        }

        $done = 0;
        $startedAt = microtime(true);
        foreach ($query->cursor() as $row) {
            // keep-raw: the current grid drains only when the union threshold
            // is present, decided per row inside the UPDATE so a re-run after
            // the union backfill picks up exactly the newly-eligible rows.
            DB::statement(
                'UPDATE rippling_reach
                    SET max_polygon_cells = NULL,
                        polygon_cells = IF(origin_union_secs IS NOT NULL, NULL, polygon_cells)
                  WHERE msgid = ?',
                [$row->msgid]
            );
            $done++;
            if ($done % 500 === 0) {
                $rate = $done / max(0.001, microtime(true) - $startedAt);
                $eta = $total > $done && $rate > 0
                    ? gmdate('G\h i\m', (int) (($total - $done) / $rate))
                    : '-';
                $this->info(sprintf('%d/%d (%d%%), %.1f rows/s, ETA %s',
                    $done, $total, (int) (100 * $done / max(1, $total)), $rate, $eta));
            }
            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
        }

        $this->info("Drained {$done} rows. Now reclaim the space: OPTIMIZE TABLE rippling_reach;");

        return Command::SUCCESS;
    }
}
