-- Production idempotent SQL: add messages_groups.rippled_in (rippling-out fast-track, #2).
-- Marks rows created by ExpandService::rippleIntoNewGroups so AutoApproveService can
-- short-window auto-approve them past the membership gate (already vetted on origin).
-- Trailing NOT NULL column with default → INSTANT add in MySQL 8 (no rebuild).
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'messages_groups'
      AND column_name = 'rippled_in'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE messages_groups ADD COLUMN rippled_in TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
