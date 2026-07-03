-- Production idempotent SQL: messages_bulk_items.available (bulk-offer external update link).
-- Lets the offerer / an external owner (via the logged-out secret-link page) mark a single
-- catalogue item taken/gone independently of its quantity; messages.availablenow is recomputed
-- as the sum of quantities across items still marked available. NOT NULL DEFAULT 1 -> existing
-- items read as available. Positioned bool -> INSTANT add in MySQL 8. Run once on production
-- BEFORE deploying.
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'messages_bulk_items' AND column_name = 'available');
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE messages_bulk_items ADD COLUMN available TINYINT(1) NOT NULL DEFAULT 1 AFTER quantity',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
