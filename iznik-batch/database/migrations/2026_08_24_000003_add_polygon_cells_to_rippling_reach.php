<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_reach.polygon_cells - the compact cell-set form of the CURRENT
 * reach polygon (plans/2026-08-24-rippling-reach-raster-storage.md),
 * following max_polygon_cells (2026_08_24_000002) once that column had
 * proven the pattern: write via App\Services\Ripple\CellSetService, read
 * via a decode-and-bit-test, fall back to `polygon` (or its content-
 * addressed hash, plans/2026-08-23-rippling-reach-polygon-dedup.md) whenever
 * this is NULL.
 *
 * `polygon` itself is NOT retired by this column. It stays the write path's
 * source of truth - the secondary-group rejection clip (ExpandService::
 * reapplyClips / Go's ClipReachForRejectedGroup) still runs ST_Difference
 * against it, and outer_bound/inner_bound are still derived from a
 * transient WKT, never from this column. polygon_cells is purely an
 * additive fast/compact accelerator for readers that only ever needed
 * "is this point inside", exactly as max_polygon_cells is for max_polygon.
 *
 * NULLABLE and unindexed, same discipline as max_polygon_cells: a deploy
 * ahead of the backfill (ripple:backfill-reach-cells) is a no-op, and
 * nothing ever queries the bytes in SQL.
 *
 * MEDIUMBLOB (16MB) rather than BLOB (64KB), matching max_polygon_cells:
 * the largest reaches are unbounded in area and this column must never
 * silently truncate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rippling_reach') || Schema::hasColumn('rippling_reach', 'polygon_cells')) {
            return;
        }

        DB::statement(
            'ALTER TABLE rippling_reach
                ADD COLUMN polygon_cells MEDIUMBLOB NULL AFTER polygon_hash'
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('rippling_reach') || !Schema::hasColumn('rippling_reach', 'polygon_cells')) {
            return;
        }

        DB::statement('ALTER TABLE rippling_reach DROP COLUMN polygon_cells');
    }
};
