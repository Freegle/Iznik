<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The index MaxReachService::populate's candidate scan actually needs.
 *
 * That scan looks for expanding posts which still lack a max reach, newest
 * first, LIMIT 200. Once every expanding post HAS one the predicate matches
 * nothing, and a LIMIT that is never satisfied cannot stop a scan early - so it
 * walked the whole updated_at index to prove the empty set: 55,990 row lookups
 * in a ~50GB table, 2m09s on an idle db1, once a minute, on the read node.
 * PR #1404 fixed the immediate bleeding with FORCE INDEX on the status index
 * (2m09s -> 5.3s) and deliberately stopped there, because the real fix is DDL
 * and #1404 was a code-only change. This is that DDL, folded in here because
 * this branch is already doing schema work on the table.
 *
 * Measured on a 55,015-row clone matching production's distribution
 * (8,299 expanding, and ZERO rows matching the predicate - the live state):
 *
 *   planner's own choice      full updated_at walk, backward scan
 *   FORCE INDEX (status)      13,696 rows examined + FILESORT
 *   this index                     1 row examined, no filesort
 *
 * WHY A GENERATED COLUMN. "Lacks a max reach" is max_polygon_cells IS NULL, and
 * max_polygon_cells is a MEDIUMBLOB, which cannot be indexed without a prefix
 * length - and a prefix would not answer nullness anyway. So the nullness is
 * hoisted into a VIRTUAL generated column and that is what gets indexed. This
 * is the same idiom has_overflow already uses on this table, for the same
 * reason.
 *
 * The column is defined over max_polygon_cells ALONE, deliberately, even though
 * the transition era's predicate also tests max_polygon and max_polygon_hash.
 * Including those would make MySQL refuse to drop them later - a generated
 * column pins every column it references - which would break the drop
 * migration. The cost is that the index only becomes a true lookup once the
 * max-reach backfill has filled max_polygon_cells; before that it still narrows
 * to the expanding rows, which is no worse than the FORCE INDEX it replaces.
 *
 * COLUMN ORDER. (status, has_max_reach, updated_at): equality on the first two,
 * then updated_at in index order, so ORDER BY updated_at DESC LIMIT 200 is a
 * backward index scan that stops at 200 rather than a filesort over everything
 * matching. Dropping updated_at from the index reinstates the filesort.
 *
 * NOTE FOR WHOEVER READS THE QUERY: MySQL does NOT substitute this column for
 * the expression automatically. Written as `max_polygon_cells IS NULL` the
 * planner ignores this index entirely and goes back to the updated_at walk;
 * only `has_max_reach = 0` uses it. Verified by EXPLAIN, both ways. That is why
 * MaxReachService names the generated column when it is available.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rippling_reach')) {
            return;
        }

        // Needs max_polygon_cells, which an earlier migration in this branch
        // adds. Guarded rather than assumed so a partial migration state says
        // so instead of failing on a missing column.
        if (!Schema::hasColumn('rippling_reach', 'max_polygon_cells')) {
            return;
        }

        if (!Schema::hasColumn('rippling_reach', 'has_max_reach')) {
            DB::statement(
                'ALTER TABLE rippling_reach
                    ADD COLUMN has_max_reach TINYINT(1)
                        GENERATED ALWAYS AS (max_polygon_cells IS NOT NULL) VIRTUAL'
            );
        }

        $index = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
                AND index_name = 'rippling_reach_maxreach_candidates'"
        );

        if ((int) ($index->n ?? 0) === 0) {
            DB::statement(
                'ALTER TABLE rippling_reach
                    ADD INDEX rippling_reach_maxreach_candidates (status, has_max_reach, updated_at)'
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('rippling_reach')) {
            return;
        }

        $index = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
                AND index_name = 'rippling_reach_maxreach_candidates'"
        );

        if ((int) ($index->n ?? 0) > 0) {
            DB::statement('ALTER TABLE rippling_reach DROP INDEX rippling_reach_maxreach_candidates');
        }

        // The index goes first, and not for the obvious reason. MySQL does NOT
        // refuse to drop a generated column an index still names - it silently
        // REWRITES the index without that column, so
        // (status, has_max_reach, updated_at) quietly becomes
        // (status, updated_at): same name, still present, wrong shape, no
        // warning. A guarded re-create that only checks the index NAME would
        // then skip it and leave the wrong index in place for good. Verified on
        // Percona 8.0.43-34.
        if (Schema::hasColumn('rippling_reach', 'has_max_reach')) {
            DB::statement('ALTER TABLE rippling_reach DROP COLUMN has_max_reach');
        }
    }
};
