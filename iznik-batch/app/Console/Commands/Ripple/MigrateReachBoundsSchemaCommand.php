<?php

namespace App\Console\Commands\Ripple;

use App\Database\Expressions\Comparison;
use App\Database\Expressions\Now;
use App\Database\Expressions\StContains;
use App\Database\Expressions\StGeometryType;
use App\Database\Expressions\Value;
use App\Services\Ripple\ReachBoundsService;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Blueprint;
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

        // Reads the WRITE connection's clock deliberately (useWritePdo()) to watermark
        // rows written since the copy began. The clock itself is bindable - MySQL and
        // PHP agree here - but the watermark is compared against updated_at values
        // MySQL sets, and a watermark that runs even fractionally ahead of the server
        // silently drops delta rows, so it must come from the server via NOW() rather
        // than from PHP's now().
        $copyStart = DB::query()->useWritePdo()->value(new Now());

        // 1) Shadow with the full target schema. LIKE copies columns/indexes but not the
        //    FK, so the columns, index and FK are added explicitly. All cheap: empty table.
        if (!Schema::hasTable('rippling_reach_shadow')) {
            // keep-raw: CREATE TABLE ... LIKE has no Schema Blueprint equivalent - it
            // clones another table's live column/index definitions, which Blueprint
            // cannot express without first introspecting and re-declaring every column.
            // Verified: Blueprint has no like()/clone-style method - calling one throws
            // "BadMethodCallException: Method Illuminate\Database\Schema\Blueprint::like
            // does not exist."
            DB::statement('CREATE TABLE rippling_reach_shadow LIKE rippling_reach');
            Schema::table('rippling_reach_shadow', function (Blueprint $table) {
                $table->geometry('outer_bound', null, self::SRID);
                $table->geometry('inner_bound', null, self::SRID)->nullable();
                $table->spatialIndex('outer_bound', 'rippling_reach_outer');
            });
            Schema::table('rippling_reach_shadow', function (Blueprint $table) {
                $table->foreign('msgid', 'rippling_reach_shadow_msgid_foreign')
                    ->references('id')->on('messages')->onDelete('cascade');
            });
        }
        $this->info('Shadow table ready.');

        // 2) Chunked, resumable, msgid-ordered copy with per-row bounds derivation.
        $copied = 0;
        while (true) {
            // COALESCE(MAX(msgid), 0) becomes ->max() plus a PHP ?? 0: ->max()
            // returns null on an empty table, which is the only case COALESCE was
            // guarding. Keeping COALESCE would need DB::raw, itself a raw site.
            $cursor = (int) (DB::table('rippling_reach_shadow')->useWritePdo()->max('msgid') ?? 0);
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
                    ->useWritePdo()
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
            // the same rows forever. Same write-connection-clock reasoning as the
            // initial watermark read above.
            $copyStart = DB::query()->useWritePdo()->value(new Now());
        } while (count($deltaIds) === $chunk);
        $this->info("Delta pass complete ({$delta} rows).");

        // 4) Atomic swap. No inbound FKs reference rippling_reach, so this is clean.
        // keep-raw: a multi-table RENAME TABLE is a single atomic operation in MySQL;
        // Schema::rename() only renames one table per call, so two calls would leave a
        // window where neither name (or the wrong one) is live for concurrent readers.
        // Verified: Schema\Builder::rename($from, $to) takes exactly one pair - passing
        // an array of pairs throws "ArgumentCountError: Too few arguments to function
        // Illuminate\Database\Schema\Builder::rename(), 1 passed ... and exactly 2
        // expected"; there is no atomic multi-table form to fall back to.
        DB::statement('RENAME TABLE rippling_reach TO rippling_reach_old, rippling_reach_shadow TO rippling_reach');
        $this->info('Swapped. Old table kept as rippling_reach_old — DROP it manually once satisfied.');

        // 5) Verify: counts + a sample sandwich check.
        $oldN = (int) DB::table('rippling_reach_old')->useWritePdo()->count();
        $newN = (int) DB::table('rippling_reach')->useWritePdo()->count();
        // ->limit(100)->get()->count() applies the cap BEFORE counting, same as the
        // original subquery. (->limit(100)->count() would NOT do this - Laravel's
        // aggregate() leaves the LIMIT on a query that already collapses to one row,
        // so it would count every violation in the table instead of capping the
        // sample at 100.)
        $badSample = DB::table('rippling_reach')
            ->useWritePdo()
            ->select('msgid')
            ->where(new Comparison(new StGeometryType('outer_bound'), '<>', Value::of('POINT')))
            ->whereNot(new StContains('outer_bound', 'polygon'))
            ->limit(100)
            ->get()
            ->count();
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
            false,
            fn (): QueryBuilder => DB::table('rippling_reach as rr')
                ->where('rr.msgid', '>', $cursor)
                ->orderBy('rr.msgid')
                ->limit($chunk)
        );
    }

    /** Re-copy one row (delta pass), replacing any stale shadow copy. */
    private function replaceRow(int $msgid): void
    {
        DB::table('rippling_reach_shadow')->where('msgid', $msgid)->delete();
        // The source row may have been deleted since the delta SELECT — then this
        // inserts nothing, which is exactly right.
        $this->insertRows(
            'rr.msgid = ' . $msgid,
            true,
            fn (): QueryBuilder => DB::table('rippling_reach as rr')->where('rr.msgid', $msgid)
        );
    }

    /**
     * INSERT..SELECT into the shadow for rows matching $where, deriving bounds with the
     * sentinel ladder. Falls back to envelope-only for chunks whose geometry makes the
     * derivation throw (then per-row so one bad polygon cannot poison a whole chunk).
     *
     * $matchingRows builds the SAME row set as $where, via the query builder, for the
     * row-by-row retry fallback below. It is a second parameter rather than one derived
     * from the other because $where must stay a raw SQL fragment for the necessarily-raw
     * INSERT..SELECT (see the keep-raw note in $insert below), while the retry-path
     * SELECT has no such constraint and can go through the builder directly. Both are
     * built from the same $cursor/$chunk or $msgid the caller was given, so they cannot
     * drift independently.
     */
    private function insertRows(string $where, bool $single, \Closure $matchingRows): int
    {
        // keep-raw: this INSERT..SELECT selects rr.* (every column of rippling_reach,
        // unknown to the ORM since the shadow table's shape comes from a runtime
        // CREATE TABLE ... LIKE) alongside CASE WHEN EXISTS(...) expressions built from
        // ST_SRID/POINT and ReachBoundsService's own ST_* fragments - CASE WHEN and ST_*
        // both have no query builder equivalent, so the select list must stay raw
        // regardless of how the INSERT/SELECT shell is expressed.
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
            // Retry the chunk row-by-row so one bad polygon cannot poison it. useWritePdo()
            // matches the previous raw connection-pinned read this replaced - the row set
            // must come from the same connection the INSERT..SELECT above just ran against.
            $ids = $matchingRows()->useWritePdo()->pluck('rr.msgid')
                ->map(fn ($v) => (int) $v)->all();
            $n = 0;
            foreach ($ids as $msgid) {
                $this->replaceRow($msgid);
                $n++;
            }

            return $n;
        }
    }
}
