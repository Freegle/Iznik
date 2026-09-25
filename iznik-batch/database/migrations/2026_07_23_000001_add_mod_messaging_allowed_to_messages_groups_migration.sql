-- Production idempotent SQL: add messages_groups.mod_messaging_allowed.
-- Whether mods on this group may message the poster of this message directly.
-- Defaults to allowed, matching existing behaviour for ordinary Freegle posts.
-- Set to disallowed only in specific cases, e.g. TN posters who have not
-- consented to mod contact per TN's freegle_group_ids (see PostSyncer::processPost).
-- Trailing NOT NULL column with default → INSTANT add in MySQL 8 (no rebuild).
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'messages_groups'
      AND column_name = 'mod_messaging_allowed'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE messages_groups ADD COLUMN mod_messaging_allowed TINYINT(1) NOT NULL DEFAULT 1',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
