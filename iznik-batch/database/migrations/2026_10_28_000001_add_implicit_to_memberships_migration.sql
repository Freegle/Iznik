-- Idempotent production SQL for 2026_10_28_000001_add_implicit_to_memberships.php
--
-- memberships.implicit: 1 while the member has not knowingly joined the community. Starts as a
-- copy of rippled.
--
-- Run BEFORE deploying the Go API that selects this column: the member lists read it.
--
-- The ADD COLUMN goes after an existing column with a default, so it is INSTANT on Percona 8.0;
-- ALGORITHM=INSTANT makes it refuse rather than rebuild the table if that ever stops being true.
--
-- The copy at the end is safe to re-run, and must be re-run once the batch and the Go API that
-- write implicit alongside rippled are both live: until then the old code's ripple joins and
-- clears change rippled alone, and a cleared row left implicit = 1 reads as a ripple join. A run
-- before that point is not enough. It brings implicit into line with rippled in both directions,
-- 10,000 rows at a time,
-- except on TrashNothing placements (added_origin tn_unaddressed): those are implicit with
-- rippled = 0 by design, so it leaves them alone and is safe to run after memberships:backfill-tn.
SET SESSION lock_wait_timeout = 5;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'memberships' AND column_name = 'implicit');
SET @ddl := IF(@has_col = 0,
    'ALTER TABLE memberships
        ADD COLUMN implicit TINYINT(1) NOT NULL DEFAULT 0 AFTER rippled,
        ALGORITHM=INSTANT',
    'SELECT "memberships.implicit already present" AS note');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DROP PROCEDURE IF EXISTS memberships_sync_implicit_2026_10_28;
DELIMITER //
CREATE PROCEDURE memberships_sync_implicit_2026_10_28()
BEGIN
    DECLARE from_id BIGINT UNSIGNED DEFAULT 0;
    DECLARE to_id BIGINT UNSIGNED;
    DECLARE max_id BIGINT UNSIGNED;
    DECLARE has_origin INT;

    SELECT COALESCE(MAX(id), 0) INTO max_id FROM memberships;

    -- added_origin arrives in the next file. Before it does there is no TrashNothing placement
    -- to keep, and a statement naming the column would fail, so the clear is built here.
    SELECT COUNT(*) INTO has_origin FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'memberships' AND column_name = 'added_origin';

    -- One statement per range, both directions. A TrashNothing placement has rippled = 0, so only
    -- the clearing direction could reach one, and the guard stops it.
    SET @sync := CONCAT('UPDATE memberships SET implicit = rippled
        WHERE id > ? AND id <= ? AND implicit <> rippled',
        IF(has_origin > 0, ' AND added_origin <> ''tn_unaddressed''', ''));
    PREPARE sync FROM @sync;

    -- 10,000 rows at a time, not 10,000 ids: ids are sparse (about 5M rows under ids up to 59M
    -- on production in October 2026), so id ranges would mostly be empty.
    WHILE from_id < max_id DO
        SELECT COALESCE(MAX(id), max_id) INTO to_id
            FROM (SELECT id FROM memberships WHERE id > from_id ORDER BY id LIMIT 10000) chunk;
        SET @sync_from := from_id, @sync_to := to_id;
        EXECUTE sync USING @sync_from, @sync_to;
        SET from_id = to_id;
    END WHILE;

    DEALLOCATE PREPARE sync;
END //
DELIMITER ;
CALL memberships_sync_implicit_2026_10_28();
DROP PROCEDURE memberships_sync_implicit_2026_10_28;

-- Expect 0: implicit matches rippled everywhere once the copy has run, TrashNothing placements
-- aside.
SET @has_origin := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'memberships' AND column_name = 'added_origin');
SET @check := CONCAT('SELECT COUNT(*) AS implicit_out_of_line FROM memberships WHERE implicit <> rippled',
    IF(@has_origin > 0, ' AND added_origin <> ''tn_unaddressed''', ''));
PREPARE stmt FROM @check; EXECUTE stmt; DEALLOCATE PREPARE stmt;
