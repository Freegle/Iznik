-- Idempotent production SQL for 2026_10_02_000002_add_safeguarding_concern_category.php
--
-- 1. Appends 'safeguarding' to concern_keywords.category, keeping the column's existing
--    (production) physical order. A metadata-only change for an enum append.
-- 2. Moves the keywords added on 2026-10-02 (ids 3838-3880) from 'review' to 'safeguarding',
--    by keyword, and inserts any that are missing. Existing exclude patterns are kept.
-- Run once on production BEFORE deploying the code that reads the category.

SET @coltype := (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'concern_keywords' AND COLUMN_NAME = 'category'
);
SET @ddl := IF(LOCATE('''safeguarding''', @coltype) = 0,
    CONCAT('ALTER TABLE concern_keywords MODIFY COLUMN category ',
           CONCAT(SUBSTRING(@coltype, 1, CHAR_LENGTH(@coltype) - 1), ',''safeguarding'')'),
           ' NOT NULL'),
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @shelter_exclude := '(animal|dog|cat|bus|garden|bike|cycle|log|smoking|rain|pet|rescue|wood|bin|chicken|rabbit|beach|sun|tent|fishing)\\s+shelters?';

-- Move existing general 'review' rows.
UPDATE concern_keywords
   SET category = 'safeguarding',
       exclude = COALESCE(exclude, IF(keyword IN ('shelter', 'shelters'), @shelter_exclude, NULL))
 WHERE scope = 'global' AND group_id = 0 AND category = 'review'
   AND keyword IN ('refuge', 'refuges', 'domestic violence', 'domestic abuse', 'dv', 'fleeing',
                   'escaping abuse', 'escaped abuse', 'women''s aid', 'womens aid', 'safe house',
                   'hostel', 'hostels', 'shelter', 'shelters');

-- Insert any that are missing.
INSERT INTO concern_keywords (keyword, category, match_mode, exclude, scope, group_id, action)
SELECT k.keyword, 'safeguarding', 'literal',
       IF(k.keyword IN ('shelter', 'shelters'), @shelter_exclude, NULL), 'global', 0, 'flag'
  FROM (
        SELECT 'refuge' AS keyword UNION SELECT 'refuges' UNION SELECT 'domestic violence'
        UNION SELECT 'domestic abuse' UNION SELECT 'dv' UNION SELECT 'fleeing'
        UNION SELECT 'escaping abuse' UNION SELECT 'escaped abuse' UNION SELECT 'women''s aid'
        UNION SELECT 'womens aid' UNION SELECT 'safe house' UNION SELECT 'hostel'
        UNION SELECT 'hostels' UNION SELECT 'shelter' UNION SELECT 'shelters'
       ) k
 WHERE NOT EXISTS (
        SELECT 1 FROM concern_keywords c
         WHERE c.scope = 'global' AND c.group_id = 0
           AND c.keyword COLLATE utf8mb4_unicode_ci = k.keyword COLLATE utf8mb4_unicode_ci
       );
