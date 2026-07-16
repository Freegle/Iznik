-- Production idempotent SQL for 2026_07_16_000001_generalise_notified_ledger.php
--
-- Generalise the per-(member, post) notified ledger: rippling_reach_notified ->
-- messages_notified, adding channel VARCHAR(16) DEFAULT 'reach' and widening the
-- PK to (msgid, userid, channel). Behaviour-neutral: existing rows stay 'reach'
-- and nothing writes another channel until the digest code ships.
--
-- Run once on production BEFORE deploying the code that filters channel='reach'.
-- Safe to re-run: guarded by information_schema so a second run is a no-op.

SET @has_old := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach_notified');
SET @has_new := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'messages_notified');

-- 1. Add channel (existing rows default to 'reach').
SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach_notified' AND column_name = 'channel');
SET @sql := IF(@has_old > 0 AND @has_new = 0 AND @col = 0,
    "ALTER TABLE rippling_reach_notified ADD COLUMN channel VARCHAR(16) NOT NULL DEFAULT 'reach'",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Widen the PK to include channel (only while it is still the 2-column PK).
SET @pkcols := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach_notified' AND index_name = 'PRIMARY');
SET @sql := IF(@has_old > 0 AND @has_new = 0 AND @pkcols = 2,
    'ALTER TABLE rippling_reach_notified DROP PRIMARY KEY, ADD PRIMARY KEY (msgid, userid, channel)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Rename to the generalised name.
SET @sql := IF(@has_old > 0 AND @has_new = 0,
    'RENAME TABLE rippling_reach_notified TO messages_notified',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
