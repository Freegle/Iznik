-- Production idempotent SQL: rippling_reach.has_overflow + index.
--
-- WHY. Overflow rings live as JSON in overflow_bounds, and the read surfaces ask
-- "does any ring admit this viewer" with JSON_EXTRACT over that column - which no
-- index can serve. On 2026-08-21 that test was ORed into the spatial containment
-- predicate, which removed the SPATIAL index:
--
--   before:  key=rippling_reach_polygon   rows=1
--   after:   key=NULL                     rows=62,534   (full scan of ~17GB)
--
-- Standalone the arm measured 49s. Under real concurrency it produced 250 running
-- threads and load 158 on the read node, 209 threads on the write node, and
-- multi-second API calls. apiv2 was rolled back to 8c5551f41 to recover.
--
-- 4,213 of 55,195 rows carry a ring (7.6%). This index is how the (now separate)
-- ring query finds them without scanning.
--
-- HOW TO RUN. Statement 1 is INSTANT and safe under TOI. Statement 2 builds an
-- index over a ~17GB table, so run it NODE BY NODE under RSU rather than letting
-- TOI serialise cluster-wide writes for the build (~1 minute per node):
--
--   SET SESSION wsrep_OSU_method = 'RSU';
--   -- statement 2 on this node
--   SET SESSION wsrep_OSU_method = 'TOI';
--
-- Avoid db1's 04:00-04:17 backup window.

-- 1. Virtual column isolating the ring rows. VIRTUAL: no row rewrite.
SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND column_name = 'has_overflow');
SET @ddl := IF(@col = 0,
    'ALTER TABLE rippling_reach ADD COLUMN has_overflow TINYINT(1) GENERATED ALWAYS AS (overflow_bounds IS NOT NULL) VIRTUAL, ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. The index. updated_at as the second column so the same index also serves
--    "recent ring rows", which is how the hot queries filter.
SET @idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND index_name = 'rippling_reach_has_overflow');
SET @ddl := IF(@idx = 0,
    'ALTER TABLE rippling_reach ADD INDEX rippling_reach_has_overflow (has_overflow, updated_at), ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- AFTERWARDS. Give the optimizer figures, then confirm the ring arm is indexed.
-- This is a check to RUN, not a result to assume - if it still says type=ALL the
-- index is not being used and the change bought nothing:
--
--   ANALYZE TABLE rippling_reach;
--   EXPLAIN SELECT COUNT(*) FROM rippling_reach rr
--    WHERE rr.has_overflow = 1 AND rr.status != 'held';
--
-- Wanted: key=rippling_reach_has_overflow and rows in the low thousands, not 62k.
