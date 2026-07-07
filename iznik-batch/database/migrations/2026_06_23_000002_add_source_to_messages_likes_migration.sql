-- Production idempotent SQL: messages_likes.source (rippling reach experiment Blocker 2).
-- Tags the arrival path of a genuine page-open (pageview=1) so notification-click opens
-- (source='ripple_notify', set by handleView from ?src=ripple_notify) are distinguishable
-- from organic browse (NULL). Nullable trailing/positioned column -> INSTANT add in MySQL 8.
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'messages_likes' AND column_name = 'source');
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE messages_likes ADD COLUMN source VARCHAR(32) NULL DEFAULT NULL AFTER pageview',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
