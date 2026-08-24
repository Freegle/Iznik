-- Production idempotent SQL: rippling_reach.min_tick.
--
-- A floor the expander must not sit below. Expansion is normally driven by elapsed
-- time alone - the hazard schedule says which tick a post should be on by now. That
-- is right while nothing has been learned since the post went up.
--
-- A scout reply IS something learned. Scouts go to people OUTSIDE the current reach,
-- so a reply from one is evidence the item is wanted at that distance, and the people
-- around them deserve the same chance rather than waiting for the clock. The scout's
-- own tick becomes the floor and the next expansion jumps to it.
--
-- Nullable because almost every row will never have one, and a NULL floor means
-- "behave exactly as before" - so this is safe to run well ahead of the code.
--
-- INSTANT on Percona 8.0: nullable, no default, added after an existing column.
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND column_name = 'min_tick');
SET @ddl := IF(@has_col = 0,
    'ALTER TABLE rippling_reach
        ADD COLUMN min_tick SMALLINT UNSIGNED NULL AFTER tick,
        ALGORITHM=INSTANT',
    'SELECT "rippling_reach.min_tick already present" AS note');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
