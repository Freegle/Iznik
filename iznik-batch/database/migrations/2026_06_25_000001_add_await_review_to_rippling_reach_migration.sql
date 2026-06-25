-- Production idempotent SQL: rippling_reach.awaiting_review_since + awaiting_review_seconds
-- (earned-reach gate instrumentation, auto-approve x rippling).
--
-- awaiting_review_since: TIMESTAMP NULL - set when a post's earned-reach expansion is paused
--   (review weight < required), cleared on resume or termination.
-- awaiting_review_seconds: INT UNSIGNED NOT NULL DEFAULT 0 - accumulates total seconds spent
--   paused across all pause-resume cycles. Both surfaced on the sysadmin rippling page.
--
-- Both are INSTANT adds in MySQL 8 (no rebuild), so safe to run online. Idempotent.
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND column_name = 'awaiting_review_since');
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE rippling_reach ADD COLUMN awaiting_review_since TIMESTAMP NULL DEFAULT NULL, ADD COLUMN awaiting_review_seconds INT UNSIGNED NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
