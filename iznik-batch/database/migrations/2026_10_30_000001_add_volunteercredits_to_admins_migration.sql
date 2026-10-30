-- Idempotent production SQL for 2026_10_30_000001_add_volunteercredits_to_admins.php
--
-- Adds admins.volunteercredits: whether the email names the community's volunteers. Defaults to 1,
-- so every existing ADMIN is unchanged. ALGORITHM=INSTANT, so metadata-only: no table rebuild and
-- no long lock. lock_wait_timeout makes it fail fast rather than queue behind a long transaction.
-- Run once on production BEFORE deploying the iznik-batch code that uses it.
SET SESSION lock_wait_timeout = 5;
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'admins'
      AND column_name = 'volunteercredits'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE admins ADD COLUMN volunteercredits TINYINT(1) NOT NULL DEFAULT 1, ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
