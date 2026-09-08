-- Production idempotent SQL: messages_promises.count. One RSU pass.
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'messages_promises' AND column_name = 'count');
SET @ddl := IF(@has_col = 0,
    "ALTER TABLE messages_promises ADD COLUMN count INT UNSIGNED NULL DEFAULT NULL COMMENT 'How many were promised to this user; NULL reads as 1', ALGORITHM=INSTANT",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- Verify: SHOW COLUMNS FROM messages_promises LIKE 'count';
