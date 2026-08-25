<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\LegacyGeometry;
use App\Services\Ripple\CellSetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ripple:backfill-ring-cells - fills overflow_cells for rows whose overflow
 * rings were written BEFORE plans/2026-08-24-rippling-reach-raster-storage.md.
 *
 * The rings are the table's worst case: measured 2026-08-23, overflow_bounds
 * was HALF the table at 860KB a row, and each ring averages 37,000 vertices
 * that iznik-spatial-go must parse to build its read index. ExpandService now
 * writes overflow_cells alongside every ring it writes, but it only ever
 * writes rings when a reach is initialised or re-derived - so existing ringed
 * rows would sit unconverted until they naturally expire (up to ~90 days),
 * and spatial-go would keep parsing their WKT that whole time.
 *
 * ONE ROW MAY NEED SEVERAL rasterise calls (a post can carry a rural ring per
 * band and three cluster wedges), so this is paced even more conservatively
 * than ripple:backfill-max-reach-cells: a small --limit default and a
 * --sleep-ms between rows, because the rasteriser lives in iznik-spatial-go
 * (CellSetService::rasterize - the ONE place a polygon becomes its canonical
 * compact form) and shares a host with the routing and reply-serving traffic.
 *
 * Only rows that carry rings are candidates (has_overflow, the generated and
 * indexed flag spatial-go's own load hangs off), so this touches ~4,400 rows
 * of a 17GB table rather than all 52,000.
 *
 * Safety, in the same shape as every other Ripple backfill: bounded,
 * resumable via a config-table mark, one row per statement (Galera-safe),
 * dry-run, and updated_at held still - the reach mailer and spatial-go's own
 * delta poll both watch that column, and a bulk reach backfill once generated
 * 38k+ notification emails in a morning by bumping it.
 */
class BackfillRingCellsCommand extends Command
{
    protected $signature = 'ripple:backfill-ring-cells
                            {--limit=50 : Max rows to process this run}
                            {--sleep-ms=100 : Pause between rows, to go easy on the rasterise endpoint}
                            {--after= : Start after this msgid instead of the stored mark}
                            {--reset-mark : Start from the beginning again}
                            {--dry-run : Report what would be filled without writing}';

    protected $description = 'Backfill rippling_reach.overflow_cells for rows whose rings predate the raster-storage change';

    /** Where the sweep got to, so a bounded run can be repeated until done. */
    private const CONFIG_KEY_MARK = 'ripple_backfill_ring_cells_last_msgid';

    public function __construct(private CellSetService $cellSets)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // This sweep reads the LEGACY ring WKT, so the drop ends its work for
        // good. Said plainly rather than crashing on a dropped column.
        if (!LegacyGeometry::overflowReady()) {
            $this->info('rippling_reach.overflow_bounds has been dropped - every ring is stored as cells now, so this sweep is complete for good.');

            return self::SUCCESS;
        }

        // keep-raw: information_schema check for a column this Eloquent-less table has no model for
        $hasColumn = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
                AND column_name = 'overflow_cells'"
        );
        if (!$hasColumn || (int) $hasColumn->n === 0) {
            $this->error('rippling_reach.overflow_cells is not migrated yet; nothing to do.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $sleepMs = max(0, (int) $this->option('sleep-ms'));

        if ($this->option('reset-mark')) {
            $this->saveMark(0);
            $this->info('Mark reset.');
        }

        $after = $this->option('after') !== null ? (int) $this->option('after') : $this->mark();
        $limit = max(1, (int) $this->option('limit'));

        $rows = DB::table('rippling_reach')
            ->select('msgid', 'overflow_bounds')
            ->where('msgid', '>', $after)
            ->whereNotNull('overflow_bounds')
            ->whereNull('overflow_cells')
            ->orderBy('msgid')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->info($after > 0
                ? "Nothing left after msgid {$after}. Sweep complete."
                : 'Nothing to backfill.');

            return self::SUCCESS;
        }

        $filled = 0;
        $skipped = 0;
        $rings = 0;
        $lastMsgid = $after;

        foreach ($rows as $row) {
            $lastMsgid = (int) $row->msgid;

            $bounds = json_decode((string) $row->overflow_bounds, true);
            if (!is_array($bounds) || empty($bounds)) {
                $skipped++;

                continue;
            }

            $cells = $this->rasterizeRings($bounds, $dryRun, $rings);
            if ($cells === null) {
                // Nothing rasterised - either no geometry leaves (a row whose
                // only members are the scalars) or every call failed. Leave it
                // for the next run rather than writing an empty object, which
                // would look converted and stop this sweep revisiting it.
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $filled++;

                continue;
            }

            // keep-raw: `updated_at = updated_at` self-assignment to suppress the ON UPDATE auto-bump - the builder always emits a real value
            $n = DB::affectingStatement(
                'UPDATE rippling_reach SET overflow_cells = ?, updated_at = updated_at
                  WHERE msgid = ? AND overflow_cells IS NULL',
                [json_encode($cells), $lastMsgid]
            );
            if ($n < 1) {
                // A ring write landed while this row's lanes were being
                // rasterised - its value is newer. Same compare-and-swap
                // reasoning as ripple:backfill-reach-cells.
                $skipped++;

                continue;
            }
            $filled++;

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        if (!$dryRun && $this->option('after') === null) {
            $this->saveMark($lastMsgid);
        }

        $this->info(sprintf(
            '%s %d row(s) / %d ring(s), skipped %d, across %d candidate(s). Mark now %d.',
            $dryRun ? 'Would fill' : 'Filled',
            $filled,
            $rings,
            $skipped,
            $rows->count(),
            $lastMsgid
        ));

        return self::SUCCESS;
    }

    /**
     * The decoded overflow_bounds turned into the overflow_cells shape - the
     * same nesting and paths, each ring's WKT replaced by base64 cell bytes -
     * or null when nothing could be converted. Mirrors
     * ExpandService::overflowCellsJson; the scalar members
     * (fairness_budget_min, bbox) are deliberately not carried across.
     *
     * @param  array<string,mixed>  $bounds
     * @return array<string,array<string,string>>|null
     */
    private function rasterizeRings(array $bounds, bool $dryRun, int &$rings): ?array
    {
        $out = [];
        $thisRow = 0;

        foreach ($bounds as $lane => $ringsForLane) {
            if (!is_array($ringsForLane)) {
                continue; // fairness_budget_min, a scalar
            }
            $converted = [];
            foreach ($ringsForLane as $band => $wkt) {
                // The is_string test is what excludes `bbox`, which IS an array
                // (four floats) and so gets this far: it contributes nothing,
                // the lane ends up empty, and it is dropped below.
                if (!is_string($wkt) || $wkt === '') {
                    continue;
                }
                $thisRow++;
                $rings++;
                if ($dryRun) {
                    continue;
                }
                $cells = $this->cellSets->rasterize($wkt);
                if ($cells !== null) {
                    $converted[(string) $band] = base64_encode($cells);
                }
            }
            if (!empty($converted)) {
                $out[(string) $lane] = $converted;
            }
        }

        if ($dryRun) {
            // Nothing was rasterised on purpose. THIS row is fillable when it
            // has at least one ring of its own - counted per row, not from the
            // run's running total, or every row after the first ringed one
            // would report fillable whether it carried a ring or not.
            return $thisRow > 0 ? [] : null;
        }

        return empty($out) ? null : $out;
    }

    private function mark(): int
    {
        $row = DB::table('config')->where('key', self::CONFIG_KEY_MARK)->first();

        return $row && $row->value !== null && $row->value !== '' ? (int) $row->value : 0;
    }

    private function saveMark(int $msgid): void
    {
        DB::table('config')->updateOrInsert(
            ['key' => self::CONFIG_KEY_MARK],
            ['value' => (string) $msgid]
        );
    }
}
