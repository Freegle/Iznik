-- Production idempotent SQL: convert the bulk-offer tables to utf8mb4_unicode_ci.
--
-- On production messages_bulk_items and messages_bulk_items_interest were created
-- with a bare CREATE TABLE that inherited the MySQL 8 server default
-- (utf8mb4_0900_ai_ci). items.name is utf8mb4_unicode_ci, so stats generation's
-- `JOIN items i ON i.name = bi.name` throws 1267 "Illegal mix of collations".
--
-- Both tables are tiny, so CONVERT TO is a sub-second table rebuild. On Galera this
-- runs as a Total Order Isolation DDL (brief cluster pause) — acceptable at this size.
-- Run once on production; safe to re-run (guards on the current collation).

SET @c := (SELECT TABLE_COLLATION FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages_bulk_items');
SET @ddl := IF(@c <> 'utf8mb4_unicode_ci',
    'ALTER TABLE messages_bulk_items CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT TABLE_COLLATION FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages_bulk_items_interest');
SET @ddl := IF(@c <> 'utf8mb4_unicode_ci',
    'ALTER TABLE messages_bulk_items_interest CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
