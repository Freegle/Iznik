-- Production idempotent SQL: memberships(groupid, emailfrequency).
--
-- WHY. The immediate digest asks, per community, who wants immediate mail:
--
--   ... WHERE memberships.groupid = ? AND memberships.emailfrequency = ?
--
-- Both are equality tests, but the only groupid-leading index carries collection second,
-- so the lookup seeks on the community and then walks all its members checking
-- emailfrequency. Measured on production across every live Freegle community: 4,987,773
-- membership rows examined to find 344,961 immediate members — 93% of the work discarded.
-- On the largest community, EXPLAIN reports 39,078 rows examined for a single lookup.
--
-- This got more expensive with #1339, which removed the coarse pre-filter that skipped
-- communities with no immediate members, so the loop now visits every community.
--
-- HOW TO RUN. memberships is large and hot, and prod is Galera with
-- wsrep_OSU_method=TOI, so a plain ALTER serialises cluster-wide writes for the whole
-- index build. Run this node by node under RSU so no single node blocks the cluster:
--
--   SET SESSION wsrep_OSU_method = 'RSU';
--   -- run the guarded ALTER below on this node
--   SET SESSION wsrep_OSU_method = 'TOI';
--
-- Avoid db1's 04:00–04:17 backup window, when that node is intentionally desynced.
--
-- LOCKING. ALGORITHM=INPLACE, LOCK=NONE builds a plain secondary index online. If a
-- future MySQL refuses it, the ALTER errors rather than silently blocking writes; rerun
-- with LOCK=SHARED and expect writes to memberships to wait.
--
-- Guarded on information_schema so a re-run is a no-op.
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'memberships'
      AND index_name = 'memberships_groupid_emailfrequency');
SET @ddl := IF(@idx_exists = 0,
    'ALTER TABLE memberships ADD INDEX memberships_groupid_emailfrequency (groupid, emailfrequency), ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- AFTERWARDS. Give the optimizer figures for the new index before judging it:
--
--   ANALYZE TABLE memberships;
--
-- Then confirm the lookup stops walking the whole community. This is a check to run, not
-- a result to assume — if the rows estimate has not dropped then the index is not being
-- used and the change has bought nothing, so say so rather than closing the ticket:
--
--   EXPLAIN SELECT COUNT(*) FROM memberships WHERE groupid = <a large community> AND emailfrequency = 0;
--
-- Wanted: key=memberships_groupid_emailfrequency, and a rows estimate close to that
-- community's immediate-member count rather than its total membership.
