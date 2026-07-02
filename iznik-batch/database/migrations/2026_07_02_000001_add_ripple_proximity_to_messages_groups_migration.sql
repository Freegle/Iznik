-- Production idempotent SQL: add messages_groups.ripple_proximity_p/_q (the "quicker to
-- get to" moderator note, computed once at ripple-in time by ExpandService::recordRippleProximity).
-- Trailing nullable columns → INSTANT add in MySQL 8 (no rebuild).
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'messages_groups'
      AND column_name = 'ripple_proximity_p'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE messages_groups ADD COLUMN ripple_proximity_p VARCHAR(160) NULL',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'messages_groups'
      AND column_name = 'ripple_proximity_q'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE messages_groups ADD COLUMN ripple_proximity_q VARCHAR(160) NULL',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
