-- Idempotent production SQL for 2026_10_30_000002_add_audience_to_admins.php
--
-- Says who an ADMIN goes to now there are no communities: the whole country (national), or
-- members within reachmiles of a centre. admins is small.
SET SESSION lock_wait_timeout = 5;
SET @db := DATABASE();

SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE admins ADD COLUMN national TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN lat DECIMAL(10,6) NULL, ADD COLUMN lng DECIMAL(10,6) NULL, ADD COLUMN reachminutes SMALLINT UNSIGNED NULL, ADD COLUMN reachmiles DECIMAL(6,2) NULL, ALGORITHM=INSTANT', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @db AND table_name = 'admins' AND column_name = 'national');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
