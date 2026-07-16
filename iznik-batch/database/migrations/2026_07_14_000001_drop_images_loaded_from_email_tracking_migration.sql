-- Production idempotent SQL for 2026_07_14_000001_drop_images_loaded_from_email_tracking.php
--
-- Drops the retired email_tracking.images_loaded denormalised per-hit counter. It was
-- incremented on every tracking-image load; a digest open loads many images at once, so
-- the per-hit exclusive-lock UPDATE on the single parent row serialised with each load's
-- FK insert into email_tracking_images and hit lock-wait timeouts. The write was removed
-- and nothing reads the column - the per-load rows in email_tracking_images are the source
-- of truth, so the count is derivable there.
--
-- Safe to run online and more than once (guarded on column existence). Run AFTER deploying
-- the code that no longer writes or selects the column (this change set). Requires ALTER
-- privilege on the iznik database.
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'email_tracking' AND column_name = 'images_loaded');
SET @ddl := IF(@col_exists > 0,
    'ALTER TABLE email_tracking DROP COLUMN images_loaded',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
