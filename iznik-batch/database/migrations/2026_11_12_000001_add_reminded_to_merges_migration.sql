-- Idempotent production SQL for 2026_11_12_000001_add_reminded_to_merges.php
--
-- Adds merges.reminded: when the merge offer email was re-sent, so merges:remind sends it at most
-- once. Nullable, so every existing offer is "not reminded". ALGORITHM=INSTANT, so metadata-only:
-- no table rebuild and no long lock. lock_wait_timeout makes it fail fast rather than queue
-- behind a long transaction. Run once on production BEFORE deploying the iznik-batch code that
-- uses it.
SET SESSION lock_wait_timeout = 5;
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'merges'
      AND column_name = 'reminded'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE merges ADD COLUMN reminded TIMESTAMP NULL DEFAULT NULL, ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
