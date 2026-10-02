-- Idempotent production SQL for 2026_09_23_000001_add_needs_moderator_to_messages_groups.php
--
-- Adds messages_groups.needs_moderator: set on every copy a moderator's Back to pending
-- pulls back, so no automatic path can approve it until a moderator does.
-- Trailing NOT NULL column with default → INSTANT add in MySQL 8 (no rebuild).
-- Run once on production BEFORE deploying the iznik-server-go and iznik-batch code that uses it.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'messages_groups'
      AND column_name = 'needs_moderator'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE messages_groups ADD COLUMN needs_moderator TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
