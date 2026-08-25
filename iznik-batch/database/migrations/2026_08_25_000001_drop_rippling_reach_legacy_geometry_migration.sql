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
--   0. REFUSES to run unless ALL THREE mirrors are complete - one guard per
--      column being dropped, because each has its own backfill and checking
--      only one would drop the other two while their mirrors were empty. This is the disk-reclaiming step - the
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
-- memoized era guards (LegacyPolygonReady etc.) re-read the schema. That
-- restart is REQUIRED, not tidy-up: the guards are resolved once per process,
-- so a worker started before the drop still believes the columns exist and
-- will name them until it is restarted. The statements below also drop
-- overflow_bounds BEFORE polygon, so between those two a process holds one
-- guard true and the other false - which every reader tolerates (they are
-- separate guards precisely so that window is coherent) but which is another
-- reason not to leave old processes running across the drop.

-- 0. Coverage guards, ONE PER COLUMN BEING DROPPED. Each of the three has its
--    own mirror and its own backfill, so each is checked separately: checking
--    only polygon_cells would happily drop max_polygon and overflow_bounds
--    while their mirrors were still empty, losing every post's eventual reach
--    and every overflow ring.
--
--    polygon is NOT NULL, so every row must have cells. max_polygon and
--    overflow_bounds are nullable and legitimately absent on most rows, so
--    the test is "has the old value but not the new one".
--
--    These MUST stop the script, not merely warn. The stop is done by
--    selecting from a table that does not exist, whose NAME is the reason:
--    SIGNAL cannot carry a computed message through PREPARE, and a bare
--    SELECT would scroll past unnoticed in a long run. `mysql` reading a file
--    aborts on the first error unless --force, so this genuinely halts before
--    any DDL. Each name is kept under MySQL's 64-character identifier limit
--    on purpose: go over it and the error becomes "Identifier name '...' is
--    too long", which reads like a bug in this file rather than a refusal.
--    Each guard is built through PREPARE so that a SECOND run - when the
--    column has already gone - does not name it and error. Naming a dropped
--    column directly here would break the idempotency the rest of this file
--    is careful to keep.
SET @has_poly := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'polygon');
SET @has_maxpoly := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'max_polygon');
SET @has_rings := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'overflow_bounds');

-- polygon is NOT NULL, so EVERY row must carry cells.
SET @ddl := IF(@has_poly = 0, 'SELECT 0 INTO @bad',
    'SELECT COUNT(*) INTO @bad FROM rippling_reach WHERE polygon_cells IS NULL');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
SELECT @bad AS rows_missing_polygon_cells;
SET @ddl := IF(@bad = 0, 'SELECT 1',
    'SELECT 1 FROM `REFUSING__rows_have_no_polygon_cells__run_the_backfill_first`');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- max_polygon is nullable and legitimately absent on most rows, so the test is
-- "has the old value but not the new one".
SET @ddl := IF(@has_maxpoly = 0, 'SELECT 0 INTO @bad',
    'SELECT COUNT(*) INTO @bad FROM rippling_reach WHERE max_polygon IS NOT NULL AND max_polygon_cells IS NULL');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
SELECT @bad AS rows_missing_max_polygon_cells;
SET @ddl := IF(@bad = 0, 'SELECT 1',
    'SELECT 1 FROM `REFUSING__rows_have_max_polygon_but_no_max_polygon_cells`');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- Same again for the overflow rings.
SET @ddl := IF(@has_rings = 0, 'SELECT 0 INTO @bad',
    'SELECT COUNT(*) INTO @bad FROM rippling_reach WHERE overflow_bounds IS NOT NULL AND overflow_cells IS NULL');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
SELECT @bad AS rows_missing_overflow_cells;
SET @ddl := IF(@bad = 0, 'SELECT 1',
    'SELECT 1 FROM `REFUSING__rows_have_overflow_bounds_but_no_overflow_cells`');
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
SET @ddl := IF(@col > 0, 'ALTER TABLE rippling_reach DROP COLUMN polygon_hash, ALGORITHM=INSTANT', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'max_polygon_hash');
SET @ddl := IF(@col > 0, 'ALTER TABLE rippling_reach DROP COLUMN max_polygon_hash, ALGORITHM=INSTANT', 'SELECT 1');
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
SET @ddl := IF(@gen > 0, 'ALTER TABLE rippling_reach DROP COLUMN has_overflow, ALGORITHM=INSTANT', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'overflow_bounds');
SET @ddl := IF(@col > 0, 'ALTER TABLE rippling_reach DROP COLUMN overflow_bounds, ALGORITHM=INSTANT', 'SELECT 1');
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
SET @ddl := IF(@col > 0, 'ALTER TABLE rippling_reach DROP COLUMN polygon, ALGORITHM=INSTANT', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'max_polygon');
SET @ddl := IF(@col > 0, 'ALTER TABLE rippling_reach DROP COLUMN max_polygon, ALGORITHM=INSTANT', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 4. The shared geometry table, once nothing references it.
DROP TABLE IF EXISTS rippling_reach_geom;

-- 5. THE REBUILD - the step that actually returns the disk to the operating
--    system. Everything above is metadata: the dropped columns' bytes are
--    still sitting in every existing row. This rewrites the table without
--    them.
--
--    It is deliberately ONE rebuild rather than pinning ALGORITHM=INPLACE on
--    each DROP COLUMN above, which would have rebuilt a ~50GB table six
--    times over.
--
--    LOCK=SHARED, NOT LOCK=NONE, AND THAT IS NOT A CHOICE. InnoDB refuses an
--    online rebuild of this table outright:
--
--      ERROR 1846 (0A000): LOCK=NONE is not supported. Reason: Do not support
--      online operation on table with GIS index. Try LOCK=SHARED.
--
--    The GIS index in question is the one on outer_bound, which this change
--    deliberately KEEPS - so there is no version of this rebuild that leaves
--    writes running. Reads continue; writes to rippling_reach block for the
--    duration, on a ~50GB table.
--
--    That is survivable only because of how this is already meant to be run:
--    node by node under RSU, where the node is desynced from the cluster and
--    is not taking traffic anyway. Do NOT run it on a node that is in
--    rotation, and do not reach for LOCK=EXCLUSIVE.
--
--    If blocking writes even on a desynced node is unacceptable, the
--    alternative is a shadow-table copy (pt-online-schema-change / gh-ost),
--    which is more moving parts but never blocks the live table. That is an
--    operator decision, not something this file should make silently.
--
--    Safe to re-run: a second rebuild is wasted work but changes nothing.
--    Verify afterwards with (expect it to have fallen by roughly the column
--    sizes measured in the design doc):
--      SELECT ROUND(data_length/1024/1024/1024, 1) AS gb
--        FROM information_schema.tables
--       WHERE table_schema = DATABASE() AND table_name = 'rippling_reach';
ALTER TABLE rippling_reach FORCE, ALGORITHM=INPLACE, LOCK=SHARED;
