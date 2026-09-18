-- Idempotent production SQL for 2026_09_18_000001_add_reason_to_users_related.php
--
-- Adds users_related.reason: the note shown to moderators on the Related Members card
-- saying why two accounts were linked. Needed once more than one detector writes to the
-- table - the first additional one is shared phone numbers in chat
-- (users:detect-related-phone). NULL means the row came from the original browser-session
-- detector, or predates the column.
--
-- Safe to run more than once: the step only runs when the column is absent.
-- Run once on production BEFORE deploying the code that reads/writes it.

SET @exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users_related' AND column_name = 'reason'
);
SET @sql := IF(@exists = 0,
    "ALTER TABLE users_related ADD COLUMN reason VARCHAR(255) NULL AFTER detected",
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
