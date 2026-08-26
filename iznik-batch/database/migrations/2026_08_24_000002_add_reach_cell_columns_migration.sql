-- Production idempotent SQL: everything rippling_reach GAINS for cell storage.
--
-- TWO operations, replacing four separate migrations (one per cells column plus
-- one for the maxreach candidate index). Each of those was a distinct schema
-- change to run node by node under RSU on a ~50GB table.
--
-- TWO AND NOT ONE, DELIBERATELY. Fewer statements is not automatically less
-- work. Measured on Percona 8.0.43-34:
--
--   * the four column adds together, ALGORITHM=INSTANT -> metadata only,
--     TOTAL_ROW_VERSIONS ticks up, no data touched, seconds.
--   * the index alone, ALGORITHM=INPLACE LOCK=NONE -> online, no rebuild.
--   * all five in ONE alter -> runs, but TOTAL_ROW_VERSIONS resets to 0, i.e.
--     it REBUILDS THE WHOLE TABLE, and that form cannot be LOCK=NONE either,
--     so it would block writes for the length of a 50GB rebuild.
--
-- Merging the two would turn the cheapest change in the plan into the most
-- expensive. Run them as two.
--
-- Safe to re-run: both are guarded and do nothing if already applied.

-- ============================================================================
-- 1 of 2: the columns. Metadata only, seconds, does not touch data.
-- ============================================================================
SET @adds := CONCAT_WS(', ',
    (SELECT IF(COUNT(*) = 0, 'ADD COLUMN polygon_cells MEDIUMBLOB NULL', NULL)
       FROM information_schema.columns WHERE table_schema = DATABASE()
        AND table_name = 'rippling_reach' AND column_name = 'polygon_cells'),
    (SELECT IF(COUNT(*) = 0, 'ADD COLUMN max_polygon_cells MEDIUMBLOB NULL', NULL)
       FROM information_schema.columns WHERE table_schema = DATABASE()
        AND table_name = 'rippling_reach' AND column_name = 'max_polygon_cells'),
    (SELECT IF(COUNT(*) = 0, 'ADD COLUMN overflow_cells JSON NULL', NULL)
       FROM information_schema.columns WHERE table_schema = DATABASE()
        AND table_name = 'rippling_reach' AND column_name = 'overflow_cells'),
    (SELECT IF(COUNT(*) = 0,
        'ADD COLUMN has_max_reach TINYINT(1) GENERATED ALWAYS AS (max_polygon_cells IS NOT NULL) VIRTUAL',
        NULL)
       FROM information_schema.columns WHERE table_schema = DATABASE()
        AND table_name = 'rippling_reach' AND column_name = 'has_max_reach')
);
SET @ddl := IF(@adds IS NULL OR @adds = '', 'SELECT 1',
    CONCAT('ALTER TABLE rippling_reach ', @adds, ', ALGORITHM=INSTANT'));
SELECT @ddl AS statement_1_of_2;
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ============================================================================
-- 2 of 2: the index. Online (LOCK=NONE), no rebuild. Kept separate on purpose.
-- ============================================================================
SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND index_name = 'rippling_reach_maxreach_candidates');
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND column_name = 'has_max_reach');
SET @ddl := IF(@has_idx = 0 AND @has_col > 0,
    'ALTER TABLE rippling_reach ADD INDEX rippling_reach_maxreach_candidates (status, has_max_reach, updated_at), ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1');
SELECT @ddl AS statement_2_of_2;
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- VERIFY:
--   SELECT column_name, extra FROM information_schema.columns
--    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
--      AND column_name IN ('polygon_cells','max_polygon_cells','overflow_cells','has_max_reach');
--
--   SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols
--     FROM information_schema.statistics
--    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
--      AND index_name = 'rippling_reach_maxreach_candidates'
--    GROUP BY index_name;
--   -- must be exactly: status,has_max_reach,updated_at
--
--   EXPLAIN SELECT msgid, lat, lng, schedule FROM rippling_reach
--    WHERE has_max_reach = 0 AND schedule IS NOT NULL AND status = 'expanding'
--    ORDER BY updated_at DESC LIMIT 200;
--   -- must key on rippling_reach_maxreach_candidates, with no filesort. If it
--   -- still names rippling_reach_updated_at, the query is written as
--   -- `max_polygon_cells IS NULL` somewhere: MySQL does not substitute the
--   -- generated column for the expression, so the raw form ignores this index.
