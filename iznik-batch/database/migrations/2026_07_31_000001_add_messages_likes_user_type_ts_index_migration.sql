-- Production idempotent SQL: messages_likes(userid, type, timestamp) index for
-- DigestRelevanceService::interests(), which builds a member's interest vectors from
-- the posts they recently viewed.
--
-- That query filters `userid = ? AND type = 'View' AND timestamp >= ?` and keeps the
-- newest MAX_INTERESTS rows. With only the (userid) index, MySQL reads every like row
-- the member has ever had (thousands for an engaged member) to keep a few dozen, once
-- per digest recipient. messages_likes_source_ts_user cannot serve it: that index leads
-- on `source`, which this query does not constrain.
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
      AND index_name = 'messages_likes_user_type_ts');
SET @ddl := IF(@idx_exists = 0,
    'ALTER TABLE messages_likes ADD INDEX messages_likes_user_type_ts (userid, type, timestamp), ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
