-- Idempotent production SQL for 2026_09_16_000001_add_heldby_to_messages_drafts.php
--
-- Adds messages_drafts.heldby: a mod hold captured from messages_groups when a
-- member's pending post is taken back to draft, restored on repost so an
-- edit-and-repost does not silently clear a hold no mod released (Discourse 9946/8).
--
-- Safe to run more than once: each step only runs when its target is absent.
-- Run once on production BEFORE deploying iznik-server-go code that reads/writes it.

SET @exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'messages_drafts' AND column_name = 'heldby'
);
SET @sql := IF(@exists = 0,
    "ALTER TABLE messages_drafts ADD COLUMN heldby BIGINT UNSIGNED NULL AFTER groupid",
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'messages_drafts'
      AND constraint_name = 'messages_drafts_heldby_foreign'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE messages_drafts ADD CONSTRAINT messages_drafts_heldby_foreign FOREIGN KEY (heldby) REFERENCES users (id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'messages_drafts' AND index_name = 'messages_drafts_heldby_idx'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE messages_drafts ADD INDEX messages_drafts_heldby_idx (heldby)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
