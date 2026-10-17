-- Idempotent production SQL for 2026_06_04_000002_add_quality_sample_to_messages_groups.php
--
-- messages_groups.quality_sample: this copy was held back from auto-publishing as part of the
-- quality-check sample (FREEGLE_AUTOAPPROVE_QUALITY_CHECK_PCT), for a moderator's verdict.
-- Stamped by AutomodService as it records an approve; read by AutoApproveCleanService and the
-- Go countdown. No index: every reader filters on it behind collection = 'Pending'.
--
-- Trailing NOT NULL column with a default: ALGORITHM=INSTANT, metadata only, no rebuild.
-- lock_wait_timeout makes it fail fast rather than queue behind a long transaction.
SET SESSION lock_wait_timeout = 5;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'messages_groups'
      AND column_name = 'quality_sample'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE messages_groups ADD COLUMN quality_sample TINYINT(1) NOT NULL DEFAULT 0 AFTER checkedby, ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
