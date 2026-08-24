<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\CellSetService;
use App\Services\Ripple\GeomShareService;
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
                            {--sleep-ms=50 : Pause between rows, to go easy on the rasterise endpoint}
                            {--after= : Start after this msgid instead of the stored mark}
                            {--reset-mark : Start from the beginning again}
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

        $after = $this->option('after') !== null ? (int) $this->option('after') : $this->mark();
        $limit = max(1, (int) $this->option('limit'));

        $pJoin = GeomShareService::joinSql('rippling_reach', 'polygon', 'gp');
        $poly = GeomShareService::sourceExpr('rippling_reach', 'polygon', 'gp');

        // Candidates are exactly the rows that have a reach but no cells for
        // it. `polygon` is NOT NULL on this table, so unlike max_polygon there
        // is no "does it have one at all" question to ask - only the drained
        // case, which the COALESCE join covers.
        // keep-raw: the COALESCE-through-join WKT read has no query-builder equivalent
        $rows = DB::select(
            "SELECT rippling_reach.msgid, ST_AsText($poly) AS wkt
               FROM rippling_reach$pJoin
              WHERE rippling_reach.msgid > ?
                AND rippling_reach.polygon_cells IS NULL
              ORDER BY rippling_reach.msgid
              LIMIT ?",
            [$after, $limit]
        );

        if (empty($rows)) {
            $this->info($after > 0
                ? "Nothing left after msgid {$after}. Sweep complete."
                : 'Nothing to backfill.');

            return self::SUCCESS;
        }

        $filled = 0;
        $skipped = 0;
        $lastMsgid = $after;

        foreach ($rows as $row) {
            $lastMsgid = (int) $row->msgid;

            if ($row->wkt === null) {
                // The join found no shared row and the blob is a sentinel or
                // gone - a dangling hash. ripple:verify-geometry-dedup's job,
                // not this command's; leave it and move on.
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

            // keep-raw: `updated_at = updated_at` self-assignment to suppress the ON UPDATE auto-bump - the builder always emits a real value
            DB::statement(
                'UPDATE rippling_reach SET polygon_cells = ?, updated_at = updated_at WHERE msgid = ?',
                [$cells, $lastMsgid]
            );
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
