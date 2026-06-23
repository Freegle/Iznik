-- Production idempotent SQL: add messages_likes.pageview (genuine page-view instrumentation).
--   pageview = 1  -> genuine page-open       (Go handleView writes/upgrades to 1)
--   pageview = 0  -> list-scroll impression  (Go MarkSeen writes 0 on a fresh insert)
--   pageview = NULL -> legacy / unknown      (all pre-migration rows)
-- The 'View' type still marks "seen" for list de-duplication; pageview isolates real
-- eyeballs for the rippling extent governor + reach experiment.
-- Nullable ON PURPOSE: pre-existing rows are genuinely unknown, not impressions, so they
-- must read NULL (not 0). Nullable column with NULL default -> INSTANT add in MySQL 8.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'messages_likes'
      AND column_name = 'pageview'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE messages_likes ADD COLUMN pageview TINYINT(1) NULL DEFAULT NULL AFTER count',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
