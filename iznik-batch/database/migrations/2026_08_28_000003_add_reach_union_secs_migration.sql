-- Grid-removal endgame columns (idempotent; both INSTANT).
--
-- origin_union_secs: road-native origin-group union threshold. NULL = not
-- computed (cells decide, transitional); -1 = never activates; >= 0 = the
-- drive-time budget at which the origin group's whole area is admitted.
--
-- rippling_reach_leaves.fp: partition-build fingerprint for the row's leaf
-- id, so a dual-build routing server (rolling label migration across a map
-- refresh) scopes candidates to the builds it has loaded.

SET @exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND column_name = 'origin_union_secs');
SET @ddl := IF(@exists = 0,
    'ALTER TABLE rippling_reach ADD COLUMN origin_union_secs FLOAT NULL, ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach_leaves'
      AND column_name = 'fp');
SET @ddl := IF(@exists = 0,
    'ALTER TABLE rippling_reach_leaves ADD COLUMN fp BIGINT UNSIGNED NULL, ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
