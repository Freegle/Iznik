-- Production idempotent SQL: add rippling_reach.rejected_groups (secondary-reject clip persistence, #9).
-- A JSON array of group ids subtracted from this post's reach; advanceDue re-applies them each tick
-- so a secondary-group rejection is not undone by the next schedule-driven polygon overwrite.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'rippling_reach'
      AND column_name = 'rejected_groups'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE rippling_reach ADD COLUMN rejected_groups JSON NULL AFTER status',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
