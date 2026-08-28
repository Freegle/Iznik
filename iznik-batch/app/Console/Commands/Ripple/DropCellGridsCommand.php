<?php

namespace App\Console\Commands\Ripple;

use App\Console\Concerns\PreventsOverlapping;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The disk-reclaim step of the labels-truth cutover: NULL the MAX-reach cell
 * grid (max_polygon_cells, ~20KB/row measured on production) for every post
 * whose stored label now decides its reach. Every max-cells reader (the
 * first-reply passthrough gate, MaxReachService, MatchMail's band) asks the
 * stored label FIRST and only falls back to this grid, so a labelled row's
 * max grid is dead weight; without it those readers fail closed during a
 * routing outage, which is the conservative direction for what are all
 * extra-mail decisions.
 *
 * polygon_cells (the CURRENT reach grid) is deliberately NOT drained: it is
 * the source the spatial server's reach containment index is built from (the
 * browse feed / badge / digest prefilter), so it stays materialised every
 * tick until that index can answer from labels.
 *
 * Run AFTER ripple:backfill-reach-labels has completed and the labels-truth
 * code has soaked. Follow with OPTIMIZE TABLE rippling_reach (online,
 * InnoDB) to hand the space back.
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

        $limit = (int) $this->option('limit');
        $sleepUs = max(0, (int) $this->option('sleep-ms')) * 1000;

        $query = DB::table('rippling_reach')
            ->select('msgid')
            ->whereNotNull('reach_labels')
            ->whereNotNull('max_polygon_cells');

        $total = (clone $query)->count();
        $this->info("{$total} labelled rows still carry a max-reach cell grid.");
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
            DB::table('rippling_reach')->where('msgid', $row->msgid)->update([
                'max_polygon_cells' => null,
            ]);
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
