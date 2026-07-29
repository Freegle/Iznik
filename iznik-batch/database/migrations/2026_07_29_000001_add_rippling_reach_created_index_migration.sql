-- Production idempotent SQL: rippling_reach(created_at, total_freeglers) index for the
-- ModTools sysadmin rippling analytics tab (iznik-server-go/rippling/analytics.go).
-- Without it every analytics query full-scans rippling_reach — only ~52k rows but ~17GB
-- because each row carries the reach polygons — costing ~15s per query, four queries
-- serially, tipping /rippling/analytics past the gateway timeout (504s).
--
-- Prod is Galera (wsrep_OSU_method=TOI): a plain ALTER serialises cluster-wide writes
-- for the index build. Run this node-by-node under RSU so no single node blocks the
-- cluster (RSU is session-scoped; it reverts when the session ends):
--   SET SESSION wsrep_OSU_method = 'RSU';
--   -- run the guarded ALTER below on this node
--
-- rippling_reach has a SPATIAL index, so MySQL refuses LOCK=NONE for INPLACE builds on
-- it — LOCK=SHARED is the least locking mode available (build took 2-14s per node when
-- applied to prod 2026-07-29). Guarded so a re-run is a no-op.
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND index_name = 'rippling_reach_created_freeglers');
SET @ddl := IF(@idx_exists = 0,
    'ALTER TABLE rippling_reach ADD INDEX rippling_reach_created_freeglers (created_at, total_freeglers), ALGORITHM=INPLACE, LOCK=SHARED',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
