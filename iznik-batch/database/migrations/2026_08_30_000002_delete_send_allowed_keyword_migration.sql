-- Production idempotent SQL: delete the 'send' allowed keyword, which
-- word-boundary-matches inside the scam keyword 'send the money' and — since
-- allowed phrases are cut out of the text before scanning — made that keyword
-- unmatchable. Found by 2026_08_30_000001's verification query (b) on prod.
-- Legacy of the substring engine (worrywords 485, type 'Allowed'); under
-- word-boundary matching nothing can fire inside 'send', so like 'grass' it
-- needs no entry at all.
--
-- ALREADY APPLIED to production by hand 2026-08-30 (single-row DELETE, id
-- 368). Safe to re-run.

DELETE FROM concern_keywords
 WHERE LOWER(keyword) = 'send' AND category = 'allowed' AND scope = 'global';

-- Verify: 2026_08_30_000001's query (b) must return no rows.
SELECT a.keyword AS allowed_phrase, k.keyword AS keyword_it_would_disable, k.category
  FROM concern_keywords a
  JOIN concern_keywords k
    ON k.category <> 'allowed'
   AND LOWER(k.keyword) <> LOWER(a.keyword)
   AND LOWER(k.keyword) REGEXP CONCAT('\\b', LOWER(a.keyword), '\\b')
 WHERE a.category = 'allowed';
