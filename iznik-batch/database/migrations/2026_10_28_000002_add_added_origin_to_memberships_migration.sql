-- Idempotent production SQL for 2026_10_28_000002_add_added_origin_to_memberships.php
--
-- memberships.added_origin and memberships_history.added_origin: how the row was created. Every
-- existing row starts as 'unknown' until the join logs are read back into it.
--
-- Run AFTER 2026_10_28_000001_add_implicit_to_memberships_migration.sql (added_origin goes after
-- implicit), and BEFORE deploying the Go API that selects it: the member lists read it.
--
-- Each ADD COLUMN goes after an existing column with a default, so it is INSTANT on Percona 8.0;
-- ALGORITHM=INSTANT makes it refuse rather than rebuild the table if that ever stops being true.
SET SESSION lock_wait_timeout = 5;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'memberships' AND column_name = 'added_origin');
SET @ddl := IF(@has_col = 0,
    'ALTER TABLE memberships
        ADD COLUMN added_origin ENUM(''manual'',''posted'',''replied'',''subscribed'',''rippled'',''moderator'',''partner'',''tn_unaddressed'',''signup'',''unknown'') NOT NULL DEFAULT ''unknown'' AFTER implicit,
        ALGORITHM=INSTANT',
    'SELECT "memberships.added_origin already present" AS note');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'memberships_history' AND column_name = 'added_origin');
SET @ddl := IF(@has_col = 0,
    'ALTER TABLE memberships_history
        ADD COLUMN added_origin ENUM(''manual'',''posted'',''replied'',''subscribed'',''rippled'',''moderator'',''partner'',''tn_unaddressed'',''signup'',''unknown'') NOT NULL DEFAULT ''unknown'' AFTER rippled,
        ALGORITHM=INSTANT',
    'SELECT "memberships_history.added_origin already present" AS note');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
