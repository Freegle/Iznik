-- Idempotent production SQL for 2026_10_20_000001_add_release_published_to_paf_imports.php
-- paf_imports is a handful of rows. ALGORITHM=INSTANT adds the column without copying the table,
-- and the short lock wait gives up rather than queue behind anything. Safe to run twice: the
-- procedure only adds the column when it is missing.
SET SESSION lock_wait_timeout = 5;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paf_imports' AND COLUMN_NAME = 'release_published');
SET @sql := IF(@has = 0,
  'ALTER TABLE `paf_imports` ADD COLUMN `release_published` date DEFAULT NULL AFTER `edition`, ALGORITHM=INSTANT',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
