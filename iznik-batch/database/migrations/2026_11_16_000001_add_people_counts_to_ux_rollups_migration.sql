-- Idempotent production SQL for 2026_11_16_000001_add_people_counts_to_ux_rollups.php
--
-- Adds counts of distinct people to ux_flow_daily and ux_component_daily. NULL means the day was
-- rolled up before the columns existed. ALGORITHM=INSTANT, so metadata-only: no table rebuild and
-- no long lock. lock_wait_timeout makes it fail fast rather than queue behind a long transaction.
-- Run once on production BEFORE deploying the iznik-batch code that uses it.
SET SESSION lock_wait_timeout = 5;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'ux_flow_daily'
      AND column_name = 'people_entered'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE ux_flow_daily ADD COLUMN people_entered INT UNSIGNED NULL, ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'ux_flow_daily'
      AND column_name = 'people_completed'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE ux_flow_daily ADD COLUMN people_completed INT UNSIGNED NULL, ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'ux_component_daily'
      AND column_name = 'people'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE ux_component_daily ADD COLUMN people INT UNSIGNED NULL, ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'ux_component_daily'
      AND column_name = 'people_trouble'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE ux_component_daily ADD COLUMN people_trouble INT UNSIGNED NULL, ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
