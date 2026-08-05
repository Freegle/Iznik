<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\ReachBoundsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ripple:migrate-reach-bounds-schema — pt-online-schema-change-style migration of
 * rippling_reach to the sandwich-bounds schema, for PRODUCTION
 * (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md).
 *
 * A plain ALTER cannot deliver the target schema on the ~10 GB hot table: the NOT NULL
 * conversion and SPATIAL index build rebuild the tablespace, and on Galera every DDL is
 * TOI — the whole cluster blocks writes for the rebuild (verified on Percona 8.0.43:
 * ALGORITHM=INSTANT is refused for both). So instead:
 *
 *   1. Create rippling_reach_shadow with the FULL target schema (bounds columns
 *      NOT NULL + SPATIAL index) — empty, so the index build is free.
 *   2. Chunked copy, deriving the bounds per row with the sentinel ladder:
 *      real bounds > ST_Envelope (derivation threw) > degenerate POINT for COMPLETED
 *      posts (messages_spatial.successful=1), pruning them from the new R-tree from
 *      day one. Resumable: the copy is msgid-ordered, so it restarts from the shadow's
 *      MAX(msgid).
 *   3. Delta pass: re-copy rows whose updated_at changed after the copy started
 *      (catches clip/status writes that happened mid-copy).
 *   4. Atomic RENAME swap; the displaced table is kept as rippling_reach_old for
 *      manual inspection/DROP.
 *
 * QUIESCE FIRST: pause the ripple crons (ripple:expand etc.) before --execute. The
 * delta pass catches stragglers, but a quiet table makes the swap race-free. Nothing
 * else needs downtime — readers fall back to legacy queries until the Go API restarts.
 */
class MigrateReachBoundsSchemaCommand extends Command
{
    protected $signature = 'ripple:migrate-reach-bounds-schema
                            {--execute : Actually run (default is a dry-run report)}
                            {--chunk=500 : Rows per copy chunk}
                            {--sleep-ms=50 : Pause between chunks, to pace replication}';

    protected $description = 'Shadow-copy rippling_reach to the sandwich-bounds schema and swap (prod-safe)';

    private const SRID = 3857;

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));

        if (Schema::hasColumn('rippling_reach', 'outer_bound')) {
            $this->info('rippling_reach already has the bounds columns — nothing to do.');

            return Command::SUCCESS;
        }

        $total = (int) DB::table('rippling_reach')->count();
        if (!$this->option('execute')) {
            $chunks = (int) ceil($total / $chunk);
            $this->info("DRY RUN: would shadow-copy {$total} rows in ~{$chunks} chunks of {$chunk} "
                . "({$sleepMs}ms pause), then delta-sync and RENAME-swap. Quiesce the ripple crons, "
                . 'then re-run with --execute.');

            return Command::SUCCESS;
        }

        $copyStart = DB::selectOne('SELECT NOW() AS t', [], false)->t;

        // 1) Shadow with the full target schema. LIKE copies columns/indexes but not the
        //    FK, so the columns, index and FK are added explicitly. All cheap: empty table.
        if (!Schema::hasTable('rippling_reach_shadow')) {
            DB::statement('CREATE TABLE rippling_reach_shadow LIKE rippling_reach');
            DB::statement(
                'ALTER TABLE rippling_reach_shadow
                    ADD COLUMN outer_bound GEOMETRY NOT NULL SRID ' . self::SRID . ',
                    ADD COLUMN inner_bound GEOMETRY SRID ' . self::SRID . ' NULL,
                    ADD SPATIAL INDEX rippling_reach_outer (outer_bound)'
            );
            DB::statement(
                'ALTER TABLE rippling_reach_shadow
                    ADD CONSTRAINT rippling_reach_shadow_msgid_foreign
                    FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE'
            );
        }
        $this->info('Shadow table ready.');

        // 2) Chunked, resumable, msgid-ordered copy with per-row bounds derivation.
        $copied = 0;
        while (true) {
            // COALESCE(MAX(msgid), 0) becomes ->max() plus a PHP ?? 0: ->max()
            // returns null on an empty table, which is the only case COALESCE was
            // guarding. Keeping COALESCE would need DB::raw, itself a raw site.
            $cursor = (int) (DB::table('rippling_reach_shadow')->max('msgid') ?? 0);
            $done = $this->copyChunk($cursor, $chunk);
            if ($done === 0) {
                break;
            }
            $copied += $done;
            $this->output->write("\rCopied {$copied} rows...");
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }
        $this->newLine();
        $this->info("Copy complete ({$copied} rows this run).");

        // 3) Delta: rows written after the copy started (clips, status flips, new posts
        //    if the crons were not fully quiesced). REPLACE re-derives their bounds.
        $delta = 0;
        do {
            $deltaIds = array_map(
                fn ($r) => (int) $r->msgid,
                DB::table('rippling_reach as rr')
                    ->select('rr.msgid')
                    // leftJoin, not join: the whole point is finding rows with
                    // NO shadow row, which an inner join would exclude.
                    ->leftJoin('rippling_reach_shadow as s', 's.msgid', '=', 'rr.msgid')
                    ->where(function ($q) use ($copyStart) {
                        $q->whereNull('s.msgid')
                          ->orWhere('rr.updated_at', '>=', $copyStart);
                    })
                    ->limit($chunk)
                    ->get()
                    ->all()
            );
            foreach ($deltaIds as $msgid) {
                $this->replaceRow($msgid);
                $delta++;
            }
            // Advance the watermark so a busy table converges instead of re-copying
            // the same rows forever.
            $copyStart = DB::selectOne('SELECT NOW() AS t', [], false)->t;
        } while (count($deltaIds) === $chunk);
        $this->info("Delta pass complete ({$delta} rows).");

        // 4) Atomic swap. No inbound FKs reference rippling_reach, so this is clean.
        DB::statement('RENAME TABLE rippling_reach TO rippling_reach_old, rippling_reach_shadow TO rippling_reach');
        $this->info('Swapped. Old table kept as rippling_reach_old — DROP it manually once satisfied.');

        // 5) Verify: counts + a sample sandwich check.
        $oldN = (int) DB::table('rippling_reach_old')->count();
        $newN = (int) DB::table('rippling_reach')->count();
        $badSample = (int) DB::selectOne(
            'SELECT COUNT(*) AS n FROM (
                SELECT msgid FROM rippling_reach
                 WHERE ST_GeometryType(outer_bound) <> \'POINT\'
                   AND NOT ST_Contains(outer_bound, polygon) LIMIT 100
             ) x',
            [],
            false
        )->n;
        $this->info("Verify: old={$oldN} new={$newN} sandwich-violations(sample)={$badSample}.");
        if ($newN < $oldN || $badSample > 0) {
            $this->error('VERIFY FAILED — investigate before dropping rippling_reach_old '
                . '(swap back with: RENAME TABLE rippling_reach TO rippling_reach_shadow, '
                . 'rippling_reach_old TO rippling_reach).');

            return Command::FAILURE;
        }

        $this->info('Done. Re-enable the ripple crons and restart the Go API (schema check is process-cached).');

        return Command::SUCCESS;
    }

    /** Copy the next $chunk rows above $cursor; returns rows copied. */
    private function copyChunk(int $cursor, int $chunk): int
    {
        return $this->insertRows(
            'rr.msgid > ' . $cursor . ' ORDER BY rr.msgid LIMIT ' . $chunk,
            false
        );
    }

    /** Re-copy one row (delta pass), replacing any stale shadow copy. */
    private function replaceRow(int $msgid): void
    {
        DB::table('rippling_reach_shadow')->where('msgid', $msgid)->delete();
        // The source row may have been deleted since the delta SELECT — then this
        // inserts nothing, which is exactly right.
        $this->insertRows('rr.msgid = ' . $msgid, true);
    }

    /**
     * INSERT..SELECT into the shadow for rows matching $where, deriving bounds with the
     * sentinel ladder. Falls back to envelope-only for chunks whose geometry makes the
     * derivation throw (then per-row so one bad polygon cannot poison a whole chunk).
     */
    private function insertRows(string $where, bool $single): int
    {
        $insert = function (string $outerExpr, string $innerExpr) use ($where): int {
            return DB::affectingStatement(
                'INSERT INTO rippling_reach_shadow
                 SELECT rr.*, ' . $outerExpr . ' AS outer_bound, ' . $innerExpr . ' AS inner_bound
                   FROM rippling_reach rr
                  WHERE ' . $where
            );
        };
        // Completed posts get the POINT sentinel (pruned from the new R-tree); open
        // posts get real derived bounds.
        $outer = 'CASE WHEN EXISTS (SELECT 1 FROM messages_spatial ms WHERE ms.msgid = rr.msgid AND ms.successful = 1)
                       THEN ST_SRID(POINT(rr.lng, rr.lat), ' . self::SRID . ')
                       ELSE ' . ReachBoundsService::outerExpr('rr.polygon') . ' END';
        $inner = 'CASE WHEN EXISTS (SELECT 1 FROM messages_spatial ms WHERE ms.msgid = rr.msgid AND ms.successful = 1)
                       THEN NULL
                       ELSE ' . ReachBoundsService::innerExpr('rr.polygon') . ' END';

        try {
            return $insert($outer, $inner);
        } catch (\Throwable) {
            if ($single) {
                // Envelope ladder rung for a single pathological row.
                try {
                    return $insert('ST_Envelope(rr.polygon)', 'NULL');
                } catch (\Throwable) {
                    // Final rung: degenerate point. The row keeps its exact polygon for
                    // the non-browse consumers; browse loses a geometrically-broken row.
                    return $insert('ST_SRID(POINT(rr.lng, rr.lat), ' . self::SRID . ')', 'NULL');
                }
            }
            // Retry the chunk row-by-row so one bad polygon cannot poison it.
            $ids = array_map(
                fn ($r) => (int) $r->msgid,
                DB::select('SELECT rr.msgid FROM rippling_reach rr WHERE ' . $where, [], false)
            );
            $n = 0;
            foreach ($ids as $msgid) {
                $this->replaceRow($msgid);
                $n++;
            }

            return $n;
        }
    }
}
