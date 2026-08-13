-- Production idempotent SQL: rippling_reach(updated_at).
--
-- WHY. iznik-spatial-go polls, every two minutes, on every node:
--
--   SELECT msgid, status, ST_AsWKB(polygon) FROM rippling_reach WHERE updated_at > ?
--
-- With no index on updated_at that is a full scan of a ~29GB table every time
-- (EXPLAIN: type=ALL, ~48k large rows). Measured executions of 51s on db3 and 52s on
-- db2 against a two-minute interval — close to a 43% duty cycle per instance, roughly
-- 0.4–0.9 mysqld cores per node, continuously, day and night. It is the largest
-- steady-state database cost in the rippling family and, being 24/7, the one that
-- moving work to the night cannot touch.
--
-- HOW TO RUN. rippling_reach is large and hot, and prod is Galera with
-- wsrep_OSU_method=TOI, so a plain ALTER serialises cluster-wide writes for the whole
-- index build. Run this node by node under RSU so no single node blocks the cluster:
--
--   SET SESSION wsrep_OSU_method = 'RSU';
--   -- run the guarded ALTER below on this node
--   SET SESSION wsrep_OSU_method = 'TOI';
--
-- Avoid db1's 04:00–04:17 backup window, when that node is intentionally desynced.
--
-- LOCKING. The build is fully online: ALGORITHM=INPLACE, LOCK=NONE is accepted here,
-- verified against Percona 8.0.43 (prod runs 8.0.45). Worth saying explicitly because
-- the table carries two SPATIAL indexes (rippling_reach_outer, rippling_reach_polygon)
-- and a spatial index cannot itself be built with LOCK=NONE — but that restriction is
-- about ADDING a spatial index, not about adding a plain one to a table that has some.
-- If a future MySQL disagrees, the ALTER will refuse rather than silently block writes;
-- rerun it with LOCK=SHARED and expect writes to that table to wait.
--
-- Guarded on information_schema so a re-run is a no-op.
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND index_name = 'rippling_reach_updated_at');
SET @ddl := IF(@idx_exists = 0,
    'ALTER TABLE rippling_reach ADD INDEX rippling_reach_updated_at (updated_at), ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- AFTERWARDS. Give the optimizer figures for the new index before judging it. Until it has
-- them, it is guessing at how many rows the poll's window matches, and on a table whose rows
-- average around 600KB it can reasonably guess that reading the whole thing is cheaper:
--
--   ANALYZE TABLE rippling_reach;
--
-- Then confirm the poll has actually stopped scanning. This is a check to run, not a result
-- to assume - if it still says type=ALL then the index is not being used and the change has
-- bought nothing, so say so rather than closing the ticket:
--
--   EXPLAIN SELECT msgid, status, ST_AsWKB(polygon) FROM rippling_reach
--   WHERE updated_at > NOW() - INTERVAL 2 MINUTE;
--
-- Wanted: type=range, key=rippling_reach_updated_at, and a rows estimate in the hundreds
-- rather than the tens of thousands.
