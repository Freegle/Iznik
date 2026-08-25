<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\CellSetService;
use App\Services\Ripple\GeomShareService;
use App\Services\Ripple\LegacyGeometry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ripple:backfill-max-reach-cells — fills max_polygon_cells for rows whose
 * eventual reach was computed BEFORE plans/2026-08-24-rippling-reach-raster-
 * storage.md shipped.
 *
 * MaxReachService::populate() only ever fills rows where max_polygon IS NULL
 * (it is answering "does this row have an eventual reach yet at all") - once
 * a row has one, that method never revisits it, so it will NEVER pick up
 * max_polygon_cells for existing rows. Without this command the disk saving
 * this design exists for only reaches new rows going forward; the whole
 * historical table (up to ~90 days of it) would sit unconverted until it
 * naturally expires.
 *
 * One HTTP call per row (the rasteriser lives in iznik-spatial-go - see
 * CellSetService::rasterize, the ONE place a polygon becomes its canonical
 * compact form), so this is deliberately paced far below the pure-SQL
 * backfills' throughput: a small --limit default, and a --sleep-ms between
 * rows so a sweep run during business hours does not compete with the
 * routing/reply-serving traffic that also calls spatial-go.
 *
 * Reads the WKT to rasterise through the SAME COALESCE join the readers use
 * (GeomShareService): max_polygon may itself be content-addressed
 * (rippling_reach_geom) or already drained to NULL with only the hash
 * remaining (plans/2026-08-23-rippling-reach-polygon-dedup.md) - either way,
 * "a max reach is known" and this command can still rasterise it.
 *
 * Safety, in the same shape as every other Ripple backfill: bounded,
 * resumable via a config-table mark, one row per statement (Galera-safe),
 * dry-run, and updated_at held still - the reach mailer and spatial-go's own
 * delta poll watch that column.
 */
class BackfillMaxReachCellsCommand extends Command
{
    protected $signature = 'ripple:backfill-max-reach-cells
                            {--limit=100 : Max rows to process this run}
                            {--sleep-ms=50 : Pause between rows, to go easy on the rasterise endpoint}
                            {--after= : Start after this msgid instead of the stored mark}
                            {--reset-mark : Start from the beginning again}
                            {--dry-run : Report what would be filled without writing}';

    protected $description = 'Backfill rippling_reach.max_polygon_cells for rows whose eventual reach predates the raster-storage change';

    /** Where the sweep got to, so a bounded run can be repeated until done. */
    private const CONFIG_KEY_MARK = 'ripple_backfill_max_reach_cells_last_msgid';

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
                AND column_name = 'max_polygon_cells'"
        );
        if (!$hasColumn || (int) $hasColumn->n === 0) {
            $this->error('rippling_reach.max_polygon_cells is not migrated yet; nothing to do.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $sleepMs = max(0, (int) $this->option('sleep-ms'));

        if ($this->option('reset-mark')) {
            $this->saveMark(0);
            $this->info('Mark reset.');
        }

        // As with ripple:backfill-reach-cells: this converts the LEGACY
        // max_polygon into cells, so the drop ends its work for good. max_polygon
        // is dropped in the same DDL step as polygon, so the polygon guard
        // answers for both.
        if (!LegacyGeometry::polygonReady()) {
            $this->info('rippling_reach.max_polygon has been dropped - every max reach is stored as cells now, so this sweep is complete for good.');

            return self::SUCCESS;
        }

        $after = $this->option('after') !== null ? (int) $this->option('after') : $this->mark();
        $limit = max(1, (int) $this->option('limit'));

        $mJoin = GeomShareService::joinSql('rippling_reach', 'max_polygon', 'gm');
        $maxPoly = GeomShareService::sourceExpr('rippling_reach', 'max_polygon', 'gm');

        // A max reach is "known" via the blob OR the hash (a drained row keeps
        // only the hash) - candidates are exactly the rows that know one but
        // have not yet been rasterised into cells.
        // keep-raw: the COALESCE-through-join WKT read has no query-builder equivalent
        $rows = DB::select(
            "SELECT rippling_reach.msgid, ST_AsText($maxPoly) AS wkt
               FROM rippling_reach$mJoin
              WHERE rippling_reach.msgid > ?
                AND rippling_reach.max_polygon_cells IS NULL
                AND (rippling_reach.max_polygon IS NOT NULL OR rippling_reach.max_polygon_hash IS NOT NULL)
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
                // The join found no shared row and the blob is gone too - a
                // dangling hash left behind by the retired dedup layer. Not
                // this command's job; leave it and move on (the drop removes
                // the hash columns entirely).
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

            DB::statement(
                'UPDATE rippling_reach SET max_polygon_cells = ?, updated_at = updated_at WHERE msgid = ?',
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
