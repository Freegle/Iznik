<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\GeomShareService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ripple:drain-deduped-blobs — Stage 4 of the reach geometry dedup
 * (plans/2026-08-23-rippling-reach-polygon-dedup.md): the step that actually
 * frees disk. For rows whose geometry is verified in rippling_reach_geom,
 * replace the duplicated blob: max_polygon becomes NULL; polygon becomes the
 * degenerate sentinel POINT, because that column is NOT NULL with a live
 * SPATIAL index (an R-tree column cannot be nullable, and dropping the index
 * on prod means rebuilding a 50 GB table - the design doc's "SET polygon =
 * NULL" was written before that constraint was found). Same idiom as the
 * degenerate-POINT outer_bound for completed posts. Readers never see the
 * sentinel: a drained row's hash is set, so COALESCE prefers the shared bytes.
 *
 * Every drain statement re-verifies IN ITS OWN WHERE that the shared row
 * exists and that BOTH copies still hash to the pointer - so a drain can never
 * orphan a row whose hash was stale, dangling or mid-clip. A row failing that
 * guard is reported as refused, left untouched, and is the checker's job.
 *
 * status <> 'expanding' only: an expanding post's polygon is rewritten every
 * tick anyway, so draining it is churn for no saving. The freed LOB pages go
 * back to the tablespace free list, not the OS: success is the .ibd file NOT
 * GROWING while inserts continue, never data_free (measured 2026-08-23).
 *
 * Bounded, resumable, one row per statement (Galera-safe), dry-run, and
 * updated_at held still - the reach mailer and spatial-go's delta poll watch
 * that column.
 */
class DrainDedupedBlobsCommand extends Command
{
    protected $signature = 'ripple:drain-deduped-blobs
                            {--limit=500 : Max rows to drain this run}
                            {--after= : Start after this msgid instead of the stored mark}
                            {--reset-mark : Start from the beginning again}
                            {--dry-run : Report what would be drained without writing}';

    protected $description = 'Free verified-deduplicated rippling_reach blobs (polygon -> sentinel, max_polygon -> NULL)';

    /** Where the sweep got to, so a bounded run can be repeated until done. */
    private const CONFIG_KEY_MARK = 'ripple_drain_deduped_blobs_last_msgid';

    public function handle(): int
    {
        if (!GeomShareService::ready()) {
            $this->error('rippling_reach_geom is not migrated yet; nothing to drain.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('reset-mark')) {
            $this->saveMark(0);
            $this->info('Mark reset.');
        }

        $after = $this->option('after') !== null ? (int) $this->option('after') : $this->mark();
        $limit = max(1, (int) $this->option('limit'));

        $polyDrained = GeomShareService::drainedExpr('rippling_reach', 'polygon');
        $rows = DB::table('rippling_reach')
            ->select('msgid')
            // keep-raw: undrained-blob presence flags need MD5/ST_AsBinary sentinel tests - the builder cannot render these
            ->selectRaw("(polygon_hash IS NOT NULL AND NOT ($polyDrained)) AS poly_todo,
                         (max_polygon_hash IS NOT NULL AND max_polygon IS NOT NULL) AS max_todo,
                         OCTET_LENGTH(polygon) + COALESCE(OCTET_LENGTH(max_polygon), 0) AS bytes_now")
            ->where('msgid', '>', $after)
            ->where('status', '<>', 'expanding')
            ->whereRaw('(polygon_hash IS NOT NULL OR max_polygon_hash IS NOT NULL)')
            ->orderBy('msgid')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->info($after > 0
                ? "Nothing left after msgid {$after}. Sweep complete."
                : 'Nothing drainable.');

            return self::SUCCESS;
        }

        $drained = 0;
        $refused = 0;
        $bytesFreed = 0;
        $lastMsgid = $after;

        foreach ($rows as $row) {
            $lastMsgid = (int) $row->msgid;
            $todo = ((int) $row->poly_todo === 1 ? 1 : 0) + ((int) $row->max_todo === 1 ? 1 : 0);
            if ($todo === 0) {
                continue; // already drained on both counts - not re-counted, not refused
            }

            if ($dryRun) {
                $drained++;
                $bytesFreed += (int) $row->bytes_now;

                continue;
            }

            $done = 0;
            if ((int) $row->poly_todo === 1) {
                $done += $this->drainColumn($lastMsgid, 'polygon');
            }
            if ((int) $row->max_todo === 1) {
                $done += $this->drainColumn($lastMsgid, 'max_polygon');
            }

            if ($done === $todo) {
                $drained++;
                $bytesFreed += (int) $row->bytes_now;
            } else {
                // Say so per row: the guard refusing means hash, blob and shared row
                // disagree, which the checker must see before this command re-runs.
                $this->warn("msgid {$lastMsgid}: refused (verification guard) - left unchanged");
                $refused++;
            }
        }

        if (!$dryRun && $this->option('after') === null) {
            $this->saveMark($lastMsgid);
        }

        $this->info(sprintf(
            '%s %d row(s), refused %d, ~%s of blob replaced. Mark now %d.',
            $dryRun ? 'Would drain' : 'Drained',
            $drained,
            $refused,
            $this->bytes($bytesFreed),
            $lastMsgid
        ));

        if ($refused > 0) {
            $this->warn("{$refused} row(s) refused the verification guard - run ripple:verify-geometry-dedup before re-running.");
        }

        return self::SUCCESS;
    }

    /**
     * Drain one column of one row, 1 on success. The WHERE is the safety: the
     * shared row must exist and BOTH copies must hash to the pointer, checked
     * in the same statement that overwrites the blob - there is no window in
     * which a clip's hash detach or a dangling pointer can slip between check
     * and write. Only one spatial-indexed column is touched per statement
     * (polygon), so the 1713 undo-log trap of pairing it with outer_bound
     * cannot arise; the sentinel's undo image is the OLD blob alone, which
     * every single-column rewrite of this table already carries.
     */
    private function drainColumn(int $msgid, string $col): int
    {
        $set = $col === 'polygon'
            ? "r.polygon = ST_GeomFromText('" . GeomShareService::DRAIN_SENTINEL_WKT . "', " . GeomShareService::SRID . ')'
            : 'r.max_polygon = NULL';

        // keep-raw: multi-table UPDATE with MD5/ST_AsBinary verification guards in WHERE - the builder cannot render these
        return DB::affectingStatement(
            "UPDATE rippling_reach r
               JOIN rippling_reach_geom g ON g.hash = r.{$col}_hash
                SET $set, r.updated_at = r.updated_at
              WHERE r.msgid = ?
                AND r.{$col}_hash IS NOT NULL
                AND UNHEX(MD5(ST_AsBinary(g.geom))) = r.{$col}_hash
                AND UNHEX(MD5(ST_AsBinary(r.{$col}))) = r.{$col}_hash",
            [$msgid]
        ) > 0 ? 1 : 0;
    }

    private function bytes(int $n): string
    {
        if (abs($n) < 1024) {
            return $n . 'B';
        }
        if (abs($n) < 1024 * 1024) {
            return round($n / 1024, 1) . 'KB';
        }

        return round($n / 1024 / 1024, 1) . 'MB';
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
