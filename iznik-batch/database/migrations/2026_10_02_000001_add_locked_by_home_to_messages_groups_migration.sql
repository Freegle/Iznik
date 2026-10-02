-- Idempotent production SQL for 2026_10_02_000001_add_locked_by_home_to_messages_groups.php
--
-- Adds messages_groups.locked_by_home: set on each rippled-in copy a home-community
-- moderator's Back to pending pulls back, so the receiving community cannot approve it
-- until the home copy is approved again.
-- Trailing NOT NULL column with default → INSTANT add in MySQL 8 (no rebuild).
-- Run once on production BEFORE deploying the iznik-server-go and iznik-batch code that uses it.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'messages_groups'
      AND column_name = 'locked_by_home'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE messages_groups ADD COLUMN locked_by_home TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
