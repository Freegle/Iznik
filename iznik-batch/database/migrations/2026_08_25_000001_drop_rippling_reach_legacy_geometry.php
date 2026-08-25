<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stop storing the reach polygons (plans/2026-08-24-rippling-reach-raster-
 * storage.md, Stage 3). This is the step the whole design exists for: until
 * now the cell grids sat ALONGSIDE the geometry they mirror, so the table was
 * fractionally bigger rather than dramatically smaller.
 *
 * Dropped here:
 *   polygon           GEOMETRY NOT NULL, and its SPATIAL INDEX
 *   max_polygon       GEOMETRY NULL
 *   overflow_bounds   JSON of ring WKT
 *   polygon_hash / max_polygon_hash, their indexes and FKs, and the shared
 *                     rippling_reach_geom table - the content-addressed dedup
 *                     layer from #1402, which existed only to shrink the
 *                     polygons that are now going entirely
 *
 * Kept deliberately:
 *   outer_bound / inner_bound. These are NOT envelopes but buffered
 *   simplifications (ReachBoundsService: ST_Buffer(ST_Simplify(reach, 0.002),
 *   +/-0.002)), about 19KB a row, and they are the R-tree access path every
 *   SQL-side prefilter drives from plus the cheap-accept tier. At that size
 *   they are noise against what is being removed, and losing the index would
 *   be the 2026-08-21 outage again.
 *   has_overflow is kept too, but REGENERATED from overflow_cells - same
 *   meaning, same index shape.
 *
 * WHY THIS IS SAFE TO RUN, AND WHEN. Every reader has been two-era since the
 * code that ships with this migration: App\Services\Ripple\LegacyGeometry
 * (PHP) and rippling.LegacyPolygonReady / LegacyOverflowReady (Go) ask the
 * schema which era they are in, and the legacy branches are simply dead once
 * the columns are absent. So the ONLY precondition is that every live row
 * carries polygon_cells - which the guard below enforces rather than trusts,
 * because a row without cells has no reach at all afterwards.
 *
 * OPT-IN, AND NOT YET ON BY DEFAULT ANYWHERE - a deliberate decision, not a
 * fudge, and the reason is test coverage rather than caution about the DDL
 * (which is proven: it has been run twice against a clone of the real table
 * structure, refusing correctly on an uncovered row and doing nothing on the
 * second pass).
 *
 * The code that ships with this migration is two-era, and the TRANSITION era -
 * legacy columns present, cells preferred - is what production runs FIRST, for
 * as long as the three backfills take. If dev and CI dropped the columns now,
 * every fixture that writes a polygon would have to be converted, and the
 * transition era would lose the only tests that execute its SQL rather than
 * merely inspect it. Trading away coverage of the era that runs first, to gain
 * coverage of the era that runs later, is the wrong way round.
 *
 * So: dev and CI keep both forms and keep testing both eras (the cells-only
 * branches are covered by PostDropEraTest, which forces the era guard rather
 * than the schema - see LegacyGeometry::fake). Production drops via the
 * companion .sql file, node by node under RSU, AFTER the backfills report
 * complete and ripple:verify-cells-parity has been run and READ. The follow-up
 * PR that deletes the now-dead legacy branches also converts the fixtures and
 * turns this migration on by default, so the schema and the code stop
 * diverging at the same moment.
 *
 * Set RIPPLE_DROP_LEGACY_GEOMETRY=1 to run it before then.
 *
 * THE DROPS ALONE DO NOT RECLAIM ANYTHING. Percona 8.0.29+ performs DROP
 * COLUMN as ALGORITHM=INSTANT - metadata only - so the old bytes stay in every
 * existing row until it is rewritten. The final ALTER ... FORCE is what
 * returns the ~50GB .ibd (plus ~19.4GB a node of rippling_reach_geom) to the
 * operating system, and it is the long one.
 *
 * NOT REVERSIBLE, and down() says so rather than pretending. The polygons are
 * a traced approximation of a routing grid; the cells are that same grid at a
 * fixed resolution. Going back would mean tracing every grid into a boundary
 * and calling it the original, which it would not be. The rollback for this
 * change is to not run it - which is why production keeps the columns until
 * the parity report has been read by a human.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rippling_reach')) {
            return;
        }

        // Opt-in - see the class comment for why this is off by default, and
        // when it turns on.
        if (!filter_var(env('RIPPLE_DROP_LEGACY_GEOMETRY', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        // The guard: never drop the only copy of anything. THREE columns are
        // being removed and each has its own mirror and its own backfill, so
        // each is checked separately - an earlier version checked only
        // polygon_cells and would happily have dropped max_polygon and
        // overflow_bounds while their mirrors were still empty, losing every
        // post's eventual reach and every overflow ring.
        //
        // polygon is NOT NULL, so every row must have cells. max_polygon and
        // overflow_bounds are nullable and are legitimately absent on most
        // rows, so the test is "has the old value but not the new one" rather
        // than "has no new value".
        $checks = [
            ['polygon', 'polygon_cells', null, 'ripple:backfill-reach-cells'],
            ['max_polygon', 'max_polygon_cells', 'max_polygon', 'ripple:backfill-max-reach-cells'],
            ['overflow_bounds', 'overflow_cells', 'overflow_bounds', 'ripple:backfill-ring-cells'],
        ];
        foreach ($checks as [$old, $new, $onlyWhereSet, $command]) {
            if (!Schema::hasColumn('rippling_reach', $old) || !Schema::hasColumn('rippling_reach', $new)) {
                continue;
            }
            $q = DB::table('rippling_reach')->whereNull($new);
            if ($onlyWhereSet !== null) {
                $q->whereNotNull($onlyWhereSet);
            }
            $uncovered = $q->count();
            if ($uncovered > 0) {
                throw new RuntimeException(
                    "Refusing to drop rippling_reach.{$old}: {$uncovered} row(s) have it but have no {$new}. "
                    . "Run {$command} to completion first."
                );
            }
        }

        // 1. The dedup layer: FKs, then indexes, then columns, then the table.
        foreach (['rippling_reach_polygon_hash_foreign', 'rippling_reach_max_polygon_hash_foreign'] as $fk) {
            if ($this->hasForeignKey($fk)) {
                DB::statement("ALTER TABLE rippling_reach DROP FOREIGN KEY {$fk}");
            }
        }
        foreach (['rippling_reach_polygon_hash', 'rippling_reach_max_polygon_hash'] as $idx) {
            if ($this->hasIndex($idx)) {
                DB::statement("ALTER TABLE rippling_reach DROP INDEX {$idx}");
            }
        }
        foreach (['polygon_hash', 'max_polygon_hash'] as $col) {
            if (Schema::hasColumn('rippling_reach', $col)) {
                DB::statement("ALTER TABLE rippling_reach DROP COLUMN {$col}, ALGORITHM=INSTANT");
            }
        }

        // 2. has_overflow is GENERATED from overflow_bounds, so it and its
        //    index must go BEFORE the column they derive from, and come back
        //    derived from overflow_cells instead.
        if ($this->hasIndex('rippling_reach_has_overflow')) {
            DB::statement('ALTER TABLE rippling_reach DROP INDEX rippling_reach_has_overflow');
        }
        if (Schema::hasColumn('rippling_reach', 'has_overflow')) {
            DB::statement('ALTER TABLE rippling_reach DROP COLUMN has_overflow, ALGORITHM=INSTANT');
        }
        if (Schema::hasColumn('rippling_reach', 'overflow_bounds')) {
            DB::statement('ALTER TABLE rippling_reach DROP COLUMN overflow_bounds, ALGORITHM=INSTANT');
        }
        if (Schema::hasColumn('rippling_reach', 'overflow_cells')
            && !Schema::hasColumn('rippling_reach', 'has_overflow')) {
            DB::statement(
                'ALTER TABLE rippling_reach
                    ADD COLUMN has_overflow TINYINT(1)
                        GENERATED ALWAYS AS (overflow_cells IS NOT NULL) VIRTUAL'
            );
            DB::statement(
                'ALTER TABLE rippling_reach
                    ADD INDEX rippling_reach_has_overflow (has_overflow, updated_at)'
            );
        }

        // 3. The fat geometry, and the R-tree that drove the browse feed until
        //    the spatial index took that job over.
        if ($this->hasIndex('rippling_reach_polygon')) {
            DB::statement('ALTER TABLE rippling_reach DROP INDEX rippling_reach_polygon');
        }
        foreach (['polygon', 'max_polygon'] as $col) {
            if (Schema::hasColumn('rippling_reach', $col)) {
                DB::statement("ALTER TABLE rippling_reach DROP COLUMN {$col}, ALGORITHM=INSTANT");
            }
        }

        // 4. The shared geometry table, once nothing points at it.
        Schema::dropIfExists('rippling_reach_geom');

        // 5. The rebuild, which is what actually returns the disk. Everything
        // above is metadata only: Percona 8.0.29+ does DROP COLUMN as
        // ALGORITHM=INSTANT, so the dropped columns' bytes stay in every
        // existing row until it is rewritten. Measured on 8.0.43-34: a table
        // of five 200KB blobs reported 1,552KB after an INSTANT drop and 16KB
        // after this. One rebuild rather than pinning INPLACE on each drop,
        // which would rebuild the table six times.
        //
        // LOCK=SHARED because InnoDB REFUSES LOCK=NONE here: "Do not support
        // online operation on table with GIS index" - the index on
        // outer_bound, which this change keeps. Reads continue, writes block.
        // On production that is why the whole file is run node-by-node under
        // RSU, on a node already out of rotation.
        DB::statement('ALTER TABLE rippling_reach FORCE, ALGORITHM=INPLACE, LOCK=SHARED');
    }

    public function down(): void
    {
        // Deliberately not reversible - see the class comment. Re-adding empty
        // columns would be worse than refusing: every reader would find NULL
        // where it expects a reach and quietly decide nobody is covered.
        throw new RuntimeException(
            'Dropping the reach geometry cannot be undone: the cell grids are now the only '
            . 'record of every reach. Restore from a backup taken before the drop instead.'
        );
    }

    private function hasIndex(string $name): bool
    {
        return DB::table('information_schema.statistics')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', 'rippling_reach')
            ->where('index_name', $name)
            ->exists();
    }

    private function hasForeignKey(string $name): bool
    {
        return DB::table('information_schema.referential_constraints')
            ->whereRaw('constraint_schema = DATABASE()')
            ->where('table_name', 'rippling_reach')
            ->where('constraint_name', $name)
            ->exists();
    }
};
