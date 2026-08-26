<?php

use App\Services\Ripple\LegacyGeometryDrop;
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
 * THE ALGORITHM IS NOT A FREE CHOICE HERE, AND GETTING IT WRONG IS SILENT
 * UNTIL IT IS NOT. Percona 8.0.29+ would normally do DROP COLUMN as
 * ALGORITHM=INSTANT, which is metadata only and reclaims nothing - but on THIS
 * table INSTANT is refused outright, twice over: once because virtual generated
 * columns exist (has_overflow, has_max_reach), and again, after those are
 * removed, because of the GIS index on outer_bound that this change keeps.
 *
 * So every legacy column is dropped in ONE combined ALTER with
 * ALGORITHM=INPLACE, which both succeeds and rewrites the table - measured, a
 * 400-row clone with ~200KB in each fat column went 91,808KB -> 1,696KB with no
 * follow-up statement. That is what returns the ~50GB .ibd (plus ~19.4GB a node
 * of rippling_reach_geom) to the operating system, and it is the long one.
 * There is deliberately no separate ALTER ... FORCE: an earlier version had
 * one, believing the drops were metadata-only, and that version could never
 * reach it because it errored on the first drop.
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

        // The whole DDL sequence lives in LegacyGeometryDrop so that a test can
        // run it against a `CREATE TABLE ... LIKE rippling_reach` clone. Until it
        // did, nothing executed these statements: CI never sets
        // RIPPLE_DROP_LEGACY_GEOMETRY and PostDropEraTest fakes the era instead
        // of issuing DDL - which is exactly how a version that died on its first
        // ALTER survived review. See that class for why the order is forced.
        (new LegacyGeometryDrop())->run('rippling_reach');

        // And finally the shared geometry table, once nothing points at it. This
        // stays here rather than in LegacyGeometryDrop because it is not part of
        // the rippling_reach column surgery, and a test running the sequence
        // against a clone must not drop the real shared table.
        Schema::dropIfExists('rippling_reach_geom');
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

};
