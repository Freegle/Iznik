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

-- ============================================================================
-- THREE operations against rippling_reach, down from fourteen. Each one was a
-- separate pass to run node by node under RSU on a ~50GB table.
--
-- Only STATEMENT 2 does real work. 1 and 3 touch metadata only.
--
-- THREE AND NOT ONE. Fewer statements is not automatically less work, and here
-- it is not even permitted: a virtual generated column may not be added or
-- dropped in the same ALTER as anything else ("INPLACE ADD or DROP of virtual
-- columns cannot be combined with other ALTER TABLE actions"), and has_overflow
-- additionally has to go before overflow_bounds, which it derives from. Pushing
-- further would also backfire - measured on the additive side, folding an
-- INSTANT column add into an index build turned a metadata-only change into a
-- full table rebuild that could not even run LOCK=NONE.
--
-- ALGORITHM=INSTANT IS NOT AVAILABLE ON THIS TABLE AT ALL, twice over: while
-- any virtual generated column exists InnoDB refuses to drop any column, and
-- with those removed it refuses again because of the GIS index on outer_bound
-- that this change deliberately KEEPS. An earlier version of this file pinned
-- INSTANT on each drop and died on the very first one, leaving every legacy
-- column in place; it read as verified because it had only been exercised
-- against a simplified table carrying neither of those things.
--
-- TWO THINGS ARE NOW IMPLICIT, verified rather than assumed:
--   * single-column indexes. Dropping a column drops any index over just that
--     column, so rippling_reach_polygon (the R-tree), _polygon_hash and
--     _max_polygon_hash need no statement of their own.
--   * the foreign keys, which ride along inside statement 2.
--     rippling_reach_shadow_msgid_foreign is NOT named and must survive.
--
-- Safe to re-run: all three are guarded, and if no legacy column remains the
-- whole file does nothing. That guard is load-bearing - without it a repeat
-- would take both generated columns off and put them back, rebuilding two
-- indexes for no reason.
-- ============================================================================

-- Is there any legacy column left? If not, everything below is a no-op.
SET @legacy := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND column_name IN ('polygon','max_polygon','overflow_bounds','polygon_hash','max_polygon_hash'));

-- ============================================================================
-- 1 of 3: both generated columns and their indexes. Metadata only - a virtual
--         column stores nothing, so this is fast regardless of table size.
--
--         The index goes before its column in each pair, and NOT because MySQL
--         would refuse otherwise. It would not: dropping a generated column
--         SILENTLY REWRITES any index naming it, so
--         (status, has_max_reach, updated_at) becomes (status, updated_at)
--         under the same name - and the guarded re-create in statement 3 checks
--         the NAME, so it would decline to fix it. Confirmed on 8.0.43-34.
-- ============================================================================
SET @parts := CONCAT_WS(', ',
    (SELECT IF(COUNT(*) > 0, 'DROP INDEX rippling_reach_has_overflow', NULL)
       FROM information_schema.statistics WHERE table_schema = DATABASE()
        AND table_name = 'rippling_reach' AND index_name = 'rippling_reach_has_overflow'),
    (SELECT IF(COUNT(*) > 0, 'DROP COLUMN has_overflow', NULL)
       FROM information_schema.columns WHERE table_schema = DATABASE()
        AND table_name = 'rippling_reach' AND column_name = 'has_overflow'),
    (SELECT IF(COUNT(*) > 0, 'DROP INDEX rippling_reach_maxreach_candidates', NULL)
       FROM information_schema.statistics WHERE table_schema = DATABASE()
        AND table_name = 'rippling_reach' AND index_name = 'rippling_reach_maxreach_candidates'),
    (SELECT IF(COUNT(*) > 0, 'DROP COLUMN has_max_reach', NULL)
       FROM information_schema.columns WHERE table_schema = DATABASE()
        AND table_name = 'rippling_reach' AND column_name = 'has_max_reach')
);
SET @ddl := IF(@legacy = 0 OR @parts IS NULL OR @parts = '', 'SELECT 1',
    CONCAT('ALTER TABLE rippling_reach ', @parts));
SELECT @ddl AS statement_1_of_3;
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ============================================================================
-- 2 of 3: THE ONE THAT DOES THE WORK. Both dedup foreign keys and all five
--         legacy columns, in a single ALGORITHM=INPLACE alter - which succeeds
--         where INSTANT cannot, and rewrites the table, which is what actually
--         returns the disk. Measured: a clone of 400 rows carrying ~200KB in
--         each fat column went 91,808KB -> 1,696KB in this one statement, with
--         TOTAL_ROW_VERSIONS staying at 0. One rebuild instead of five.
--
--         LOCK=SHARED, not LOCK=NONE, and not a choice: InnoDB refuses an
--         online rebuild of a table carrying a GIS index, and outer_bound is
--         one. Reads continue; writes to rippling_reach block for the duration.
--         Survivable only because this runs on a node already desynced and out
--         of rotation. Do NOT run it on a node in rotation, and do not reach
--         for LOCK=EXCLUSIVE. If blocking writes even on a desynced node is
--         unacceptable, use pt-online-schema-change instead - an operator
--         decision, not one this file should make silently.
-- ============================================================================
SET @parts := CONCAT_WS(', ',
    (SELECT IF(COUNT(*) > 0, 'DROP FOREIGN KEY rippling_reach_polygon_hash_foreign', NULL)
       FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE()
        AND table_name = 'rippling_reach' AND constraint_name = 'rippling_reach_polygon_hash_foreign'),
    (SELECT IF(COUNT(*) > 0, 'DROP FOREIGN KEY rippling_reach_max_polygon_hash_foreign', NULL)
       FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE()
        AND table_name = 'rippling_reach' AND constraint_name = 'rippling_reach_max_polygon_hash_foreign'),
    (SELECT IF(COUNT(*) > 0, 'DROP COLUMN polygon_hash', NULL)
       FROM information_schema.columns WHERE table_schema = DATABASE()
        AND table_name = 'rippling_reach' AND column_name = 'polygon_hash'),
    (SELECT IF(COUNT(*) > 0, 'DROP COLUMN max_polygon_hash', NULL)
       FROM information_schema.columns WHERE table_schema = DATABASE()
        AND table_name = 'rippling_reach' AND column_name = 'max_polygon_hash'),
    (SELECT IF(COUNT(*) > 0, 'DROP COLUMN overflow_bounds', NULL)
       FROM information_schema.columns WHERE table_schema = DATABASE()
        AND table_name = 'rippling_reach' AND column_name = 'overflow_bounds'),
    (SELECT IF(COUNT(*) > 0, 'DROP COLUMN polygon', NULL)
       FROM information_schema.columns WHERE table_schema = DATABASE()
        AND table_name = 'rippling_reach' AND column_name = 'polygon'),
    (SELECT IF(COUNT(*) > 0, 'DROP COLUMN max_polygon', NULL)
       FROM information_schema.columns WHERE table_schema = DATABASE()
        AND table_name = 'rippling_reach' AND column_name = 'max_polygon')
);
SET @ddl := IF(@parts IS NULL OR @parts = '', 'SELECT 1',
    CONCAT('ALTER TABLE rippling_reach ', @parts, ', ALGORITHM=INPLACE, LOCK=SHARED'));
SELECT @ddl AS statement_2_of_3;
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ============================================================================
-- 3 of 3: both generated columns and both indexes, restored over the surviving
--         cell columns. has_overflow changes meaning here (overflow_bounds ->
--         overflow_cells); has_max_reach does not, but had to come off to let
--         statement 2 through. Measured: no rebuild, TOTAL_ROW_VERSIONS
--         unchanged.
--
--         Restoring has_max_reach matters rather than being tidiness: without
--         it MaxReachService falls back to its FORCE INDEX form, which is
--         13,696 rows and a filesort instead of one row.
-- ============================================================================
SET @parts := CONCAT_WS(', ',
    (SELECT IF(COUNT(*) = 0,
        'ADD COLUMN has_overflow TINYINT(1) GENERATED ALWAYS AS (overflow_cells IS NOT NULL) VIRTUAL', NULL)
       FROM information_schema.columns WHERE table_schema = DATABASE()
        AND table_name = 'rippling_reach' AND column_name = 'has_overflow'),
    (SELECT IF(COUNT(*) = 0,
        'ADD COLUMN has_max_reach TINYINT(1) GENERATED ALWAYS AS (max_polygon_cells IS NOT NULL) VIRTUAL', NULL)
       FROM information_schema.columns WHERE table_schema = DATABASE()
        AND table_name = 'rippling_reach' AND column_name = 'has_max_reach'),
    (SELECT IF(COUNT(*) = 0, 'ADD INDEX rippling_reach_has_overflow (has_overflow, updated_at)', NULL)
       FROM information_schema.statistics WHERE table_schema = DATABASE()
        AND table_name = 'rippling_reach' AND index_name = 'rippling_reach_has_overflow'),
    (SELECT IF(COUNT(*) = 0,
        'ADD INDEX rippling_reach_maxreach_candidates (status, has_max_reach, updated_at)', NULL)
       FROM information_schema.statistics WHERE table_schema = DATABASE()
        AND table_name = 'rippling_reach' AND index_name = 'rippling_reach_maxreach_candidates')
);
SET @ddl := IF(@parts IS NULL OR @parts = '', 'SELECT 1',
    CONCAT('ALTER TABLE rippling_reach ', @parts));
SELECT @ddl AS statement_3_of_3;
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- The shared geometry table, once nothing references it. Not an ALTER, and the
-- cheapest part of the whole exercise: it takes ~21.5GB a node with it.
DROP TABLE IF EXISTS rippling_reach_geom;

-- ============================================================================
-- VERIFY. Three checks, and the second is the one that cannot be fooled.
--
--   -- (a) the space came back
--   SELECT ROUND(data_length/1024/1024/1024, 1) AS gb
--     FROM information_schema.tables
--    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach';
--
--   -- (b) nothing instantly-dropped is still lurking in the rows. MUST be 0.
--   SELECT TOTAL_ROW_VERSIONS FROM information_schema.INNODB_TABLES
--    WHERE NAME = CONCAT(DATABASE(), '/rippling_reach');
--
--   -- (c) the columns are genuinely gone, and the right indexes survive
--   SELECT COUNT(*) AS legacy_left FROM information_schema.columns
--    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
--      AND column_name IN ('polygon','max_polygon','overflow_bounds',
--                          'polygon_hash','max_polygon_hash');
--   SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols
--     FROM information_schema.statistics
--    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
--    GROUP BY index_name ORDER BY index_name;
--   -- rippling_reach_outer MUST still be there (outer_bound), and
--   -- rippling_reach_maxreach_candidates MUST read status,has_max_reach,updated_at.
--
-- (a) alone is not enough: data_length can look plausible while dropped bytes
-- remain, because an INSTANT change edits only metadata. (b) counts them
-- directly. It also matters as a BUDGET - InnoDB allows 64 row versions per
-- table then refuses all further INSTANT DDL, and the rebuild in statement 2
-- returns it to 0, which is what keeps that fast path available later.
-- ============================================================================
