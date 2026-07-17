-- Idempotent production SQL for 2026_07_17_000001_create_rippling_reach_bounds_table.php
--
-- Creates rippling_reach_bounds: conservative "sandwich" bounds (small superset +
-- subset polygons) for rippling_reach.polygon, so browse/digest reach queries can
-- cheap-reject / cheap-accept candidates without fetching the ~178 KB exact polygon.
-- A missing row means readers fall back to the full ST_Contains, so this table can
-- be created and backfilled in any order relative to the code deploy — fail-safe.
--
-- Column names are outer_bound/inner_bound (OUTER and INNER are MySQL reserved words).
--
-- Safe to run multiple times: CREATE TABLE IF NOT EXISTS.
--
-- Run on production BEFORE deploying the iznik-batch / iznik-server-go code that
-- writes and reads the bounds. Then run `php artisan ripple:backfill-reach-bounds`
-- off-peak to populate existing reaches.
--
-- See plans/2026-07-17-db3-cpu-reach-sql-prefilter.md.

CREATE TABLE IF NOT EXISTS rippling_reach_bounds (
    msgid       BIGINT UNSIGNED NOT NULL,
    outer_bound GEOMETRY        NOT NULL SRID 3857,
    inner_bound GEOMETRY        NULL SRID 3857,
    PRIMARY KEY (msgid),
    SPATIAL INDEX rippling_reach_bounds_outer (outer_bound),
    CONSTRAINT rippling_reach_bounds_msgid_foreign FOREIGN KEY (msgid)
        REFERENCES rippling_reach (msgid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
