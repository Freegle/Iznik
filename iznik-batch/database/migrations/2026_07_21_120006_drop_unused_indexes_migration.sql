-- Production runbook for 2026_07_21_120006_drop_unused_indexes.php
--
-- Drops 23 secondary indexes that no query uses, reclaiming ~5.5 GB of the 48.3 GB
-- of index on an 81.4 GB database. The disk is the less interesting half: chat_roster
-- and messages are written constantly, so the bigger win is not maintaining 12 dead
-- b-trees on every INSERT and UPDATE.
--
-- HOW THESE WERE IDENTIFIED
--
-- Two independent signals, because neither alone is sufficient:
--
--   1. performance_schema.table_io_waits_summary_by_index_usage COUNT_READ, collected
--      from ALL THREE cluster nodes (10.220.0.22 / .150 / .47) and summed. Reading one
--      node is actively misleading - db1, the node behind the live tunnel, serves
--      almost no read traffic, so nearly everything looks unused there. Every index
--      below is zero across all three.
--   2. A read of the Go API and Laravel batch code for each candidate, to catch paths
--      too infrequent to appear in the sample window (db1/db2 had ~2 days of uptime,
--      db3 ~14 days).
--
-- Step 2 was not a formality. Indexes that read zero on all three nodes but are NOT
-- dropped here, because the code says otherwise:
--
--   email_tracking_images.email_tracking_id  1.82 GB  CASCADE FK, structurally required
--   paf_addresses.udprn                      1.07 GB  UNIQUE + PafService dedup on a
--                                                     manually-run PAF import
--   messages.envelopefrom                             SpamCheckService, near-every inbound
--   messages.subject                                  ContentCheckService subject-repeat
--   messages.message-id                               incoming-mail dedup (insertOrIgnore)
--   microactions.searchterm1 / searchterm2            FK to search_terms
--
-- Equally, the cross-node sum overturned several code-based guesses: users.lastname
-- (5.3M reads - MySQL scans it as a covering index for the admin name search),
-- messages.type (8.3M), messages.deleted (13.2M) and messages.date (4.9M, the nightly
-- purge:messages job) all looked droppable from the code alone and are not.
--
-- WHAT IS BEING DROPPED, AND WHY IT IS SAFE
--
-- None is UNIQUE. None backs a foreign key. All verified against
-- information_schema.KEY_COLUMN_USAGE on live, not against the migrations - the
-- migrations have drifted (see the schema-parity migrations in this change set), and
-- trusting them would have wrongly cleared chat_messages.scheduleid, which does carry
-- an FK on production.
--
--   chat_roster (2.80 GB index vs 1.24 GB data; written on every chat interaction)
--     lastip           841 MB  write-only; IP abuse checks read spam_countries /
--                              spam_whitelist_ips, never chat_roster
--     lastmsgseen      343 MB  filtered, but always after chatid_2 (chatid,userid) has
--                              already pinned the row, so never the driving index
--     lastmsgnotified  343 MB  written by ChatNotificationService, read by nothing -
--                              a leftover from V1's removed notification_chaseup.php
--     date             332 MB  PurgeService never touches chat_roster directly, only
--                              via cascade from chat_rooms
--     status           249 MB  5-value ENUM, always a residual filter after chatid_2
--
--   messages (leftover email plumbing)
--     fromaddr         434 MB  (fromaddr, subject) - no WHERE anywhere. Note this is a
--                              different column from envelopefrom, which IS used.
--     envelopeto       253 MB  only ever `LIKE '%-volunteers@...'`, a leading wildcard
--                              a b-tree cannot serve
--     fromip           253 MB  (index is named `fromup`) write/select only
--     sourceheader     253 MB  the stats query is served by the arrival composite,
--                              which already leads with arrival and carries sourceheader
--     lat              103 MB  geo search goes via messages_spatial's SPATIAL index;
--     lng              103 MB  the remaining lat/lng predicate is an ST_Contains on a
--                              constructed POINT, which no plain b-tree can serve
--     retrylastfailure 103 MB  write-only. RetryFailedMailCommand targets the Laravel
--                              failed_jobs queue, not this column.
--
--   users_searches.locationid      567 MB  INSERT-only (message.go:1469), no FK
--   users_emails.md5hash           274 MB  virtual generated column; tracking uses
--                                          email_tracking.tracking_id instead
--   users_emails.viewed            112 MB  no query context anywhere
--   locations.osm_id               256 MB  SELECT list and INSERT only
--   locations.newareaid            105 MB  orphaned - only the migration mentions it
--   users.firstname_2              197 MB  (firstname, lastname) - the name search is a
--                                          leading-wildcard LIKE and the optimizer picks
--                                          the narrower `lastname` index for the scan
--   users.suspectcount              39 MB  zero references in the monorepo
--   users.gotrealemail              34 MB  one-time migration flag
--   memberships_history.added      190 MB  EXPLAIN confirms processingrequired always
--                                          wins this plan; `added < cutoff` matches
--                                          essentially the whole table
--   audits.auditable_type/id       158 MB  laravel-auditing ships it by default; Freegle
--                                          has no per-record audit history view
--   engage.timestamp               125 MB  only MAX() scoped by userid; no purge job
--
-- PRODUCTION PATH
--
-- Production is Percona XtraDB Cluster 8.0.45 (3 nodes), where DDL runs TOI by
-- default - the cluster blocks writes for the whole operation. DROP INDEX is far
-- cheaper than a build, but chat_roster and messages are large and hot, so prefer
-- RSU node by node.
--
-- The statements are grouped one ALTER per table so InnoDB rebuilds each table once
-- rather than once per index.
--
-- Run off-peak. On each node in turn: take it out of the proxy rotation, confirm
-- SHOW STATUS LIKE 'wsrep_local_state_comment' reports "Synced", then:
--
--     SET SESSION wsrep_OSU_method = 'RSU';
--     ... the ALTER statements below ...
--     SET SESSION wsrep_OSU_method = 'TOI';
--
-- Wait for the node to resync before moving to the next. RSU is safe for this change
-- specifically because these are plain secondary index drops: no column, constraint or
-- uniqueness change, so writes landing from other nodes mid-operation apply cleanly.
--
-- These statements are NOT idempotent - MySQL has no DROP INDEX IF EXISTS. Re-running
-- one that has already been applied gives ERROR 1091, which is harmless. To check
-- first:
--
--     SELECT table_name, index_name FROM information_schema.statistics
--      WHERE table_schema = 'iznik'
--        AND (table_name, index_name) IN (('chat_roster','lastip'), ('messages','lat'));

ALTER TABLE chat_roster
    DROP INDEX lastip,
    DROP INDEX lastmsg,
    DROP INDEX lastmsgnotified,
    DROP INDEX `date`,
    DROP INDEX `status`,
    ALGORITHM=INPLACE, LOCK=NONE;

ALTER TABLE messages
    DROP INDEX fromaddr,
    DROP INDEX envelopeto,
    DROP INDEX fromup,
    DROP INDEX sourceheader,
    DROP INDEX lat,
    DROP INDEX lng,
    DROP INDEX retrylastfailure,
    ALGORITHM=INPLACE, LOCK=NONE;

ALTER TABLE users
    DROP INDEX firstname_2,
    DROP INDEX gotrealemail,
    DROP INDEX suspectcount,
    ALGORITHM=INPLACE, LOCK=NONE;

ALTER TABLE users_emails
    DROP INDEX md5hash,
    DROP INDEX viewed,
    ALGORITHM=INPLACE, LOCK=NONE;

ALTER TABLE locations
    DROP INDEX osm_id,
    DROP INDEX newareaid,
    ALGORITHM=INPLACE, LOCK=NONE;

ALTER TABLE users_searches
    DROP INDEX locationid,
    ALGORITHM=INPLACE, LOCK=NONE;

ALTER TABLE memberships_history
    DROP INDEX `date`,
    ALGORITHM=INPLACE, LOCK=NONE;

ALTER TABLE audits
    DROP INDEX audits_auditable_type_auditable_id_index,
    ALGORITHM=INPLACE, LOCK=NONE;

ALTER TABLE engage
    DROP INDEX `timestamp`,
    ALGORITHM=INPLACE, LOCK=NONE;

-- VERIFY afterwards:
--
--     SELECT table_name, COUNT(*) AS secondary_indexes
--       FROM information_schema.statistics
--      WHERE table_schema = 'iznik' AND index_name <> 'PRIMARY'
--        AND table_name IN ('chat_roster','messages','users','users_emails','locations',
--                           'users_searches','memberships_history','audits','engage')
--      GROUP BY table_name;
--     -- chat_roster should drop from 7 to 2, messages from 18 to 11.
--
--     SELECT ROUND(SUM(index_length)/1024/1024/1024, 1) AS index_gb
--       FROM information_schema.tables WHERE table_schema = 'iznik';
--     -- expect roughly 43 GB, down from 48.3 GB. InnoDB returns the pages to the
--     -- tablespace free list; file size on disk only shrinks with OPTIMIZE TABLE,
--     -- which is NOT recommended here (full rebuild of hot multi-GB tables).
--
-- The two queries most worth eyeballing afterwards, since they are the ones that used
-- a dropped index as a fallback scan target rather than a seek:
--
--     EXPLAIN SELECT id FROM users WHERE lastname LIKE '%smith%';
--     -- still expect key=lastname (that index is NOT dropped)
--
--     EXPLAIN SELECT COUNT(*) FROM memberships_history
--      WHERE added < NOW() - INTERVAL 30 MINUTE AND processingrequired = 1;
--     -- expect key=processingrequired, rows in single figures
