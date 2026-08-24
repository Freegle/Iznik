-- Production idempotent SQL: rippling_reach.max_polygon_cells.
--
-- Compact cell-set form of max_polygon (plans/2026-08-24-rippling-reach-
-- raster-storage.md): a bitmap over the routing server's own 0.0003-degree
-- lattice, replacing an ~11k-vertex WKT tracing with the membership grid
-- every consumer actually wants. Measured on a real reach polygon: 45x
-- smaller, zero disagreement against the polygon-built classification.
--
-- Nullable and unindexed: a deploy ahead of the backfill is a no-op
-- (readers fall back to max_polygon), and nothing ever queries this column
-- in SQL - it is opaque bytes decoded in application code only.
--
-- ADD COLUMN of a nullable non-spatial type is ALGORITHM=INSTANT on
-- Percona 8.0; pinned explicitly so anything that would need a copy
-- refuses loudly instead of running one under TOI.
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND column_name = 'max_polygon_cells');
SET @ddl := IF(@has_col = 0,
    'ALTER TABLE rippling_reach
        ADD COLUMN max_polygon_cells MEDIUMBLOB NULL AFTER max_polygon_hash,
        ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
