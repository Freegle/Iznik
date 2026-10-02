<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything rippling_reach GAINS for cell-set storage, in two operations.
 *
 * This replaces four separate migrations - one per cells column, plus one for
 * the maxreach candidate index. Each was a distinct schema change an operator
 * had to run node by node under RSU on a ~50GB table, and there is no reason
 * for four passes when two will do.
 *
 * TWO, NOT ONE, AND THAT IS DELIBERATE. Fewer statements is not automatically
 * less work. Measured on Percona 8.0.43-34:
 *
 *   - the four column adds together, ALGORITHM=INSTANT: metadata only,
 *     TOTAL_ROW_VERSIONS ticks up, no data touched, seconds.
 *   - the index alone, ALGORITHM=INPLACE, LOCK=NONE: online, no rebuild.
 *   - all five in ONE alter: runs, but TOTAL_ROW_VERSIONS resets to 0, i.e. it
 *     REBUILDS THE WHOLE TABLE - and that form cannot be LOCK=NONE either, so
 *     it would block writes for the length of a 50GB rebuild.
 *
 * Merging the last two would therefore turn a free change into the most
 * expensive one in the whole plan. Two cheap passes beat one costly one.
 *
 * WHAT THE COLUMNS ARE:
 *
 *  - polygon_cells / max_polygon_cells / overflow_cells: the compact cell-set
 *    form of polygon, max_polygon and overflow_bounds - the membership grid the
 *    routing server already computes, RLE-compressed on a fixed 0.0003-degree
 *    lattice, instead of an ~11k-vertex boundary tracing of it. Measured on six
 *    real production polygons: 19.5x to 22.0x smaller. All nullable, so a
 *    deploy ahead of the backfill is a no-op and every reader falls back to the
 *    geometry. MEDIUMBLOB rather than BLOB because the largest reaches are
 *    unbounded in area and this must never silently truncate; overflow_cells is
 *    JSON because it mirrors overflow_bounds' per-lane nesting.
 *
 *  - has_max_reach + rippling_reach_maxreach_candidates: the index
 *    MaxReachService's candidate scan needs. That scan looks for expanding
 *    posts still lacking a max reach, newest first, LIMIT 200, once a minute.
 *    Once every expanding post HAS one the predicate matches nothing, and a
 *    LIMIT that is never satisfied cannot stop a scan early - so it walked the
 *    whole updated_at index to prove the empty set: 55,990 row lookups, 2m09s
 *    on an idle db1, on the read node. PR #1404 fixed the bleeding with a
 *    FORCE INDEX and deliberately stopped there because the real fix is DDL.
 *    This is that DDL. Measured on a clone matching production: 13,696 rows
 *    plus a filesort becomes one row and no sort.
 *
 * WHY A GENERATED COLUMN: "lacks a max reach" is max_polygon_cells IS NULL, and
 * a MEDIUMBLOB cannot be indexed without a prefix - which would not answer
 * nullness anyway. So the nullness is hoisted into a VIRTUAL column and that is
 * indexed, the same idiom has_overflow already uses on this table.
 *
 * It references max_polygon_cells ALONE, deliberately, even though the
 * transition era's predicate also tests max_polygon and max_polygon_hash: a
 * generated column pins every column it names, so including those would make
 * MySQL refuse to drop them later and break the drop migration outright.
 *
 * AND MYSQL WILL NOT SUBSTITUTE IT FOR THE EXPRESSION. Written as
 * `max_polygon_cells IS NULL` the planner ignores this index completely and
 * goes back to the updated_at walk; only `has_max_reach = 0` uses it. Verified
 * by EXPLAIN both ways, which is why MaxReachService names the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rippling_reach')) {
            return;
        }

        // ONE: every column, metadata only. Pinned INSTANT so anything that
        // would need a copy refuses loudly instead of quietly rebuilding 50GB.
        $adds = [];
        foreach ([
            'polygon_cells' => 'MEDIUMBLOB NULL',
            'max_polygon_cells' => 'MEDIUMBLOB NULL',
            'overflow_cells' => 'JSON NULL',
        ] as $col => $type) {
            if (!Schema::hasColumn('rippling_reach', $col)) {
                $adds[] = "ADD COLUMN {$col} {$type}";
            }
        }
        if (!Schema::hasColumn('rippling_reach', 'has_max_reach')) {
            $adds[] = 'ADD COLUMN has_max_reach TINYINT(1) '
                .'GENERATED ALWAYS AS (max_polygon_cells IS NOT NULL) VIRTUAL';
        }
        if ($adds) {
            DB::statement('ALTER TABLE rippling_reach '.implode(', ', $adds).', ALGORITHM=INSTANT');
        }

        // TWO: the index, online and on its own. Kept separate on purpose -
        // see the class comment for the measurement.
        if (!$this->hasIndex('rippling_reach_maxreach_candidates')
            && Schema::hasColumn('rippling_reach', 'has_max_reach')) {
            DB::statement(
                'ALTER TABLE rippling_reach
                    ADD INDEX rippling_reach_maxreach_candidates (status, has_max_reach, updated_at),
                    ALGORITHM=INPLACE, LOCK=NONE'
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('rippling_reach')) {
            return;
        }

        // The index first: dropping has_max_reach would NOT fail while an index
        // names it, it would SILENTLY REWRITE that index without the column,
        // leaving the name in place over the wrong columns.
        if ($this->hasIndex('rippling_reach_maxreach_candidates')) {
            DB::statement('ALTER TABLE rippling_reach DROP INDEX rippling_reach_maxreach_candidates');
        }

        // The generated column alone - it cannot share an alter with the rest.
        if (Schema::hasColumn('rippling_reach', 'has_max_reach')) {
            DB::statement('ALTER TABLE rippling_reach DROP COLUMN has_max_reach');
        }

        $drops = [];
        foreach (['polygon_cells', 'max_polygon_cells', 'overflow_cells'] as $col) {
            if (Schema::hasColumn('rippling_reach', $col)) {
                $drops[] = "DROP COLUMN {$col}";
            }
        }
        if ($drops) {
            DB::statement('ALTER TABLE rippling_reach '.implode(', ', $drops));
        }
    }

    private function hasIndex(string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
                AND index_name = ?",
            [$name]
        );

        return (int) ($row->n ?? 0) > 0;
    }
};
