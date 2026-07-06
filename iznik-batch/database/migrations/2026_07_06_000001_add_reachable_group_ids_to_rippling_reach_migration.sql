-- Production deploy SQL for the Laravel migration of the same name (idempotent).
-- Adds the reachable_group_ids JSON column to rippling_reach: the set of group IDs
-- containing a road node reachable from the post origin (water/toll-correct ripple
-- targeting). NULL on pre-rollout rows; gate/retraction fall back to the polygon then.
SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rippling_reach'
      AND COLUMN_NAME = 'reachable_group_ids'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE rippling_reach ADD COLUMN reachable_group_ids JSON NULL AFTER schedule',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
