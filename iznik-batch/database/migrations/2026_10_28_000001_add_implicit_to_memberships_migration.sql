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
-- The copy at the end is safe to re-run, and should be re-run just before the code that writes
-- implicit alongside rippled goes live, because until then ripple joins and clears change rippled
-- alone. It brings implicit into line with rippled in both directions, 10,000 ids at a time.
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
    DECLARE max_id BIGINT UNSIGNED;

    SELECT COALESCE(MAX(id), 0) INTO max_id FROM memberships;

    WHILE from_id < max_id DO
        UPDATE memberships SET implicit = rippled
            WHERE id > from_id AND id <= from_id + 10000 AND implicit <> rippled;
        SET from_id = from_id + 10000;
    END WHILE;
END //
DELIMITER ;
CALL memberships_sync_implicit_2026_10_28();
DROP PROCEDURE memberships_sync_implicit_2026_10_28;

-- Expect 0: implicit matches rippled everywhere once the copy has run.
SELECT COUNT(*) AS implicit_out_of_line FROM memberships WHERE implicit <> rippled;
