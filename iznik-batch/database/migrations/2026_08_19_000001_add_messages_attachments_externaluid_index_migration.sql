-- Production idempotent SQL: messages_attachments(externaluid).
--
-- WHY. Accepting a regenerated AI image repoints the attachments that used the old image:
--
--   UPDATE messages_attachments SET externaluid = ? WHERE externaluid = ?
--
-- externaluid carries no index, so each Accept full-scans a 35.9M-row table
-- (EXPLAIN: type=ALL, possible_keys=NULL, rows=35,860,240) to rewrite 0-4 rows. From
-- apiv2's slow-SQL log at aiimage.go:805 on 2026-08-19: 30.2s, 30.3s, 30.7s, 35.4s,
-- 40.0s, 48.1s, 50.6s, 70.8s.
--
-- It is a user-visible failure, not just a slow query. The API gateway (applb HAProxy)
-- times out at 50s, so the runs over 50s returned an error to ModTools mid-UPDATE: the
-- moderator saw "Failed to accept image. Please try again." and retried, launching
-- another 35M-row scan. Reported on the community forum the same day.
--
-- HOW TO RUN. prod is Galera with wsrep_OSU_method=TOI, so a plain ALTER serialises
-- cluster-wide writes for the whole build on a 2.9GB table. Run node by node under RSU:
--
--   SET SESSION wsrep_OSU_method = 'RSU';
--   -- run the guarded ALTER below on this node
--   SET SESSION wsrep_OSU_method = 'TOI';
--
-- Avoid db1's 04:00-04:17 backup window, when that node is intentionally desynced.
--
-- LOCKING. messages_attachments is ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16. ADD INDEX is
-- INPLACE-capable on a compressed table, so ALGORITHM=INPLACE, LOCK=NONE is what this
-- asks for. If the server refuses it, the ALTER fails rather than quietly blocking
-- writes; rerun with LOCK=SHARED and expect writes to this table to wait for the build.
--
-- SIZE. Existing indexes on this table total 0.8GB against 2.9GB of data. A sample of
-- 63,900 recent rows (id > 45400000) shows 0 NULLs, max length 64, 99.99% carrying the
-- constant 'freegletusd-' prefix, and 60,628 distinct values against 60,627 distinct
-- 20-char prefixes. externaluid(24) would therefore be about a third smaller at the same
-- measured selectivity, if index size turns out to matter more than the row recheck it
-- costs.
--
-- Guarded on information_schema so a re-run is a no-op.
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'messages_attachments'
      AND index_name = 'messages_attachments_externaluid');
SET @ddl := IF(@idx_exists = 0,
    'ALTER TABLE messages_attachments ADD INDEX messages_attachments_externaluid (externaluid), ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- AFTERWARDS. Give the optimizer figures for the new index before judging it:
--
--   ANALYZE TABLE messages_attachments;
--
-- Then confirm the Accept path has stopped scanning. This is a check to run, not a
-- result to assume — if it still says type=ALL the index is not being used and the
-- change has bought nothing, so say so rather than closing the ticket:
--
--   EXPLAIN SELECT id FROM messages_attachments
--   WHERE externaluid = 'freegletusd-0000000000000000000000000000000a';
--
-- Wanted: type=ref, key=messages_attachments_externaluid, rows in single figures.
