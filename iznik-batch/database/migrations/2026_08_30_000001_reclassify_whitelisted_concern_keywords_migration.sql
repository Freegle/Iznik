-- Production idempotent SQL: put the legacy whitelist words back in the
-- 'allowed' category. The one-off concern_keywords migration only
-- special-cased spam_keywords.action = 'Spam', so 'Whitelist' fell through to
-- 'review' and 13 protected place and shop names became live flag words
-- (grass, Cock Lane, Superdrug Pharmacy and the rest). Driven off
-- spam_keywords, so running it twice is safe. The two tables use different
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

-- 2. 'grass' is the exception. Matching is now anchored to word boundaries, so
--    nothing fires inside it and it needs no entry at all. Leaving it as
--    'allowed' would be worse than useless: allowed phrases are cut out of the
--    text before scanning, so it would take the word out of the four Schedule 9
--    plant keywords that end in it - crimson fountain grass, perennial veldt
--    grass, purple pampas grass, purple veldt grass - each of which blocks.
DELETE FROM concern_keywords WHERE keyword = 'grass' AND scope = 'global';

-- 3. Verify. Both of these must return no rows.

--    a. No legacy whitelist word is still flagging.
SELECT ck.id, ck.keyword, ck.category
  FROM concern_keywords ck
  JOIN spam_keywords sk
    ON LOWER(sk.word) COLLATE utf8mb4_unicode_ci
     = LOWER(ck.keyword) COLLATE utf8mb4_unicode_ci
 WHERE sk.action = 'Whitelist'
   AND ck.category <> 'allowed';

--    b. No allowed phrase is a whole word inside a keyword that still flags,
--       which is the condition that would silently disable that keyword.
SELECT a.keyword AS allowed_phrase, k.keyword AS keyword_it_would_disable, k.category
  FROM concern_keywords a
  JOIN concern_keywords k
    ON k.category <> 'allowed'
   AND LOWER(k.keyword) <> LOWER(a.keyword)
   AND LOWER(k.keyword) REGEXP CONCAT('\\b', LOWER(a.keyword), '\\b')
 WHERE a.category = 'allowed';
