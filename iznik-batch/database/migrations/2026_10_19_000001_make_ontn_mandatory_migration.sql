-- Idempotent production SQL for 2026_10_19_000001_make_ontn_mandatory.php
--
-- Every Freegle community is on TrashNothing. Changing a column default is
-- metadata-only, and groups is about 500 rows, so both statements are quick.
SET SESSION lock_wait_timeout = 5;
ALTER TABLE `groups` ALTER COLUMN `ontn` SET DEFAULT 1;
UPDATE `groups` SET `ontn` = 1 WHERE `type` = 'Freegle' AND `ontn` = 0;
