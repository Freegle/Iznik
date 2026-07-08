-- Idempotent production DDL for 2026_07_08_000001_add_source_to_rippling_held_replies.
-- Adds rippling_held_replies.source (email/tn/web), default 'email' for existing (email/TN) rows.
-- MySQL has no ADD COLUMN IF NOT EXISTS, so guard via information_schema.

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rippling_held_replies'
      AND COLUMN_NAME = 'source'
);
SET @ddl := IF(@col = 0,
    "ALTER TABLE rippling_held_replies ADD COLUMN source ENUM('email','tn','web') NOT NULL DEFAULT 'email' AFTER replieruserid",
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
