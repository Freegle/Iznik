-- Idempotent production SQL for 2026_11_12_000001_add_held_back_to_users_digests.php
--
-- Adds users_digests.held_back: the msgids the reach gate kept out of the member's daily
-- digest that the cursor then passed, re-offered on later runs until sent or too old.
-- NULL when nothing is held back.
--
-- Safe to run more than once: the ALTER only runs when the column is absent. Adding a nullable
-- column is an INSTANT change, so it takes no table copy and only a brief metadata lock; the
-- short lock wait stops it queuing behind a long transaction.
-- Run once on production BEFORE deploying iznik-batch code that reads it.

SET SESSION lock_wait_timeout = 5;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users_digests' AND column_name = 'held_back'
);
SET @sql := IF(@exists = 0,
    "ALTER TABLE users_digests ADD COLUMN held_back JSON NULL COMMENT 'msgids the reach gate held back that the cursor passed; re-offered until sent or too old' AFTER carryover, ALGORITHM=INSTANT",
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
