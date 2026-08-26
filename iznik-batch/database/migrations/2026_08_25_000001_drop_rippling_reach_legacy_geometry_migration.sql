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

-- 2. THE VIRTUAL GENERATED COLUMNS MUST GO FIRST, AND THAT IS NOT OPTIONAL.
--
--    While ANY virtual generated column exists on this table, InnoDB refuses to
--    drop ANY column by INSTANT or INPLACE - even a column no generated column
--    references:
--
--      ERROR 1846 (0A000): ALGORITHM=INSTANT is not supported. Reason: INPLACE
--      ADD or DROP of virtual columns cannot be combined with other ALTER TABLE
--      actions. Try ALGORITHM=COPY/INPLACE.
--
--    An earlier version of this file pinned ALGORITHM=INSTANT on each DROP
--    COLUMN and failed on the very first one, leaving every legacy column in
--    place. It passed review because it had only been exercised against a
--    simplified table that carried neither the generated columns nor the GIS
--    index. Run against a real CREATE TABLE ... LIKE rippling_reach clone it
--    stops at the first drop, every time.
--
--    (INSTANT is doubly unavailable here anyway: with the generated columns
--    removed the same statement is refused a second time, "Do not support
--    online operation on table with GIS index" - that being the outer_bound
--    R-tree this change deliberately KEEPS.)
--
--    So both generated columns come off first, each in its own statement, and
--    are regenerated afterwards. has_overflow additionally HAS to go before
--    overflow_bounds, which it derives from, or the drop is refused with
--    "Column 'overflow_bounds' has a generated column dependency".
-- Is there any legacy column left at all? If not, everything from here is a
-- no-op, and that matters rather than being tidiness: without this check a
-- re-run would take both generated columns off and put them straight back,
-- rebuilding two indexes on a ~50GB table to achieve nothing. An RSU pass is
-- exactly the sort of thing an operator repeats.
SET @legacy := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND column_name IN ('polygon','max_polygon','overflow_bounds','polygon_hash','max_polygon_hash'));

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND index_name = 'rippling_reach_maxreach_candidates');
SET @ddl := IF(@idx > 0 AND @legacy > 0, 'ALTER TABLE rippling_reach DROP INDEX rippling_reach_maxreach_candidates', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'has_max_reach');
SET @ddl := IF(@col > 0 AND @legacy > 0, 'ALTER TABLE rippling_reach DROP COLUMN has_max_reach', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND index_name = 'rippling_reach_has_overflow');
SET @ddl := IF(@idx > 0 AND @legacy > 0, 'ALTER TABLE rippling_reach DROP INDEX rippling_reach_has_overflow', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'has_overflow');
SET @ddl := IF(@col > 0 AND @legacy > 0, 'ALTER TABLE rippling_reach DROP COLUMN has_overflow', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. The polygon R-tree, before the column it indexes.
SET @idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND index_name = 'rippling_reach_polygon');
SET @ddl := IF(@idx > 0 AND @legacy > 0, 'ALTER TABLE rippling_reach DROP INDEX rippling_reach_polygon', 'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 4. ONE COMBINED DROP, AND IT IS ALSO THE REBUILD.
--
--    Every legacy column goes in a single ALTER, ALGORITHM=INPLACE. That is one
--    rebuild of a ~50GB table rather than five, and - measured - it returns the
--    disk on its own: a clone holding 400 rows of ~200KB in each fat column went
--    from 90,784KB to 192KB with no further statement, and
--    INNODB_TABLES.TOTAL_ROW_VERSIONS stayed at 0 throughout.
--
--    So there is NO separate ALTER TABLE ... FORCE at the end any more. The
--    earlier file had one because it believed the drops above were INSTANT and
--    therefore metadata-only. They cannot be INSTANT on this table at all, and
--    an INPLACE drop rewrites the table by definition, so the extra rebuild was
--    both unreachable (the file errored before it) and redundant.
--
--    LOCK=SHARED, NOT LOCK=NONE, and that is not a choice either: InnoDB
--    refuses an online rebuild of a table carrying a GIS index, and outer_bound
--    is one. Reads continue; writes to rippling_reach block for the duration.
--    That is survivable only because this runs node by node under RSU, on a
--    node desynced and out of rotation. Do NOT run it on a node in rotation,
--    and do not reach for LOCK=EXCLUSIVE. If blocking writes even on a desynced
--    node is unacceptable, use a shadow-table copy (pt-online-schema-change /
--    gh-ost) instead; that is an operator decision, not one this file should
--    make silently.
--
--    The statement is assembled from whichever columns are still present, so a
--    partially-applied run finishes cleanly and a fully-applied one is a no-op.
SET @drops := CONCAT_WS(', ',
    (SELECT IF(COUNT(*) > 0, 'DROP COLUMN polygon_hash', NULL) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'polygon_hash'),
    (SELECT IF(COUNT(*) > 0, 'DROP COLUMN max_polygon_hash', NULL) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'max_polygon_hash'),
    (SELECT IF(COUNT(*) > 0, 'DROP COLUMN overflow_bounds', NULL) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'overflow_bounds'),
    (SELECT IF(COUNT(*) > 0, 'DROP COLUMN polygon', NULL) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'polygon'),
    (SELECT IF(COUNT(*) > 0, 'DROP COLUMN max_polygon', NULL) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'max_polygon')
);
SET @ddl := IF(@drops IS NULL OR @drops = '', 'SELECT 1',
    CONCAT('ALTER TABLE rippling_reach ', @drops, ', ALGORITHM=INPLACE, LOCK=SHARED'));
SELECT @ddl AS the_rebuild;
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 5. Regenerate the two virtual columns and their indexes, now over the
--    surviving cell columns. Each ADD is its own statement for the same reason
--    the drops were: a virtual column cannot share an ALTER with anything else.
SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'has_overflow');
SET @cells := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'overflow_cells');
SET @ddl := IF(@col = 0 AND @cells > 0,
    'ALTER TABLE rippling_reach ADD COLUMN has_overflow TINYINT(1) GENERATED ALWAYS AS (overflow_cells IS NOT NULL) VIRTUAL',
    'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND index_name = 'rippling_reach_has_overflow');
SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'has_overflow');
SET @ddl := IF(@idx = 0 AND @col > 0,
    'ALTER TABLE rippling_reach ADD INDEX rippling_reach_has_overflow (has_overflow, updated_at), ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- has_max_reach is unchanged in meaning - it derives from max_polygon_cells,
-- which survives - but it had to come off to let the drop through, so it is put
-- back here. Restoring it matters: without it MaxReachService falls back to the
-- FORCE INDEX form, which is 13,696 rows and a filesort instead of one row.
SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'has_max_reach');
SET @cells := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'max_polygon_cells');
SET @ddl := IF(@col = 0 AND @cells > 0,
    'ALTER TABLE rippling_reach ADD COLUMN has_max_reach TINYINT(1) GENERATED ALWAYS AS (max_polygon_cells IS NOT NULL) VIRTUAL',
    'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND index_name = 'rippling_reach_maxreach_candidates');
SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'has_max_reach');
SET @ddl := IF(@idx = 0 AND @col > 0,
    'ALTER TABLE rippling_reach ADD INDEX rippling_reach_maxreach_candidates (status, has_max_reach, updated_at), ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 6. The shared geometry table, once nothing references it.
DROP TABLE IF EXISTS rippling_reach_geom;

-- VERIFYING IT ACTUALLY HAPPENED. Two checks, and the second cannot be fooled:
--
--   -- (a) the space came back
--   SELECT ROUND(data_length/1024/1024/1024, 1) AS gb
--     FROM information_schema.tables
--    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach';
--
--   -- (b) no instantly-dropped column is still lurking in the rows. MUST be 0.
--   SELECT TOTAL_ROW_VERSIONS FROM information_schema.INNODB_TABLES
--    WHERE NAME = CONCAT(DATABASE(), '/rippling_reach');
--
-- Check (a) alone is not enough: data_length can look plausible while dropped
-- bytes are still present, because an INSTANT change edits only metadata. Check
-- (b) counts them directly. It reads 3 on this table in dev today, from earlier
-- INSTANT column additions, so it is a live counter rather than a formality -
-- and it is also a budget, since InnoDB permits 64 row versions per table and
-- then refuses all further INSTANT DDL. The INPLACE rebuild above returns it
-- to 0, which is what keeps that fast path available for later changes.
--
--   -- (c) and the columns are genuinely gone
--   SELECT COUNT(*) FROM information_schema.columns
--    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
--      AND column_name IN ('polygon','max_polygon','overflow_bounds',
--                          'polygon_hash','max_polygon_hash');
