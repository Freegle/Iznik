-- Production idempotent SQL: widen memberships `groupid` to (groupid, collection, emailfrequency).
--
-- WHY. The immediate digest, and the rippling reach mailer, both ask per community who
-- wants immediate mail:
--
--   ... WHERE memberships.groupid = ?
--         AND memberships.collection = 'Approved'
--         AND memberships.emailfrequency = -1
--
-- All three are equality tests. The index serving this stops at collection, so the lookup
-- seeks the community and then fetches every member to read emailfrequency off the row.
--
-- Measured on production: immediate members are 32,044 of 4,989,415 memberships (0.64%).
-- The lookup runs about 15,455 times a day, reading 204,774,049 membership rows to find
-- 1,165,044 wanted ones. On the largest community it fetches 59,282 rows to return 160.
--
-- This got more expensive with #1339, which removed the coarse pre-filter that skipped
-- communities with no immediate members, so the loop now visits every community.
--
-- WHY WIDEN RATHER THAN ADD. memberships already carries 12 indexes, 1.44GB of them
-- against 0.42GB of data, on a hot table. collection is functionally constant here
-- (4,989,400 Approved, 15 Pending, no Banned), so appending emailfrequency to the existing
-- (groupid, collection) index is a strict superset - every current user of `groupid` keeps
-- its plan, the digest lookup gains a third equality column, and the index count does not
-- grow.
--
-- HOW TO RUN. prod is Galera with wsrep_OSU_method=TOI, so a plain ALTER serialises
-- cluster-wide writes for the whole index build. Run this node by node under RSU so no
-- single node blocks the cluster:
--
--   SET SESSION wsrep_OSU_method = 'RSU';
--   -- run both guarded statements below on this node
--   SET SESSION wsrep_OSU_method = 'TOI';
--
-- Avoid db1's 04:00-04:17 backup window, when that node is intentionally desynced.
--
-- ONE ALTER, NOT TWO. Both changes go in a single statement so InnoDB makes one pass over
-- the table, and so the swap is atomic: there is no window in which the table has neither
-- index, whatever interrupts it. See docs/ops/reference/database-index-hygiene.md,
-- "Applying index changes on the cluster".
--
-- LOCKING. ALGORITHM=INPLACE, LOCK=NONE builds a plain secondary index online, and the
-- drop half is metadata only. If a future MySQL refuses the combination, the ALTER errors
-- rather than silently blocking writes; rerun with LOCK=SHARED and expect writes to
-- memberships to wait.
--
-- The groupid foreign key (memberships_ibfk_2) does not stand in the way of the drop:
-- groupid_2 (groupid, role) already leads with groupid and satisfies it, as does the new
-- index.
--
-- Each half is guarded separately on information_schema, so this converges on the intended
-- shape from any starting state and a re-run is a no-op. information_schema.statistics is
-- cached, so read it with the cache disabled or a re-run can act on stale metadata.

SET SESSION information_schema_stats_expiry = 0;

SET @new_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'memberships'
      AND index_name = 'memberships_groupid_collection_emailfrequency');
SET @old_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'memberships'
      AND index_name = 'groupid');

SET @clauses := CONCAT_WS(', ',
    IF(@new_exists = 0,
       'ADD INDEX memberships_groupid_collection_emailfrequency (groupid, collection, emailfrequency)',
       NULL),
    IF(@old_exists > 0, 'DROP INDEX `groupid`', NULL));

SET @ddl := IF(@clauses IS NULL OR @clauses = '',
    'SELECT 1',
    CONCAT('ALTER TABLE memberships ', @clauses, ', ALGORITHM=INPLACE, LOCK=NONE'));
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- AFTERWARDS. Give the optimizer figures for the new index before judging it:
--
--   ANALYZE TABLE memberships;
--
-- Then confirm the lookup stops fetching the whole community. This is a check to run, not
-- a result to assume - if the rows estimate has not dropped then the index is not being
-- used and the change has bought nothing, so say so rather than closing the ticket. Note
-- emailfrequency = -1 is immediate; 0 is never, and is a much larger set:
--
--   EXPLAIN SELECT COUNT(*) FROM memberships
--    WHERE groupid = 21257 AND collection = 'Approved' AND emailfrequency = -1;
--
-- Wanted: key=memberships_groupid_collection_emailfrequency, key_len=13 (all three
-- columns), and a rows estimate in the hundreds rather than the tens of thousands. Group
-- 21257 has 59,282 approved members and 160 immediate ones.
