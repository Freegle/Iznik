-- Idempotent production SQL for 2026_06_04_000001_add_checked_columns_to_messages_groups.php
--
-- messages_groups.checkedat / checkedby: a moderator (or two microvolunteers) has looked at a
-- post that published itself (post-moderation, docs/moderators/post-moderation.md). Drives the
-- Check queue and its count, and counts as "a human look" for the earned-reach gate.
--
-- Two nullable columns, ALGORITHM=INSTANT: metadata only, no rebuild, so it fails rather than
-- rebuilding the largest table if it cannot be instant. There is deliberately no foreign key
-- and no index: the Check queue reads the existing `groupid` index (groupid, collection,
-- deleted, arrival) on the seven-day arrival window and filters checkedat on the rows it finds.
-- lock_wait_timeout makes the statement fail fast rather than queue behind a long transaction.
-- Run once BEFORE deploying the code.
SET SESSION lock_wait_timeout = 5;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'messages_groups'
      AND column_name = 'checkedat'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE messages_groups ADD COLUMN checkedat TIMESTAMP NULL DEFAULT NULL AFTER rejectedat, ADD COLUMN checkedby BIGINT UNSIGNED NULL DEFAULT NULL AFTER checkedat, ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
