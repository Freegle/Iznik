-- Production deploy SQL for the Laravel migration of the same name (idempotent).
-- Widens rippling_reply_attribution with graded-attribution evidence bits + the derived
-- attribution channel + the client-reported surface. All nullable: NULL = not captured
-- (pre-migration row). See the .php migration for the full semantics of each column.
--
-- Run once on production BEFORE deploying the iznik-server-go build that writes these
-- columns (the Go handler checks column existence at startup and falls back to the
-- legacy insert until they exist, so order is not critical - but the new data only
-- accrues once both are live). Then run: php artisan ripple:backfill-reply-attribution

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rippling_reply_attribution'
      AND COLUMN_NAME = 'was_notified'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE rippling_reply_attribution ADD COLUMN was_notified TINYINT(1) NULL DEFAULT NULL AFTER was_home_member',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rippling_reply_attribution'
      AND COLUMN_NAME = 'was_ripple_group_member'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE rippling_reply_attribution ADD COLUMN was_ripple_group_member TINYINT(1) NULL DEFAULT NULL AFTER was_notified',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rippling_reply_attribution'
      AND COLUMN_NAME = 'in_origin_catchment'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE rippling_reply_attribution ADD COLUMN in_origin_catchment TINYINT(1) NULL DEFAULT NULL AFTER was_ripple_group_member',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rippling_reply_attribution'
      AND COLUMN_NAME = 'in_reach'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE rippling_reply_attribution ADD COLUMN in_reach TINYINT(1) NULL DEFAULT NULL AFTER in_origin_catchment',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rippling_reply_attribution'
      AND COLUMN_NAME = 'post_had_rippled'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE rippling_reply_attribution ADD COLUMN post_had_rippled TINYINT(1) NULL DEFAULT NULL AFTER in_reach',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- New column (not an enum widen), so no Galera COPY-rebuild concern; order is ladder precedence.
SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rippling_reply_attribution'
      AND COLUMN_NAME = 'attribution'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE rippling_reply_attribution ADD COLUMN attribution ENUM(''home'',''ripple_notified'',''ripple_group'',''organic_local'',''ripple_reach'',''unknown'') NULL DEFAULT NULL AFTER post_had_rippled',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rippling_reply_attribution'
      AND COLUMN_NAME = 'client_source'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE rippling_reply_attribution ADD COLUMN client_source VARCHAR(32) NULL DEFAULT NULL AFTER attribution',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
