-- Idempotent production SQL for 2026_06_15_000001_add_autoapprove_hold_until_to_messages_groups.php
--
-- messages_groups.autoapprove_hold_until: the Go API bumps this (extend-only, at least ten
-- minutes ahead) whenever a moderator loads a Pending copy, so a post a moderator is looking at
-- is not published out from under them by the auto-approve crons. No index: every reader
-- filters on it behind collection = 'Pending'.
--
-- Nullable TIMESTAMP: ALGORITHM=INSTANT, metadata only, no rebuild. lock_wait_timeout makes it
-- fail fast rather than queue behind a long transaction.
SET SESSION lock_wait_timeout = 5;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'messages_groups'
      AND column_name = 'autoapprove_hold_until'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE messages_groups ADD COLUMN autoapprove_hold_until TIMESTAMP NULL DEFAULT NULL AFTER quality_sample, ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
