-- Production idempotent SQL: the index MaxReachService's candidate scan needs.
--
-- MaxReachService::populate looks for expanding posts that still lack a max
-- reach, newest first, LIMIT 200. Once every expanding post has one the
-- predicate matches NOTHING, and a LIMIT that is never satisfied cannot stop a
-- scan early, so it walked the whole updated_at index to prove the empty set:
-- 55,990 row lookups in a ~50GB table, 2m09s on an idle db1, once a minute, on
-- the read node. PR #1404 stopped the bleeding with FORCE INDEX on the status
-- index (2m09s -> 5.3s) and deliberately went no further, because the real fix
-- is DDL. This is that DDL.
--
-- Measured on a 55,015-row clone matching production's distribution
-- (8,299 expanding; ZERO rows match the predicate, which is the live state):
--
--   planner's own choice      full updated_at walk, backward scan
--   FORCE INDEX (status)      13,696 rows examined + FILESORT
--   this index                     1 row examined, no filesort
--
-- ORDER MATTERS, IN BOTH SENSES.
--
--  1. Column order (status, has_max_reach, updated_at): equality on the first
--     two, then updated_at already in order, so ORDER BY updated_at DESC
--     LIMIT 200 becomes a backward index scan that stops at 200. Drop
--     updated_at from the index and the filesort comes back.
--
--  2. Statement order below: the generated column must exist before the index
--     that references it.
--
-- RUN THIS UNDER RSU, NODE BY NODE, like the other rippling_reach index work.
-- ADD INDEX on a virtual column is INPLACE and can be LOCK=NONE, but on this
-- cluster an in-place index build on this table has previously sat 36 minutes
-- at "checking permissions" under TOI. Desync the node first.
--
-- Safe to re-run: both statements are guarded and do nothing if already applied.

-- 1. The generated column. VIRTUAL, so it is metadata only and costs no space
--    in the row - the value is computed on read. ALGORITHM=INSTANT pinned so
--    anything that would need a table copy refuses loudly instead of running
--    one. It is defined over max_polygon_cells ALONE on purpose: a generated
--    column pins every column it references, so including max_polygon or
--    max_polygon_hash here would make MySQL refuse to drop them later and
--    would break the legacy-geometry drop.
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND column_name = 'has_max_reach');
SET @has_cells := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND column_name = 'max_polygon_cells');
SET @ddl := IF(@has_col = 0 AND @has_cells > 0,
    'ALTER TABLE rippling_reach
        ADD COLUMN has_max_reach TINYINT(1)
            GENERATED ALWAYS AS (max_polygon_cells IS NOT NULL) VIRTUAL,
        ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. The index itself.
SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND index_name = 'rippling_reach_maxreach_candidates');
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND column_name = 'has_max_reach');
SET @ddl := IF(@has_idx = 0 AND @has_col > 0,
    'ALTER TABLE rippling_reach
        ADD INDEX rippling_reach_maxreach_candidates (status, has_max_reach, updated_at),
        ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verify: this should report the index, and an EXPLAIN of the candidate scan
-- should key on it with a single-figure row estimate and no filesort.
--
--   SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols
--     FROM information_schema.statistics
--    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
--      AND index_name = 'rippling_reach_maxreach_candidates'
--    GROUP BY index_name;
--
--   EXPLAIN SELECT msgid, lat, lng, schedule FROM rippling_reach
--    WHERE has_max_reach = 0 AND schedule IS NOT NULL AND status = 'expanding'
--    ORDER BY updated_at DESC LIMIT 200;
--
-- If that EXPLAIN still names rippling_reach_updated_at, check the query is
-- written as `has_max_reach = 0` and NOT as `max_polygon_cells IS NULL`: MySQL
-- does not substitute the generated column for the expression, so the raw form
-- ignores this index completely. Verified by EXPLAIN, both ways.
