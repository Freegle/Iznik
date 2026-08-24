-- Production idempotent SQL: switch rippling off for the phantom / moderator-training
-- communities.
--
-- Data-only (no DDL), so it is safe to run on a live node at any time and safe to re-run:
-- JSON_SET writes the same value each time and merges into the rest of settings rather
-- than replacing the blob. The CASE guards rows whose settings is empty or not valid
-- JSON, which JSON_SET would otherwise error on.
--
-- These communities carry moderator practice posts. FreeglePlayground places its at a real
-- Edinburgh postcode, and before the paired code change nothing gated ripple-OUT at all, so
-- those practice posts would crosspost into the live Lothians communities and show up in
-- real members' nearby feeds. Outer Hebrides Freegle is listed by name because it is a
-- phantom community whose area is the real Outer Hebrides, so it does not follow the
-- '%fresher%'/'%playground%' naming convention.
--
-- Matched by name, not id, so the same statement is correct on prod, dev and test.
UPDATE `groups`
   SET settings = JSON_SET(
           CASE WHEN settings IS NOT NULL AND JSON_VALID(settings)
                THEN settings ELSE '{}' END,
           '$.rippling', CAST('{"out": 0, "in": 0}' AS JSON))
 WHERE (nameshort LIKE '%playground%'
        OR nameshort LIKE '%fresher%'
        OR nameshort = 'OuterHebridesFreegle');

-- Verify: every matched community should read {"out": 0, "in": 0}.
SELECT id, nameshort, JSON_EXTRACT(settings, '$.rippling') AS rippling
  FROM `groups`
 WHERE (nameshort LIKE '%playground%'
        OR nameshort LIKE '%fresher%'
        OR nameshort = 'OuterHebridesFreegle');
