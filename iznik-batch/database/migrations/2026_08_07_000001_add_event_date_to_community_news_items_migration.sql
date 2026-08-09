-- Production idempotent SQL: record when a community-news item's event happens.
--
-- Research runs hourly, the newsletter goes out on Fridays, and an item stays eligible for
-- item_freshness_days (10 on live) after being researched. An event found on Monday and held on
-- Wednesday was therefore still "fresh" on Friday and went out inviting people to something that
-- had already happened. researched_at records when WE looked, not when the event IS, so nothing
-- in the table could filter it.
--
-- Nullable: most items are not dated events (a new cycle path, a library refurbishment) and must
-- keep flowing through. Only an item whose date has passed is held back.
-- See 2026_08_07_000001_add_event_date_to_community_news_items.php.
--
-- Adding a nullable column is INSTANT in MySQL 8 (metadata only, no row rewrite). The index build
-- is the only real work and the table is small.
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'community_news_items'
      AND column_name = 'event_date');
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE community_news_items ADD COLUMN event_date DATE NULL DEFAULT NULL AFTER source',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'community_news_items'
      AND index_name = 'community_news_items_areaid_event_date_index');
SET @ddl2 := IF(@idx_exists = 0,
    'ALTER TABLE community_news_items ADD INDEX community_news_items_areaid_event_date_index (areaid, event_date)',
    'SELECT 1');
PREPARE stmt2 FROM @ddl2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;
