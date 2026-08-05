-- Production idempotent SQL: the ripple_join attribution channel + its evidence bit.
--
-- Rippling auto-joins a poster to every group their post rippled into (memberships.rippled = 1).
-- Replies from those members were counted as established local membership (`home`), which handed
-- rippling's own downstream reach to the bucket that means "rippling gets no credit". ripple_join
-- separates them out. See 2026_08_05_000001_add_ripple_join_to_rippling_reply_attribution.php.
--
-- Both statements are metadata-only in MySQL 8: a nullable bool is an INSTANT add, and appending
-- a value to the END of an ENUM does not rewrite rows (inserting one mid-list would).
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reply_attribution'
      AND column_name = 'was_ripple_join');
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE rippling_reply_attribution ADD COLUMN was_ripple_join TINYINT(1) NULL DEFAULT NULL AFTER was_ripple_group_member',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_value := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reply_attribution'
      AND column_name = 'attribution' AND column_type LIKE "%'ripple_join'%");
SET @ddl2 := IF(@has_value = 0,
    "ALTER TABLE rippling_reply_attribution MODIFY COLUMN attribution ENUM('home','ripple_notified','ripple_group','organic_local','ripple_reach','unknown','ripple_join') NULL DEFAULT NULL",
    'SELECT 1');
PREPARE stmt2 FROM @ddl2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;
