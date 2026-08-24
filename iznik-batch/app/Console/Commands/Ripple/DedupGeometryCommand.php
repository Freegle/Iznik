<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\GeomShareService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ripple:dedup-geometry — Stage 2 backfill for the reach geometry dedup
 * (plans/2026-08-23-rippling-reach-polygon-dedup.md).
 *
 * For every reach row whose polygon / max_polygon blob is not yet content-
 * addressed, upsert the blob's bytes into rippling_reach_geom and point the
 * row's hash column at it. Both statements derive the hash FROM THE STORED
 * BYTES THEMSELVES (GeomShareService::upsertFromRow / rehashFromRow), so the
 * backfill cannot disagree with the dual-writing services about
 * canonicalisation, and re-running over an already-done row is a no-op.
 *
 * Frees nothing by itself: the blob stays on the row (readers COALESCE to the
 * shared geometry via the hash, so either source serves). The disk win is
 * ripple:drain-deduped-blobs, which must only run after
 * ripple:verify-geometry-dedup is clean.
 *
 * Will not fully converge in one sweep while posts are live: an expanding
 * post's polygon is rewritten every tick (the dual-write keeps its hash
 * current, so ticks do not UNDO this backfill), but a clip can transiently
 * NULL a hash between this command's runs. Repeat until it reports nothing
 * left; the writers keep everything current from then on.
 *
 * Safety, in the same shape as the other Ripple backfills: bounded, resumable
 * via a config-table mark, one row per statement (Galera-safe), dry-run, and
 * updated_at held still - the reach mailer and spatial-go's delta poll watch
 * that column, and a bulk pass that bumped it once sent 38k+ emails.
 */
class DedupGeometryCommand extends Command
{
    protected $signature = 'ripple:dedup-geometry
                            {--limit=500 : Max rows to process this run}
                            {--after= : Start after this msgid instead of the stored mark}
                            {--reset-mark : Start from the beginning again}
                            {--dry-run : Report what would be filled without writing}';

    protected $description = 'Backfill rippling_reach polygon/max_polygon into content-addressed rippling_reach_geom';

    /** Where the sweep got to, so a bounded run can be repeated until done. */
    private const CONFIG_KEY_MARK = 'ripple_dedup_geometry_last_msgid';

    public function handle(): int
    {
        if (!GeomShareService::ready()) {
            $this->error('rippling_reach_geom is not migrated yet; nothing to do.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('reset-mark')) {
            $this->saveMark(0);
            $this->info('Mark reset.');
        }

        $after = $this->option('after') !== null ? (int) $this->option('after') : $this->mark();
        $limit = max(1, (int) $this->option('limit'));

        // Candidates by PK range: only msgid and the 16-byte hash columns are read
        // (max_polygon appears solely inside IS NOT NULL, which InnoDB answers from
        // the row header), so this never drags the multi-hundred-KB blobs through
        // the buffer pool just to find work.
        $rows = DB::table('rippling_reach')
            ->select('msgid')
            // keep-raw: IS NOT NULL presence flags as selected expressions - the builder cannot render these
            ->selectRaw('polygon_hash IS NULL AS need_poly,
                         (max_polygon IS NOT NULL AND max_polygon_hash IS NULL) AS need_max')
            ->where('msgid', '>', $after)
            ->whereRaw('(polygon_hash IS NULL OR (max_polygon IS NOT NULL AND max_polygon_hash IS NULL))')
            ->orderBy('msgid')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->info($after > 0
                ? "Nothing left after msgid {$after}. Sweep complete."
                : 'Nothing to dedup.');

            return self::SUCCESS;
        }

        $filledPoly = 0;
        $filledMax = 0;
        $lastMsgid = $after;

        foreach ($rows as $row) {
            $lastMsgid = (int) $row->msgid;

            if ((int) $row->need_poly === 1) {
                if (!$dryRun) {
                    GeomShareService::upsertFromRow($lastMsgid, 'polygon');
                    GeomShareService::rehashFromRow($lastMsgid, 'polygon');
                }
                $filledPoly++;
            }

            if ((int) $row->need_max === 1) {
                if (!$dryRun) {
                    GeomShareService::upsertFromRow($lastMsgid, 'max_polygon');
                    GeomShareService::rehashFromRow($lastMsgid, 'max_polygon');
                }
                $filledMax++;
            }
        }

        // Only advance the stored mark for a real sweep. A --after run is someone
        // looking at a specific range and must not move everyone else's place.
        if (!$dryRun && $this->option('after') === null) {
            $this->saveMark($lastMsgid);
        }

        $this->info(sprintf(
            '%s %d polygon hash(es) and %d max_polygon hash(es) across %d row(s). Mark now %d.',
            $dryRun ? 'Would fill' : 'Filled',
            $filledPoly,
            $filledMax,
            $rows->count(),
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
