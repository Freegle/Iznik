<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sandwich-bounds columns on rippling_reach itself
 * (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md).
 *
 * The exact reach polygons are grid-fill isochrones averaging ~11k vertices / 178 KB;
 * the reach containment queries consult two SMALL derived polygons stored alongside:
 *
 *   outer_bound ⊇ polygon (NOT NULL, spatially indexed) — the R-tree the hot browse
 *       queries DRIVE. Sentinel ladder: real derived bound > ST_Envelope(polygon)
 *       (derivation failed — MBR still finds the row, the exact polygon decides) >
 *       degenerate POINT (completed posts ONLY — pruned from the index entirely).
 *   inner_bound ⊆ polygon, or NULL (no cheap accept).
 *
 * Same-row storage (not a sibling table) so every polygon write can set the bounds in
 * the SAME statement — no cross-table timing window can exist. Off-page BLOB storage
 * (Dynamic row format) keeps bounds/polygon caching independent of layout.
 *
 * THIS MIGRATION IS THE DEV/CI PATH: dev tables are small, so the NOT NULL conversion
 * and index build here are trivial. Production must NOT run a plain ALTER on the ~10 GB
 * hot table (Galera TOI would stall writes cluster-wide for the rebuild) — it uses the
 * shadow-copy command `ripple:migrate-reach-bounds-schema` (pt-osc-style: quiesce the
 * ripple crons, chunked copy deriving bounds per row, updated_at delta catch-up, atomic
 * RENAME swap). See the paired _migration.sql for the operator runbook.
 */
return new class extends Migration {
    private const TOLERANCE = 0.002;

    public function up(): void
    {
        if (Schema::hasColumn('rippling_reach', 'outer_bound')) {
            return;
        }

        // 1) Nullable adds. Appended LAST (no AFTER clause) to match the column order
        //    the production shadow-copy produces, so dev and prod schemas stay identical.
        DB::statement(
            'ALTER TABLE rippling_reach
                ADD COLUMN outer_bound GEOMETRY SRID 3857 NULL,
                ADD COLUMN inner_bound GEOMETRY SRID 3857 NULL'
        );

        // 2) Backfill existing rows from the stored polygon. Fresh installs/CI have an
        //    empty table so this is a no-op; a dev DB with rows gets real bounds, falling
        //    back to envelope-only if any stored geometry makes the derivation throw.
        try {
            DB::statement(
                'UPDATE rippling_reach
                    SET outer_bound = ST_Buffer(ST_Simplify(polygon, ' . self::TOLERANCE . '), ' . self::TOLERANCE . '),
                        inner_bound = ST_Buffer(ST_Simplify(polygon, ' . self::TOLERANCE . '), -' . self::TOLERANCE . ')'
            );
        } catch (\Throwable) {
            DB::statement('UPDATE rippling_reach SET outer_bound = ST_Envelope(polygon), inner_bound = NULL');
        }

        // 3) NOT NULL + the R-tree the browse queries drive. Spatial indexes require
        //    NOT NULL, which is why the sentinel ladder guarantees a value for every row.
        DB::statement('ALTER TABLE rippling_reach MODIFY outer_bound GEOMETRY NOT NULL SRID 3857');
        DB::statement('ALTER TABLE rippling_reach ADD SPATIAL INDEX rippling_reach_outer (outer_bound)');
    }

    public function down(): void
    {
        if (!Schema::hasColumn('rippling_reach', 'outer_bound')) {
            return;
        }
        DB::statement('ALTER TABLE rippling_reach DROP INDEX rippling_reach_outer');
        DB::statement('ALTER TABLE rippling_reach DROP COLUMN outer_bound, DROP COLUMN inner_bound');
    }
};
