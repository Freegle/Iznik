-- Production idempotent SQL: newsfeed.leaf - the road-network region tag the
-- road-aware ChitChat feed narrowing reads. Nullable (untagged rows keep the
-- pure radius behaviour); backfilled by `php artisan chitchat:backfill-leaves`
-- once the reach engine is deployed. Column add is ALGORITHM=INSTANT; the
-- index builds INPLACE (online).

SET @have_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsfeed'
    AND COLUMN_NAME = 'leaf'
);
SET @sql := IF(@have_col = 0,
  'ALTER TABLE newsfeed ADD COLUMN leaf INT NULL, ALGORITHM=INSTANT',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @have_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsfeed'
    AND INDEX_NAME = 'leaf'
);
SET @sql := IF(@have_idx = 0,
  'ALTER TABLE newsfeed ADD KEY leaf (leaf), ALGORITHM=INPLACE',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
