-- Production idempotent SQL: put the legacy whitelist words back in the
-- 'allowed' category. The one-off concern_keywords migration only
-- special-cased spam_keywords.action = 'Spam', so 'Whitelist' fell through to
-- 'review' and 13 protected place and shop names became live flag words
-- (grass, Cock Lane, Superdrug Pharmacy and the rest). Driven off
-- spam_keywords, so re-running is a no-op. The two tables use different
-- collations, so each join names one.

-- 1. Reclassify the mis-filed rows.
UPDATE concern_keywords ck
  JOIN spam_keywords sk
    ON LOWER(sk.word) COLLATE utf8mb4_unicode_ci
     = LOWER(ck.keyword) COLLATE utf8mb4_unicode_ci
   SET ck.category = 'allowed'
 WHERE sk.action = 'Whitelist'
   AND ck.category = 'review'
   AND ck.scope = 'global';

-- 2. Add back any whitelist word that never reached concern_keywords ('glass').
INSERT IGNORE INTO concern_keywords
    (keyword, category, match_mode, scope, group_id, action, created_at, updated_at)
SELECT sk.word, 'allowed', IF(sk.type = 'Regex', 'regex', 'literal'),
       'global', 0, 'flag', NOW(), NOW()
  FROM spam_keywords sk
  LEFT JOIN concern_keywords ck
    ON LOWER(ck.keyword) COLLATE utf8mb4_unicode_ci
     = LOWER(sk.word) COLLATE utf8mb4_unicode_ci
 WHERE sk.action = 'Whitelist'
   AND ck.id IS NULL;

-- 3. Verify: this must return no rows afterwards.
SELECT ck.id, ck.keyword, ck.category
  FROM concern_keywords ck
  JOIN spam_keywords sk
    ON LOWER(sk.word) COLLATE utf8mb4_unicode_ci
     = LOWER(ck.keyword) COLLATE utf8mb4_unicode_ci
 WHERE sk.action = 'Whitelist'
   AND ck.category <> 'allowed';
