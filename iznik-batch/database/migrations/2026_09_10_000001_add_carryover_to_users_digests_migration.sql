-- Idempotent production SQL for 2026_09_10_000001_add_carryover_to_users_digests.php
--
-- Adds users_digests.carryover: the msgids the member's last daily digest left out at
-- the post cap, re-offered on the next run. NULL when nothing was left out.
--
-- Safe to run more than once: the ALTER only runs when the column is absent.
-- Run once on production BEFORE deploying iznik-batch code that reads it.

SET @exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users_digests' AND column_name = 'carryover'
);
SET @sql := IF(@exists = 0,
    "ALTER TABLE users_digests ADD COLUMN carryover JSON NULL COMMENT 'msgids the last digest left out at the post cap; re-offered next run' AFTER lastsent",
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
