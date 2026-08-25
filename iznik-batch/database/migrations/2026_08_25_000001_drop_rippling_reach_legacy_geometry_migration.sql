-- Production idempotent SQL: drop the legacy reach geometry
-- (plans/2026-08-24-rippling-reach-raster-storage.md, Stage 3).
--
-- Removes polygon, max_polygon and overflow_bounds from rippling_reach - the
-- three fat geometry columns the cell grids replace - together with the #1402
-- dedup layer that existed to shrink them (hash columns, their indexes and
-- FKs, and the rippling_reach_geom shared table). has_overflow is regenerated
-- from overflow_cells, same meaning, same index shape.
--
-- ORDER MATTERS AND IS GUARDED:
--   0. REFUSES to run while any live row has no polygon_cells: the drop is
--      only safe after ripple:backfill-reach-cells (and -max-reach-cells,
--      -ring-cells) report complete. This is the disk-reclaiming step - the
--      DROP COLUMNs rebuild the table INPLACE, returning the ~50GB .ibd to
--      the OS - so expect a long-running ALTER; run it node-by-node under
--      RSU like the other rippling_reach index work (an in-place build on
--      this table has previously sat 36 minutes at "checking permissions"
--      under TOI).
--   1. FKs before their columns; the generated has_overflow before the
--      column it derives from.
--   2. rippling_reach_geom last, once nothing references it.
--
-- After this, restart the Go API, spatial servers and batch workers so their
-- memoized era guards (LegacyPolygonReady etc.) re-read the schema.

-- 0. Coverage guard. This MUST stop the script, not merely warn: a live row
--    with no polygon_cells has no reach at all after this file runs, and
--    there is nothing left to rebuild it from.
--
--    The stop is done by selecting from a table that does not exist, whose
--    NAME is the error message. SIGNAL cannot carry a computed message
--    through PREPARE, and a bare SELECT would scroll past unnoticed in a long
--    run; `mysql` reading a file aborts on the first error unless --force, so
--    this genuinely halts before any DDL.
SELECT COUNT(*) AS rows_without_polygon_cells FROM rippling_reach WHERE polygon_cells IS NULL;

SET @uncovered := (SELECT COUNT(*) FROM rippling_reach WHERE polygon_cells IS NULL);
SET @ddl := IF(@uncovered = 0,
    'SELECT 1',
    'SELECT 1 FROM `REFUSING_TO_DROP__rows_still_have_no_polygon_cells__run_ripple_backfill_reach_cells_to_completion_first`');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 1. Dedup FKs, then their indexes and columns (each guarded).
SET @fk := (SELECT COUNT(*) FROM information_schema.referential_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'rippling_reach'
      AND constraint_name = 'rippling_reach_polygon_hash_foreign');
SET @ddl := IF(@fk > 0, 'ALTER TABLE rippling_reach DROP FOREIGN KEY rippling_reach_polygon_hash_foreign', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk := (SELECT COUNT(*) FROM information_schema.referential_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'rippling_reach'
      AND constraint_name = 'rippling_reach_max_polygon_hash_foreign');
SET @ddl := IF(@fk > 0, 'ALTER TABLE rippling_reach DROP FOREIGN KEY rippling_reach_max_polygon_hash_foreign', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND index_name = 'rippling_reach_polygon_hash');
SET @ddl := IF(@idx > 0, 'ALTER TABLE rippling_reach DROP INDEX rippling_reach_polygon_hash', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND index_name = 'rippling_reach_max_polygon_hash');
SET @ddl := IF(@idx > 0, 'ALTER TABLE rippling_reach DROP INDEX rippling_reach_max_polygon_hash', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'polygon_hash');
SET @ddl := IF(@col > 0, 'ALTER TABLE rippling_reach DROP COLUMN polygon_hash', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'max_polygon_hash');
SET @ddl := IF(@col > 0, 'ALTER TABLE rippling_reach DROP COLUMN max_polygon_hash', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. has_overflow: drop the index and the generated column BEFORE the column
--    it derives from, then regenerate both from overflow_cells.
SET @idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND index_name = 'rippling_reach_has_overflow');
SET @ddl := IF(@idx > 0, 'ALTER TABLE rippling_reach DROP INDEX rippling_reach_has_overflow', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @gen := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND column_name = 'has_overflow' AND generation_expression LIKE '%overflow_bounds%');
SET @ddl := IF(@gen > 0, 'ALTER TABLE rippling_reach DROP COLUMN has_overflow', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'overflow_bounds');
SET @ddl := IF(@col > 0, 'ALTER TABLE rippling_reach DROP COLUMN overflow_bounds', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'has_overflow');
SET @ddl := IF(@col = 0,
    'ALTER TABLE rippling_reach ADD COLUMN has_overflow TINYINT(1) GENERATED ALWAYS AS (overflow_cells IS NOT NULL) VIRTUAL, ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND index_name = 'rippling_reach_has_overflow');
SET @ddl := IF(@idx = 0,
    'ALTER TABLE rippling_reach ADD INDEX rippling_reach_has_overflow (has_overflow, updated_at), ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. The fat geometry itself: the polygon R-tree, then the columns. THIS is
--    the table rebuild that reclaims the disk.
SET @idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND index_name = 'rippling_reach_polygon');
SET @ddl := IF(@idx > 0, 'ALTER TABLE rippling_reach DROP INDEX rippling_reach_polygon', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'polygon');
SET @ddl := IF(@col > 0, 'ALTER TABLE rippling_reach DROP COLUMN polygon', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'max_polygon');
SET @ddl := IF(@col > 0, 'ALTER TABLE rippling_reach DROP COLUMN max_polygon', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 4. The shared geometry table, once nothing references it.
DROP TABLE IF EXISTS rippling_reach_geom;
