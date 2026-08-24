<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_reach.overflow_cells - the overflow rings in compact cell-set form
 * (plans/2026-08-24-rippling-reach-raster-storage.md), the third and largest
 * column converted after max_polygon_cells and polygon_cells.
 *
 * The rings are the worst case in the table and by some distance. Measured
 * 2026-08-23 (ripple:shrink-overflow-bounds' own docblock): overflow_bounds
 * was HALF the table at 860KB a row, and its rings average 37,000 vertices.
 * They are traced from a routing-server raster, so every vertex already sits
 * on the exact 0.0003-degree lattice a cell set uses - which makes a cell set
 * a recovery of the ring's own source grid rather than a new approximation of
 * it. The read path then downsamples that tracing into a ~130m coarse raster
 * anyway (iznik-spatial-go's ringRasterDim=192), so the stored precision is
 * never used by the surface that reads it most.
 *
 * SHAPE: the same nesting and the same JSON paths as overflow_bounds, with
 * each ring's WKT replaced by base64-encoded cell-set bytes:
 *
 *   {"rural": {"dense": "<base64>", ...}, "cluster": {"w1": "<base64>", ...}}
 *
 * Same paths so iznik-spatial-go asks for a lane with the identical
 * JSON_EXTRACT it already uses, and no consumer needs a second lane table.
 * Base64 rather than raw bytes because this is a JSON column, whose value
 * must be valid UTF-8; the ~33% inflation is nothing against the reduction.
 * The non-geometry members of overflow_bounds (fairness_budget_min, bbox)
 * are NOT mirrored here - they are scalars, they are read from
 * overflow_bounds, and duplicating them would be two places to drift.
 *
 * overflow_bounds STAYS, and is still the authority:
 *  - the map overlay (iznik-server-go's message/reach.go) draws the rings and
 *    genuinely needs the vector, not a grid;
 *  - "which lanes does this post carry" (ReachQueryService) is a
 *    JSON_CONTAINS_PATH test against it;
 *  - has_overflow is GENERATED from `overflow_bounds IS NOT NULL` and indexed,
 *    and both the spatial-go ring load and its delta hang off that index.
 * So this column is additive, exactly as the other two are: it removes an
 * expensive PARSE from the read-index build, not the geometry from the table.
 *
 * Nullable, and NULL is the normal state for any row written before the
 * backfill (ripple:backfill-ring-cells): spatial-go falls back to parsing the
 * WKT for any lane whose cells are absent, per lane, so a partly-converted
 * table is a valid state rather than a migration window.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rippling_reach') || Schema::hasColumn('rippling_reach', 'overflow_cells')) {
            return;
        }

        DB::statement(
            'ALTER TABLE rippling_reach
                ADD COLUMN overflow_cells JSON NULL AFTER overflow_bounds'
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('rippling_reach') || !Schema::hasColumn('rippling_reach', 'overflow_cells')) {
            return;
        }

        DB::statement('ALTER TABLE rippling_reach DROP COLUMN overflow_cells');
    }
};
