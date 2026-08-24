-- Production idempotent SQL: rippling_reach.polygon_cells.
--
-- Compact cell-set form of the CURRENT reach polygon (plans/2026-08-24-
-- rippling-reach-raster-storage.md), following max_polygon_cells. `polygon`
-- stays the write path's source of truth (the rejection clip and the
-- outer_bound/inner_bound derivation both still use it); this column is a
-- purely additive fast/compact accelerator for point-in-reach reads.
--
-- Nullable and unindexed: a deploy ahead of the backfill is a no-op
-- (readers fall back to polygon/polygon_hash), and nothing ever queries
-- this column in SQL - it is opaque bytes decoded in application code only.
--
-- ADD COLUMN of a nullable non-spatial type is ALGORITHM=INSTANT on
-- Percona 8.0; pinned explicitly so anything that would need a copy
-- refuses loudly instead of running one under TOI.
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND column_name = 'polygon_cells');
SET @ddl := IF(@has_col = 0,
    'ALTER TABLE rippling_reach
        ADD COLUMN polygon_cells MEDIUMBLOB NULL AFTER polygon_hash,
        ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
