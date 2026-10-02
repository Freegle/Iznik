-- Idempotent production SQL for 2026_10_02_000003_add_modguidance_to_admins.php
--
-- Adds admins.modguidance: guidance for local moderators on adapting a system-wide ADMIN.
-- Never sent to members. Nullable TEXT with ALGORITHM=INSTANT, so metadata-only: no table
-- rebuild and no long lock. lock_wait_timeout makes it fail fast rather than queue behind a
-- long transaction on admins.
-- Run once on production BEFORE deploying the iznik-server-go and iznik-batch code that uses it.
SET SESSION lock_wait_timeout = 5;
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'admins'
      AND column_name = 'modguidance'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE admins ADD COLUMN modguidance TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL, ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
