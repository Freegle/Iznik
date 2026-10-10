-- Production idempotent SQL: trysts.msgid. One RSU pass.
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'trysts' AND column_name = 'msgid');
SET @ddl := IF(@has_col = 0,
    "ALTER TABLE trysts ADD COLUMN msgid BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Post this collection time is for', ALGORITHM=INSTANT",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'trysts' AND index_name = 'trysts_msgid');
SET @ddl2 := IF(@has_idx = 0, "ALTER TABLE trysts ADD INDEX trysts_msgid (msgid)", 'SELECT 1');
PREPARE stmt2 FROM @ddl2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;
-- Verify: SHOW COLUMNS FROM trysts LIKE 'msgid'; SHOW INDEX FROM trysts WHERE Key_name = 'trysts_msgid';
