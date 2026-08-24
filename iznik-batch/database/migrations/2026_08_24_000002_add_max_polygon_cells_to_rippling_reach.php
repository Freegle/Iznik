<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_reach.max_polygon_cells - the compact cell-set form of
 * max_polygon (plans/2026-08-24-rippling-reach-raster-storage.md), stacked
 * on the polygon-hash-dedup change (plans/2026-08-23-rippling-reach-
 * polygon-dedup.md): where that change stopped duplicating a stored
 * geometry, this stops STORING one at all for readers that only ever
 * needed "is this point inside", replacing an ~11k-vertex WKT/WKB tracing
 * with a compact bitmap over the same 0.0003-degree lattice the routing
 * server and the overflow rings already use. Measured on a real production
 * reach polygon 2026-08-24: 1,017,565 bytes of stored geometry -> 22,804
 * bytes of cell set, a 45x reduction, with zero disagreement against the
 * polygon-built classification across 40,401 probe points.
 *
 * max_polygon is the first column converted because it is write-once
 * (MaxReachService populates it exactly once per post, guarded by
 * `WHERE max_polygon IS NULL`) and has few readers, so it proves the whole
 * pattern - write via App\Services\Ripple\CellSetService (which calls the
 * spatial server's rasteriser, the ONE place a polygon becomes its
 * canonical compact form, exactly as GeomShareService centralised hash
 * computation for the dedup change), read via a decode-and-bit-test that
 * never needs a network call - before it is applied to polygon (many more
 * readers, and the two in-place clips) or overflow_bounds (a different
 * shape entirely - JSON of several WKT rings, not one polygon).
 *
 * NULLABLE and unindexed, deliberately:
 *  - nullable so a deploy ahead of the backfill is a no-op, same discipline
 *    as every column this table has grown since go-live: readers fall back
 *    to testing max_polygon (or, once that too is deduped, its hash) when
 *    this is NULL.
 *  - no index because nothing ever queries it in SQL - the bytes are opaque
 *    to MySQL and decoded entirely in application code (PHP or Go), the
 *    same "MySQL is a stateless calculator/store, not the geometry engine"
 *    shift the design doc argues for. An index would only ever protect a
 *    query that will never run.
 *
 * MEDIUMBLOB (16MB) rather than BLOB (64KB): the measured cell set for a
 * median reach is 22.8KB, comfortably inside BLOB, but the largest reaches
 * are unbounded in area and this column must never silently truncate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rippling_reach') || Schema::hasColumn('rippling_reach', 'max_polygon_cells')) {
            return;
        }

        DB::statement(
            'ALTER TABLE rippling_reach
                ADD COLUMN max_polygon_cells MEDIUMBLOB NULL AFTER max_polygon_hash'
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('rippling_reach') || !Schema::hasColumn('rippling_reach', 'max_polygon_cells')) {
            return;
        }

        DB::statement('ALTER TABLE rippling_reach DROP COLUMN max_polygon_cells');
    }
};
