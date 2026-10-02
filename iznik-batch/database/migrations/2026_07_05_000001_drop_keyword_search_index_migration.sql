-- Idempotent production SQL for 2026_07_05_000001_drop_keyword_search_index.php
--
-- Retires the SearchTerm micro-volunteering machinery: search_terms and the
-- microactions.searchterm1/2 columns (which fed the retired "SearchTerm"
-- micro-volunteering challenge) plus the legacy VW_search_term_similarities
-- view. The matching code removals ship in the same change set.
--
-- words, words_cache, items_index and messages_index are dropped by the later
-- 2026_09_27_000001_drop_dead_keyword_search_tables migration.
--
-- KEPT deliberately: search_history and users_searches (search analytics) and the
-- damlevlim() stored function.
--
-- Safe to run online and more than once. Foreign-key and index names are looked
-- up from information_schema rather than hard-coded, because production names may
-- differ from the Laravel defaults. Run once on production AFTER deploying the
-- code that no longer reads or writes these objects. Requires ALTER/DROP on the
-- iznik database.

-- 1. Drop the microactions searchterm foreign keys (name-agnostic).
SET @fk1 := (SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'microactions'
    AND COLUMN_NAME = 'searchterm1' AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1);
SET @sql := IF(@fk1 IS NOT NULL, CONCAT('ALTER TABLE microactions DROP FOREIGN KEY `', @fk1, '`'), 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk2 := (SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'microactions'
    AND COLUMN_NAME = 'searchterm2' AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1);
SET @sql := IF(@fk2 IS NOT NULL, CONCAT('ALTER TABLE microactions DROP FOREIGN KEY `', @fk2, '`'), 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Drop the composite unique that spans searchterm1 (the single-column indexes
--    go away with the columns in step 3).
SET @idx := (SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'microactions'
    AND COLUMN_NAME = 'searchterm1' AND INDEX_NAME <> 'searchterm1' LIMIT 1);
SET @sql := IF(@idx IS NOT NULL, CONCAT('ALTER TABLE microactions DROP INDEX `', @idx, '`'), 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. Drop the columns. MySQL has no DROP COLUMN IF EXISTS, so guard each one.
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'microactions' AND COLUMN_NAME = 'searchterm1'),
  'ALTER TABLE microactions DROP COLUMN searchterm1', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'microactions' AND COLUMN_NAME = 'searchterm2'),
  'ALTER TABLE microactions DROP COLUMN searchterm2', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4. Drop the SearchTerm table (no inter-table foreign keys).
DROP TABLE IF EXISTS search_terms;

-- 5. Drop the legacy keyword-similarity view.
DROP VIEW IF EXISTS VW_search_term_similarities;
