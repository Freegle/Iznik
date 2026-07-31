-- Production idempotent SQL: messages_likes(source, timestamp, userid) index for the
-- ModTools sysadmin "Recommendations" funnel (iznik-server-go/recommendations/stats.go).
-- Without it, DISTINCT userid over an unindexed (source, timestamp) predicate picks a
-- pathological plan (walks the ~64.5M-entry userid index, a row lookup per entry) that
-- is worse than a table scan and times the endpoint out.
--
-- messages_likes is ~75M rows and HOT, and prod is Galera (wsrep_OSU_method=TOI): a
-- plain ALTER serialises cluster-wide writes for the whole index build. To avoid that
-- stall, run this node-by-node under RSU so no single node blocks the cluster:
--   SET SESSION wsrep_OSU_method = 'RSU';
--   -- run the guarded ALTER below on this node
--   SET SESSION wsrep_OSU_method = 'TOI';
-- Online (ALGORITHM=INPLACE, LOCK=NONE) and guarded so a re-run is a no-op.
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'messages_likes'
      AND index_name = 'messages_likes_source_ts_user');
SET @ddl := IF(@idx_exists = 0,
    'ALTER TABLE messages_likes ADD INDEX messages_likes_source_ts_user (source, timestamp, userid), ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
