-- Production idempotent SQL: rippling_reach.max_polygon + max_cumulative_users.
--
-- The reach a post will EVENTUALLY have, as opposed to `polygon` which is the reach
-- it has right now. The routing server returns the whole tick schedule at t=0, so the
-- final tick is knowable from the moment a post starts rippling; nothing stored it.
--
-- Nullable and unindexed on purpose: populated by the firstreply:maxreach background
-- pass, and every read is a point-in-polygon test on a row already found by msgid
-- (the PRIMARY KEY), so an R-tree here would never be consulted and would force
-- NOT NULL. Readers treat NULL as "not known yet" and fall back to current-reach
-- behaviour, so running this ahead of the backfill changes nothing.
--
-- Adding a column is INSTANT on Percona 8.0 for a nullable trailing-ish column, but
-- ALGORITHM is left to the server here because a GEOMETRY column with an SRID
-- attribute can fall back to INPLACE. Either way this is a small table (~17k rows).
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND column_name = 'max_polygon');
SET @ddl := IF(@has_col = 0,
    'ALTER TABLE rippling_reach
        ADD COLUMN max_polygon GEOMETRY NULL SRID 3857 AFTER polygon,
        ADD COLUMN max_cumulative_users INT UNSIGNED NULL AFTER max_polygon',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
