-- Idempotent production SQL for 2026_10_30_000001_add_reach_to_events_and_volunteering.php
--
-- Gives community events and volunteering opportunities a centre point and a reach,
-- so they are shown around a place rather than to a community. Both tables are small.
SET SESSION lock_wait_timeout = 5;
SET @db := DATABASE();

SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE communityevents ADD COLUMN lat DECIMAL(10,6) NULL, ADD COLUMN lng DECIMAL(10,6) NULL, ADD COLUMN reachminutes SMALLINT UNSIGNED NULL, ADD COLUMN reachmiles DECIMAL(6,2) NULL, ALGORITHM=INSTANT', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @db AND table_name = 'communityevents' AND column_name = 'lat');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE volunteering ADD COLUMN lat DECIMAL(10,6) NULL, ADD COLUMN lng DECIMAL(10,6) NULL, ADD COLUMN reachminutes SMALLINT UNSIGNED NULL, ADD COLUMN reachmiles DECIMAL(6,2) NULL, ALGORITHM=INSTANT', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @db AND table_name = 'volunteering' AND column_name = 'lat');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

UPDATE communityevents
  INNER JOIN users ON users.id = communityevents.userid
  INNER JOIN locations ON locations.id = users.lastlocation
  SET communityevents.lat = locations.lat, communityevents.lng = locations.lng
  WHERE communityevents.lat IS NULL;

UPDATE volunteering
  INNER JOIN users ON users.id = volunteering.userid
  INNER JOIN locations ON locations.id = users.lastlocation
  SET volunteering.lat = locations.lat, volunteering.lng = locations.lng
  WHERE volunteering.lat IS NULL;
