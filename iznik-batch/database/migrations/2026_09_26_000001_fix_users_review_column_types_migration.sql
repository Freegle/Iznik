-- Idempotent production SQL for 2026_09_26_000001_fix_users_review_column_types.php
--
-- Repairs a database where an earlier copy of 2026_09_20_000001_remove_group_model added
-- users.reviewrequestedat, reviewreason and reviewedat as BIGINT UNSIGNED. They are timestamp,
-- string and timestamp, as the legacy memberships columns were and as the application reads
-- them. The current 2026_09_20 SQL adds them with these types, so there this file does nothing.
--
-- A BIGINT cannot become a TIMESTAMP or a string in place, so the change is a table copy: the
-- algorithm is not forced. Reasons and times already copied into the BIGINT columns are not
-- recoverable from them. Re-run pass 2 of the 2026_09_20 SQL afterwards to copy them again from
-- memberships, if that table still exists. Safe to run more than once.

SET @needs_fix := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'reviewreason'
      AND data_type = 'bigint'
);
SET @ddl := IF(@needs_fix > 0,
    'ALTER TABLE users MODIFY COLUMN reviewrequestedat TIMESTAMP NULL, MODIFY COLUMN reviewreason VARCHAR(255) NULL, MODIFY COLUMN reviewedat TIMESTAMP NULL',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
