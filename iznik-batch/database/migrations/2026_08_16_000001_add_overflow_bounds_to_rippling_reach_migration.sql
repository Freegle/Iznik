-- Production idempotent SQL: rippling_reach.overflow_bounds.
--
-- The overflow lanes' rings for a post. Two lanes, mutually exclusive per post, decided by
-- whether the audience cap actually bound (iznik-routing-go ripple.go; section 7c of the
-- rippling algorithm reference):
--
--   {"rural": {"dense": "<wkt>", "medium": "<wkt>", "sparse": "<wkt>"}}   cap-bound posts
--   {"fairness": {"1": "<wkt>", ...}, "weight": 0.5}                     ceiling-bound posts
--
-- A member outside the committed reach is admitted if they fall inside the ring for their own
-- density band (rural) or their own deprivation fifth (fairness). Neither lane changes which
-- groups the post is copied to.
--
-- JSON rather than seven geometry columns: consulted only in the fallback branch, never on the
-- hot indexed path that polygon/outer_bound/inner_bound serve, so no spatial index is wanted.
-- Same convention as reachable_group_ids and rejected_groups on this table.
--
-- Nullable, and NULL is the normal state: both lanes ship dark, so nothing writes this until
-- RIPPLE_RURAL_ACCESS_ENABLED or RIPPLE_FAIRNESS_ENABLED is turned on. Safe to run well ahead
-- of the code, and safe to re-run.
--
-- Column add is INSTANT on Percona 8.0. No index: the fallback branch reads it by msgid, which
-- the primary key already serves.
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND column_name = 'overflow_bounds');
SET @ddl := IF(@has_col = 0,
    'ALTER TABLE rippling_reach
        ADD COLUMN overflow_bounds JSON NULL COMMENT ''Overflow lane rings (rural per band, or fairness per deprivation fifth). NULL unless a lane is enabled.''',
    'DO 0');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
