-- Production idempotent SQL: messages_bulk_access.edittoken (bulk-offer external update link).
-- Unguessable per-offer secret authorising the logged-out availability-update page. One row per
-- msgid; NULL = no link issued. Adds a UNIQUE index for fast, safe token lookup (MySQL allows
-- multiple NULLs in a unique index). Run once on production BEFORE deploying.
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'messages_bulk_access' AND column_name = 'edittoken');
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE messages_bulk_access ADD COLUMN edittoken VARCHAR(64) NULL DEFAULT NULL AFTER accessinstructions',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'messages_bulk_access' AND index_name = 'messages_bulk_access_edittoken_unique');
SET @ddl2 := IF(@idx_exists = 0,
    'ALTER TABLE messages_bulk_access ADD UNIQUE INDEX messages_bulk_access_edittoken_unique (edittoken)',
    'SELECT 1');
PREPARE stmt2 FROM @ddl2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;
