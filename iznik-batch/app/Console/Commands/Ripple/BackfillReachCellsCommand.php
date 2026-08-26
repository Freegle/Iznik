<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\CellSetService;
use App\Services\Ripple\GeomShareService;
use App\Services\Ripple\LegacyGeometry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ripple:backfill-reach-cells - fills polygon_cells for rows whose current
 * reach was written BEFORE plans/2026-08-24-rippling-reach-raster-storage.md.
 *
 * ExpandService now writes polygon_cells alongside every polygon it writes, so
 * an EXPANDING post fills itself in within a tick or two. A post that has
 * stopped expanding never writes its polygon again, and stays browsable for up
 * to ~90 days, so without this those rows would keep every reader on the
 * sandwich-bounds-plus-exact-polygon path for the rest of their life.
 *
 * One HTTP call per row (the rasteriser lives in iznik-spatial-go - see
 * CellSetService::rasterize, the ONE place a polygon becomes its canonical
 * compact form), paced the same way ripple:backfill-max-reach-cells is: a
 * small --limit default and a --sleep-ms between rows so a sweep during
 * business hours does not compete with the routing and reply-serving traffic
 * that also calls spatial-go.
 *
 * Reads the WKT through the SAME COALESCE join the readers use
 * (GeomShareService): polygon may itself be content-addressed
 * (rippling_reach_geom) or already drained to NULL with only the hash
 * remaining (plans/2026-08-23-rippling-reach-polygon-dedup.md) - either way
 * the reach is known and can still be rasterised.
 *
 * Safety, in the same shape as every other Ripple backfill: bounded,
 * resumable via a config-table mark, one row per statement (Galera-safe),
 * dry-run, and updated_at held still - the reach mailer and spatial-go's own
 * delta poll both watch that column.
 */
class BackfillReachCellsCommand extends Command
{
    protected $signature = 'ripple:backfill-reach-cells
                            {--limit=100 : Max rows to process this run}
                            {--sleep-ms=50 : Pause between rows, to go easy on the rasterise endpoint. Set 0 and shard by --after to go as fast as the rasteriser allows.}
                            {--after= : Start BELOW this msgid instead of the stored mark (the sweep runs newest-first, so this is a ceiling). Also how to shard: give each worker its own range.}
                            {--reset-mark : Start from the beginning again}
                            {--include-expanding : Also convert rows still expanding (safe: the write is compare-and-swap; use to converge the drop guard at sweep speed instead of tick speed)}
                            {--dry-run : Report what would be filled without writing}';

    protected $description = 'Backfill rippling_reach.polygon_cells for rows whose current reach predates the raster-storage change';

    /** Where the sweep got to, so a bounded run can be repeated until done. */
    private const CONFIG_KEY_MARK = 'ripple_backfill_reach_cells_last_msgid';

    public function __construct(private CellSetService $cellSets)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // keep-raw: information_schema check for a column this Eloquent-less table has no model for
        $hasColumn = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
                AND column_name = 'polygon_cells'"
        );
        if (!$hasColumn || (int) $hasColumn->n === 0) {
            $this->error('rippling_reach.polygon_cells is not migrated yet; nothing to do.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $sleepMs = max(0, (int) $this->option('sleep-ms'));

        if ($this->option('reset-mark')) {
            $this->saveMark(0);
            $this->info('Mark reset.');
        }

        // This sweep converts the LEGACY polygon into cells, so once that
        // column is dropped its work is finished for good. Say so and succeed,
        // rather than crashing on a column that no longer exists: an operator
        // running it out of habit, or a scheduler entry nobody has pruned yet,
        // must not fill the logs with unknown-column errors.
        if (!LegacyGeometry::polygonReady()) {
            $this->info('rippling_reach.polygon has been dropped - every reach is stored as cells now, so this sweep is complete for good.');

            return self::SUCCESS;
        }

        // The resume mark is a CEILING that walks DOWNWARDS, because the sweep
        // runs newest-first. Zero or unset both mean "start at the top" - which
        // is also what --reset-mark leaves behind, so a reset restarts from the
        // newest row rather than finding nothing below zero.
        $mark = $this->mark();
        $before = $this->option('after') !== null
            ? (int) $this->option('after')
            : ($mark > 0 ? $mark : PHP_INT_MAX);
        $limit = max(1, (int) $this->option('limit'));

        $pJoin = GeomShareService::joinSql('rippling_reach', 'polygon', 'gp');
        $poly = GeomShareService::sourceExpr('rippling_reach', 'polygon', 'gp');

        // Candidates are the rows that have a reach but no cells for it, MINUS
        // (by default) the ones still expanding. `polygon` is NOT NULL on this
        // table, so unlike max_polygon there is no "does it have one at all"
        // question - only the drained case, which the COALESCE join covers.
        //
        // EXPANDING ROWS ARE SKIPPED BY DEFAULT, as an optimisation rather
        // than a safety requirement. ExpandService rewrites both the polygon
        // and the cells on every tick, so a row that ticks soon fills itself
        // and any work done here is thrown away; the compare-and-swap below
        // is what makes racing a tick harmless either way. Measured on
        // production 2026-08-25: 8,390 of 56,317 rows were expanding, so the
        // skip was ~15% less work.
        //
        // --include-expanding EXISTS BECAUSE "fills itself within a tick or
        // two" proved wrong for the tail. A tick only comes when
        // next_expansion_at falls due - hours or days away late in a schedule
        // - and a post's FINAL tick flips it to 'done' WITHOUT writing a
        // polygon (there is nothing left to expand to), so a pre-cells
        // expander whose only remaining step was "finish" lands in 'done'
        // with no cells, behind this sweep's mark, invisible to ticks.
        // Measured on production 2026-08-26: five hours after the deploy,
        // 5,839 expanding rows still had no cells and finishers were joining
        // 'done' cell-less at ~0.8/minute. Converting expanding rows directly
        // both drains that population at sweep speed instead of tick speed
        // and STOPS the finisher leak - a finisher then already carries
        // cells. Safe because every post-deploy polygon write includes cells:
        // a row with NULL cells has a polygon last written pre-deploy, i.e.
        // static, and if a tick does land mid-flight the CAS loses cleanly.
        //
        // NEWEST FIRST, deliberately. Every reach row is deleted by the
        // 90-day message purge, so the oldest rows are the ones with least
        // life left - converting them first spends the whole sweep on rows
        // about to disappear, and leaves the rows that will be queried for
        // months until last. (Nothing has reached the purge yet: the oldest
        // row is 2026-06-22, so today this only changes the order work is
        // done in, not the total. It will matter later.)
        $statusFilter = $this->option('include-expanding')
            ? ''
            : "AND rippling_reach.status <> 'expanding'";

        // keep-raw: the COALESCE-through-join WKT read has no query-builder equivalent
        $rows = DB::select(
            "SELECT rippling_reach.msgid, ST_AsText($poly) AS wkt
               FROM rippling_reach$pJoin
              WHERE rippling_reach.msgid < ?
                AND rippling_reach.polygon_cells IS NULL
                $statusFilter
              ORDER BY rippling_reach.msgid DESC
              LIMIT ?",
            [$before, $limit]
        );

        if (empty($rows)) {
            $this->info($before < PHP_INT_MAX
                ? "Nothing left below msgid {$before}. Sweep complete."
                : 'Nothing to backfill.');

            return self::SUCCESS;
        }

        $filled = 0;
        $skipped = 0;
        $lastMsgid = $before;

        foreach ($rows as $row) {
            $lastMsgid = (int) $row->msgid;

            if ($row->wkt === null) {
                // The join found no shared row and the blob is a sentinel or
                // gone - a dangling hash left behind by the retired dedup
                // layer. Not this command's job; leave it and move on (the
                // drop removes the hash columns entirely).
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $filled++;

                continue;
            }

            $cells = $this->cellSets->rasterize($row->wkt);
            if ($cells === null) {
                // Best-effort, like every other write in this pipeline: leave
                // it for the next run rather than fail the sweep.
                $skipped++;

                continue;
            }

            // COMPARE-AND-SWAP on `polygon_cells IS NULL`, not a blind write.
            // This command reads a row's WKT, makes an HTTP call to rasterise
            // it, then writes - and ExpandService::advanceDue is a separate
            // process that overwrites both polygon and polygon_cells on its
            // own cadence. Between the read and the write here, a tick can
            // land, so an unconditional UPDATE would replace fresh cells with
            // cells rasterised from the PREVIOUS reach. Nothing would ever
            // repair it either: this command skips non-NULL rows, and once
            // the post stops expanding advanceDue never revisits it - so the
            // reply gate would hold replies from people the post really does
            // reach, permanently. The condition makes the race a no-op
            // instead: whoever wrote first wins, and both wrote a grid for a
            // reach the row actually had.
            // keep-raw: `updated_at = updated_at` self-assignment to suppress the ON UPDATE auto-bump - the builder always emits a real value
            $n = DB::affectingStatement(
                'UPDATE rippling_reach SET polygon_cells = ?, updated_at = updated_at
                  WHERE msgid = ? AND polygon_cells IS NULL',
                [$cells, $lastMsgid]
            );
            if ($n < 1) {
                // A tick filled it while this row was being rasterised. Its
                // value is newer than ours; leave it.
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
            '%s %d row(s), skipped %d, across %d candidate(s). Mark now %d.',
            $dryRun ? 'Would fill' : 'Filled',
            $filled,
            $skipped,
            count($rows),
            $lastMsgid
        ));

        return self::SUCCESS;
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
