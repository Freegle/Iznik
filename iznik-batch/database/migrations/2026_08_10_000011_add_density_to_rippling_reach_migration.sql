-- Production idempotent SQL: rippling_reach density-sizing columns.
--
-- A post's reach budget is chosen from how thinly freeglers are spread around its origin
-- (App\Services\Ripple\DensityService). Without these columns that choice is invisible
-- after the fact - you can see how far a post reached but not which rule sent it there,
-- and every question the sizing rule raises is a comparison BETWEEN bands.
--
--   density_band          dense | medium | sparse | unknown ('unknown' = flat cap applied)
--   density_radius_miles  radius holding the nearest k freeglers; a LOWER BOUND where
--                         fewer than k were found (see DensityService::band)
--   max_minutes_cap       what was asked for, as against max_drive_min which is what the
--                         routing server actually reached
--
-- All nullable, so existing rows mean "flat cap, unrecorded" and this is safe to run well
-- ahead of the code.
--
-- Column adds are INSTANT on Percona 8.0. The index is not, so it is added separately
-- (INPLACE, non-locking) and only if absent.
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND column_name = 'density_band');
SET @ddl := IF(@has_col = 0,
    'ALTER TABLE rippling_reach
        ADD COLUMN density_band VARCHAR(8) NULL AFTER max_drive_min,
        ADD COLUMN density_radius_miles DOUBLE NULL AFTER density_band,
        ADD COLUMN max_minutes_cap DOUBLE NULL AFTER density_radius_miles,
        ALGORITHM=INSTANT',
    'SELECT "rippling_reach density columns already present" AS note');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND index_name = 'rippling_reach_density_created');
SET @ddl := IF(@has_idx = 0,
    'ALTER TABLE rippling_reach
        ADD INDEX rippling_reach_density_created (density_band, created_at),
        ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT "rippling_reach_density_created already present" AS note');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
