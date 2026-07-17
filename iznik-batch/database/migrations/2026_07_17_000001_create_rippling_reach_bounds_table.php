<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_reach_bounds — conservative "sandwich" bounds for rippling_reach.polygon.
 *
 * The exact reach polygons are grid-fill isochrones averaging ~11k vertices / 178 KB,
 * and the browse/digest queries pay a full BLOB fetch + point-in-polygon test per
 * R-tree candidate. This sibling table stores two SMALL derived polygons per reach so
 * those queries can cheap-reject / cheap-accept almost all candidates and only touch
 * the exact polygon for the thin boundary band:
 *
 *   outer_bound — superset of polygon (ST_Buffer(ST_Simplify(polygon, tol), +tol),
 *                 fallback ST_Envelope(polygon)). Viewer outside ⇒ definitely out.
 *   inner_bound — subset of polygon (ST_Buffer(ST_Simplify(polygon, tol), -tol),
 *                 fallback NULL, which just disables cheap-accept). Viewer inside ⇒
 *                 definitely in.
 *
 * The exact polygon stays authoritative: bounds are verified at write time
 * (ST_Contains(outer_bound, polygon) AND ST_Contains(polygon, inner_bound)) and fall
 * back to envelope/NULL on any failure. A missing row means readers fall back to the
 * full ST_Contains — fail-safe in every rollout state.
 *
 * Sibling table rather than ALTER on rippling_reach: avoids table-rebuild DDL on the
 * ~10 GB hot table, and SPATIAL indexes need NOT NULL geometry which a progressive
 * backfill could not satisfy in place.
 *
 * Column names are outer_bound/inner_bound (not the design doc's outer/inner) because
 * OUTER and INNER are MySQL reserved words.
 *
 * See plans/2026-07-17-db3-cpu-reach-sql-prefilter.md.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('rippling_reach_bounds')) {
            return;
        }

        Schema::create('rippling_reach_bounds', function ($t) {
            $t->unsignedBigInteger('msgid')->primary();
        });

        // Geometry columns + spatial index via raw SQL: SRID 3857 to match
        // rippling_reach.polygon (Freegle stores lng/lat degrees under an SRID-3857
        // label). Spatial indexes require NOT NULL, hence outer_bound NOT NULL (it
        // always exists — worst case ST_Envelope) while inner_bound is nullable.
        DB::statement(
            'ALTER TABLE rippling_reach_bounds
                ADD COLUMN outer_bound GEOMETRY NOT NULL SRID 3857 AFTER msgid,
                ADD COLUMN inner_bound GEOMETRY NULL SRID 3857 AFTER outer_bound,
                ADD SPATIAL INDEX rippling_reach_bounds_outer (outer_bound)'
        );

        // Bounds die with their reach row (e.g. the Go API's wholly-within DELETE in
        // ClipReachForRejectedGroup, or message hard-delete cascading through
        // rippling_reach).
        DB::statement(
            'ALTER TABLE rippling_reach_bounds
                ADD CONSTRAINT rippling_reach_bounds_msgid_foreign
                FOREIGN KEY (msgid) REFERENCES rippling_reach (msgid) ON DELETE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('rippling_reach_bounds');
    }
};
