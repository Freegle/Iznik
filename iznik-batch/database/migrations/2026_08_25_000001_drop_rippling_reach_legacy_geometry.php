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
 * WHY THIS IS SAFE TO RUN, AND WHEN. Nothing reads the legacy columns any
 * more (the two-era guards and their legacy branches were deleted once
 * production dropped the columns). So the ONLY precondition is that every
 * live row carries polygon_cells - which the guard below enforces rather
 * than trusts, because a row without cells has no reach at all afterwards.
 *
 * ON BY DEFAULT since the legacy branches were deleted: the code no longer
 * reads the legacy columns anywhere, so a schema that keeps them is testing
 * nothing. Production ran the companion .sql files node by node under RSU,
 * after the backfills completed and ripple:verify-cells-parity was read;
 * dev and CI drop here, on migrate. Set RIPPLE_DROP_LEGACY_GEOMETRY=0 to
 * keep the columns on a database that still needs the transition era - that
 * requires a checkout from before the legacy-branch removal, since this code
 * cannot read them.
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

        // Opt-out - see the class comment. Default on: the legacy branches are
        // gone, so keeping the columns tests nothing this code can read.
        if (!filter_var(env('RIPPLE_DROP_LEGACY_GEOMETRY', true), FILTER_VALIDATE_BOOLEAN)) {
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
                    . "Run {$command} to completion first (removed with the legacy branches - "
                    . 'run it from a checkout predating the removal, or set RIPPLE_DROP_LEGACY_GEOMETRY=0).'
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
