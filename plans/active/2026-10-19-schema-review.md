# Schema review: suggested improvements

Status: proposal only. No migrations, no code changes. Each item below needs its own PR with the raw production SQL in the description, as usual.

Evidence was gathered on 2026-10-19 from the production cluster's bulk-read node (Percona XtraDB Cluster 8.0.46, three nodes, uptime 17.6 days at the time, `performance_schema` on, digests reset 2026-10-01), from `information_schema`, `sys` and `performance_schema`, and from the code in `iznik-server-go`, `iznik-batch` and `iznik-nuxt3`. All live queries were read-only and sampled; nothing bigger than an index range or a 10,000-row tail was scanned.

Two caveats apply to every "unused" claim:

- The node queried is the bulk-read node. Member-facing API reads go to the write node and are not visible in its counters. Writes are visible (Galera applies them on every node). An index with zero reads here may still be read on the write node. The July 2026 index-drop pass (`iznik-batch/database/migrations/2026_07_21_120006_drop_unused_indexes_migration.sql`) summed counters from all three nodes and then read the code for each candidate; anything proposed here should go through the same two steps before it is dropped.
- Row counts from `information_schema.TABLES` are estimates. Where a number matters it was confirmed with an index-only `COUNT`.

Database size: 274 tables, 84.9 GB data plus 54.3 GB index, 12.6 GB of that free space inside tablespaces. The buffer pool is 6 GB, so index size is what decides whether reads hit disk.

## Summary

Ranked by payoff against risk. "Saving" is disk unless stated; "risk" is the migration risk on Galera (DDL runs cluster-wide under TOI, so a rebuild of a big table blocks every node for its duration unless done with pt-online-schema-change or in a quiet window).

| # | Finding | Saving or benefit | Risk | Effort |
|---|---|---|---|---|
| 1 | `bounces` (7.8 GB) is written by nothing in the repos and read by nothing. | 7.8 GB. | Low: archive then `DROP TABLE`. Find the writer outside the repos first. | Small. |
| 2 | `messages_likes` (120 M rows, 24 GB, 17% of the database): the `messages_likes_source_ts_user` index has had zero reads; `View` rows are kept 365 days when nothing needs more than about 90. | About 7 GB (index, estimated) plus roughly three quarters of the table over time. | Low for the index drop (INPLACE). Retention change is a purge setting. | Small. |
| 3 | `users_aboutme` has 10.7 M rows for 87,633 users: every profile save inserts a new row, even when unchanged. | About 3.3 GB. | Low: chunked delete of all but the latest row per user, plus a code fix. | Small. |
| 4 | `audits` (9.2 M rows, 2.9 GB, growing 53,000 rows a day) is read only by the GDPR export, and 97% of it is automated noise. | 2.9 GB now, about 6 GB a year. | Low: config change and a retention purge. | Small. |
| 5 | Dead tables: the `simulation_message_isochrones_*` and `transport_*` sets (4.3 GB) and about 30 small ones, never opened in 17 days and unreferenced in code. | 5.2 GB. | Low: `DROP TABLE` after a backup. | Small. |
| 6 | `email_tracking_images.email_tracking_id` index (2.85 GB) has zero reads and exists only for a CASCADE that a 30-day purge makes redundant. | 2.85 GB plus one fewer b-tree on 1.8 M inserts a day. | Medium: drop the FK and index, purge by id range instead. | Small. |
| 7 | `logs` keeps 31 M of its 45 M rows from before 2024; most are `Message/Deleted` and `Message/Autoreposted`. | About 4 GB. | Low: extend the existing chunked purge. | Small. |
| 8 | `rippling_reach` is 7.4 GB for 47,000 rows, uncompressed blobs and JSON, and its status query full-scans it 13,800 times in 17 days (2.1 hours of latency). | 3 to 4 GB and the scan time. | Medium: `ROW_FORMAT=COMPRESSED` is a rebuild; the query change is code. | Medium. |
| 9 | `stats`: 1.8 M rows have `type = ''` because `OurPostingBreakdown` is not in the ENUM and `sql_mode` is not strict. Also 20 M rows since 2015 with three single-column indexes. | Correctness; about 1 to 2 GB with a composite index and a roll-up of old rows. | Low: adding an ENUM value is INSTANT. | Small. |
| 10 | `users_searches` is never pruned (28 M rows since 2017, 2.3 GB) and two of its columns are always NULL. | About 2 GB. | Low: purge. | Small. |
| 11 | Hot queries that schema could help: chat-room list (33 hours of node time in 17 days), `messages_groups` lookup from the embedding store (12.5 hours), a `jobs` count (5.3 hours), national volunteering list (2.8 hours). | CPU on the read node. | Low to medium. | Medium. |
| 12 | 19 tables have no declared primary key and 33 have no `id`; the cluster accepts them only because each has a NOT NULL unique key. | Galera safety and the house convention. | Rebuild per table; `messages_likes` and `messages_groups` are the big ones. | Medium. |
| 13 | 12.6 GB of free space inside tablespaces, 1.7 GB of it in `logs_emails` (136 MB of data) and 1.7 GB in `chat_roster`. | Up to 12 GB, mostly on five tables. | `OPTIMIZE TABLE` is a rebuild. | Small, scheduled. |
| 14 | Legacy columns with no code references (Yahoo sync, COVID, Norfolk, licences) and columns that are always NULL or constant. | Clarity; small disk. | Dropping a column on a COMPRESSED table is a rebuild; on DYNAMIC tables it is INSTANT. | Small each. |
| 15 | Naming and type conventions are split between the legacy style (`userid`, `timestamp`, `utf8mb4_unicode_ci`) and the Laravel style (`user_id`, `created_at`, `utf8mb4_0900_ai_ci`). | Consistency. | None now; adopt one rule for new tables. | Policy. |

## 1. Wasted space

### 1.1 `bounces`: 7.8 GB of raw bounce mail nobody reads

- `information_schema`: 312,778 rows, 7,773 MB data, no secondary index. Rows are `to varchar(80)`, `msg longtext`, `date`. A 200-row sample averages 10.5 KB of raw MIME per row (max 51 KB).
- IO since uptime: 0 reads, 2 writes. Last write 2026-10-05. Date range 2026-02-03 to 2026-10-05.
- Code: no reference to the `bounces` table in `iznik-server-go`, `iznik-batch` or `iznik-nuxt3` (the Go `emailtracking` package reads `bounces_emails`, a different table). `PurgeService` does not touch it. The two writes in 17 days come from something outside these repos, most likely the mail host.
- Suggestion: find and stop the writer, export the table to object storage if anyone wants it, `DROP TABLE bounces`. Galera: `DROP TABLE` is metadata only; deleting a 7.8 GB `.ibd` can stall I/O for a few seconds, so do it in the quiet gap after the 04:00 backup.

### 1.2 `messages_likes`: 24 GB, one dead index, retention longer than needed

- 120 M rows, 9,998 MB clustered, 13,996 MB secondary. The largest table in the database.
- Structure: no declared primary key; unique `(msgid, userid, type)` acts as the clustered index. Secondary: `msgid (msgid, type)`, `userid (userid)`, `messages_likes_source_ts_user (source, timestamp, userid)`.
- Index reads since uptime: `msgid_2` 100.6 billion, `msgid` 410 M, `userid` 5.8 M, `messages_likes_source_ts_user` 0. That index was added by `2026_07_22_000001_add_messages_likes_stats_indexes.php` for a ModTools sysadmin stat. It is the widest index on the table (a 32-byte varchar plus timestamp plus the 17-byte clustered key per entry); from column widths it is roughly 7 GB of the 14 GB. The stat page runs on the write node, so confirm its reads there before dropping, or replace the stat with a daily rollup.
- Content: a sample of 300 recent posts shows every row is `type = 'View'` (221 page views, 15 digest marks). The ENUM `('Love','Laugh','View')` names a reactions table; in practice this is a page-view log, and the name misleads.
- Retention: `PurgeService::purgeOldLikes` keeps 365 days (oldest NULL-source row is exactly one year old). The `browse` source alone added 27 M rows between 2026-06-24 and now. The consumers are the browse "unseen" filter (`iznik-server-go/message/message.go:6787` and the `LEFT JOIN messages_likes` in the browse queries) and `MarkDigestSeenCommand`; both only care about posts still on the board, which are at most a few months old. A 90-day retention would keep roughly a quarter of the rows.
- `PurgeService.php:709` notes the delete is by `msgid` because `timestamp` is unindexed; with the composite index above dropped, a plain `(timestamp)` index would be far smaller and would let the purge run by range.
- Suggestion, in order: (a) confirm `messages_likes_source_ts_user` is unread on the write node, then `DROP INDEX` (INPLACE, no rebuild, 7 GB back immediately); (b) cut `View` retention to 90 days; (c) when the table is next rebuilt, declare `(msgid, userid, type)` as the PRIMARY KEY and consider renaming to `messages_views`.

### 1.3 `users_aboutme`: 10.7 M rows for 87,633 users

- `COUNT(DISTINCT userid)` over the `userid` index: 87,633 users, 10,705,364 rows. Average 122 rows per user; 2,613 MB data plus 757 MB index for an average 138 bytes of text.
- Cause: `iznik-server-go/session/session.go:2224` and `iznik-server-go/user/user.go:2772` both `INSERT` a new row whenever the settings request carries `aboutme`, whether or not the text changed. The only reads take the latest row (`user.go:808`, `ORDER BY timestamp DESC LIMIT 1`; `session.go:1079`). The model is also `Auditable`, so each insert is logged again in `audits`.
- Suggestion: insert only when the text differs from the latest row; delete all but the newest row per user in chunks (about 10.6 M rows); then `OPTIMIZE TABLE` to reclaim the space. Saving about 3.3 GB. Alternatively make it one row per user with a UNIQUE on `userid` and `UPDATE` in place; history is already in `audits` if ever wanted.

### 1.4 `audits`: 2.9 GB, 53,000 rows a day, read by nothing but the GDPR export

- 9,222,347 rows, 2,737 MB data, since 2026-05-21. 904,264 inserts and 0 reads in 17 days on this node. The only reader in code is `iznik-server-go/userdump/collect_db.go:233` (GDPR export).
- Writer: `owen-it/laravel-auditing` on 50 models (`iznik-batch/config/audit.php`, `console => true`, so console jobs are audited). A 200-row sample: 139 `UserReplyTime updated`, 30 `ChatMessage updated`, 12 `UserAboutMe updated`, 9 `ChatMessage created`, 5 `ChatRoom created`, 3 `User updated`. That is 97% automated counter churn.
- Columns: `user_type varchar(255)`, `url text`, `user_agent varchar(1023)`, `tags varchar(255)` are NULL or a few bytes for console writes. `audits_user_id_user_type_index` has cardinality 21 on 9.2 M rows.
- Suggestion: remove `Auditable` from `UserReplyTime`, `ChatRoster`, `UserAboutMe` and the batch-written chat models, or set `console => false`; add a 90-day purge. If nobody will ever read it, drop the table and the package and let the GDPR export use `logs`.

### 1.5 Dead tables (5.2 GB)

Never opened since uptime (absent from `performance_schema.table_io_waits_summary_by_table`), no SQL-level reference in any repo, and in most cases `UPDATE_TIME` NULL:

| Table | Rows | MB | Notes |
|---|---|---|---|
| `simulation_message_isochrones_messages` | 300,555 | 2,736 | Simulation from 2025-10; 0 code refs. |
| `simulation_message_isochrones_expansions` | 544,516 | 895 | As above. |
| `simulation_message_isochrones_runs`, `_users` | 73, 0 | 0 | As above. |
| `transport_postcode_classification` | 2,649,890 | 670 | One reference, in `iznik-routing-go/cmd/rippleextract/main.go:175`, an offline tool. |
| `transport_duration_model`, `transport_mode_probabilities` | 120, 120 | 0 | Same project. |
| `logs_api` | 0 | 557 free | Only `PurgeService` references `logs_errors` and `logs_sql`; nothing writes any of `logs_api`, `logs_events`, `logs_sql`, `logs_errors`. |
| `users_schedules` | 75,418 | 95 | 0 code refs. |
| `users_dashboard` | 435 | 32 | 0 code refs. `data longtext`. |
| `predictions` | 138,229 | 22 | 0 code refs; still updated daily by something outside the repos. |
| `locations_grids`, `locations_grids_touches` | 22,997, 181,695 | 19 | 0 code refs. `locations.gridid` index also unused. |
| `messages_deadlines`, `items_non`, `messages_attachments_recognise`, `rippling_reach_overflow`, `mod_bulkops_run`, `polls`, `polls_users`, `ebay_favourites`, `vouchers`, `groups_twitter`, `supporters`, `newsletters_articles`, `newsletters_images`, `noticeboards_images`, `plugin` (40 MB free), `returnpath_seedlist`, `spatial_ref_sys`, `changes`, `domains`, `partnerships_years` | small | about 60 | Each has either no code reference or only a GDPR-export read (`userdump/collect_db.go`). |

Not dead, despite never being opened: `helper_*`, `lockdown_*`, `firstreply_prompts_sent`, `item_desirability`, `messages_desirability`, `messages_bulk_*`, `rippling_hotspots`, `rippling_live_metrics`, `rippling_params`, `chat_prompts`, `merges`, `locations_dodgy`, `noticeboards_checks`, `partners_messages`. These are new or rare features with live code paths.

`mysql.innodb_index_stats` still holds rows for `memberships_yahoo` and three `#sql2-*` names: leftovers from a dropped table and interrupted ALTERs. Harmless; `DELETE` them from the stats table when convenient.

### 1.6 `email_tracking_images`: a 2.85 GB index with no reads

- 61 M rows (30-day retention; `email_tracking` oldest row 2026-09-19), 4,024 MB data, 2,848 MB index, all of it `email_tracking_id`. Reads on that index: 0. Writes: 30.8 M in 17 days (1.8 M a day).
- The July review kept it as "CASCADE FK, structurally required". The purge in `PurgeService` (line 673) deletes parents by age and relies on the cascade for children. The new `email_tracking_journal` (2026-10-08) deliberately has no FK and no secondary index for exactly this cost, and its migration comment explains why.
- Suggestion: drop the FK and the index; purge `email_tracking_images` directly by `id` range (ids are monotonic, so "older than 30 days" is "id below the first id of that day"). Also `image_position varchar(50)` holds values like `a1`, `i2`, `0/i` (sample of 20,000); `varchar(8)` fits. Longer term this table is the best partitioning candidate in the database: `PARTITION BY RANGE (id)` by day, purge by `DROP PARTITION`. Partitioned InnoDB tables cannot have FKs, which the drop above satisfies.

### 1.7 `logs`: 45 M rows, 6.1 GB, 69% older than 2024

- `COUNT(*) WHERE timestamp < '2024-01-01'` over the `timestamp` index: 31,097,724 of 44,954,418 rows. Oldest row 2016-02-04.
- Composition (20,000 most recent): 59% `Message/Deleted`, 17% `Message/Autoreposted`, 10% `Message/Outcome`, 6% `Group/Joined`. `configid`, `bulkopid` are NULL in 100% of the sample; `stdmsgid` in 99.95%. `text mediumtext` averages 25 bytes.
- The existing purge (`PurgeService.php:736` onward) only trims `User/Login`, `User/Logout`, `User/Deleted`, `User/Created`, `User/Bounce`, `Plugin` and per-group ranges.
- Suggestion: a two-year retention for `Message/*` and `Group/*` rows that are not moderation actions, chunked as now. About 4 GB. `logs` is `ROW_FORMAT=COMPRESSED`, so dropping the three dead columns would be a full rebuild; leave them until the table is next rebuilt. Partition by `RANGE (id)` by year if purges keep hurting.

### 1.8 `rippling_reach`: 7.4 GB for 47,000 rows

- 47,141 rows (6,904 expanding, 40,074 done, 138 held, 25 stopped), 7,408 MB data, 221 MB free. About 157 KB a row.
- Per-row blob sizes over three 1,500-row ranges: `overflow_cells` JSON averages 36 KB (max 104 KB), `polygon_cells mediumblob` 8 to 10 KB, `outer_bound geometry` 3 to 20 KB, `inner_bound` 4 KB, `reach_labels` 2 KB, `schedule longtext` 1.4 to 3.8 KB. Done rows keep all of these. The sum is 55 to 70 KB a row, so about half the tablespace is overhead from repeated off-page blob updates (476,091 updates in 17 days on 47,000 rows).
- `ROW_FORMAT=DYNAMIC`, `utf8mb4_0900_ai_ci`. JSON cell lists compress very well.
- Query: `SELECT msgid FROM rippling_reach WHERE status != ? AND NOT (reach_labels IS NOT NULL AND polygon_cells IS NULL)` ran 13,827 times, 548 ms each, 7,581 s total, every call a full scan that reads the blobs (`iznik-batch/app/Services/Ripple/ExpandService.php:360` is the `status != 'rejected'` pattern; note `rejected` is not even an ENUM value, so the predicate matches every row). `sys.schema_tables_with_full_table_scans` puts `rippling_reach` at 17.9 hours of scan latency in 17 days, the highest of any table.
- Suggestion: (a) change the query to `status IN ('expanding','held')` so the `status` index applies; (b) null `overflow_cells`, `polygon_cells`, `schedule` once a row is `done`; (c) convert to `ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8` on the next rebuild, as `messages`, `chat_messages` and `logs` already are. `rippling_reach_status_index` is redundant with `rippling_reach_maxreach_candidates (status, has_max_reach, updated_at)` and `rippling_reach_created_freeglers` and `rippling_reach_density_created` have zero reads.

### 1.9 `stats`: a silent ENUM bug and 11 years of daily rows

- `StatsGenerationService.php:49` defines `TYPE_OUR_POSTING_BREAKDOWN = 'OurPostingBreakdown'` and writes it at line 690. The `type` ENUM has no such value, and `sql_mode` is `NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` (not strict), so MySQL stores `''`. `COUNT(*) WHERE type = ''` over the `type` index: 1,805,713 rows. Any reader asking for `OurPostingBreakdown` gets nothing. (`locations.type` has 5 such rows too.)
- 20.3 M rows, 1,512 MB data, 2,099 MB index, since 2015-08-24. Three single-column indexes `date`, `groupid` (cardinality 21), `type`. The read pattern is `WHERE type = ? AND groupid IN (...) AND date BETWEEN` (digest with 364 calls, 300 ms each).
- Suggestion: `ALTER TABLE stats MODIFY type ENUM(..., 'OurPostingBreakdown')` (adding at the end is INSTANT) and fix the 1.8 M rows; replace the three indexes with `(type, groupid, date)`; roll pre-2020 daily rows up to monthly. Then consider `STRICT_TRANS_TABLES` cluster-wide once the code has been checked against it; this bug is the argument for it.

### 1.10 `users_searches` and `search_history`: never pruned

- `users_searches`: 27.96 M rows, 1,813 MB data, 503 MB index, 621 MB free, since 2017-05-10. In 10,000 recent rows `maxmsg` and `locationid` are NULL in all, `deleted` set in 182. `iznik-server-go/message/searchmatches.go:15` says in its own words that the table is never pruned and that 99% of it is dead weight for matching. The `userid_2` index serves the profile's "most recent 100" (820 calls).
- `search_history`: 6.78 M rows, 723 MB, since 2016-06-27; `userid` has no FK and no index; `locationid` index has 0 reads.
- Suggestion: purge both to 12 months (chunked, by `id` range), drop `users_searches.maxmsg` and `locationid` (INSTANT on a DYNAMIC table), drop `search_history.locationid` index. About 2 GB.

### 1.11 Other retention and size notes

- `users_logins`: 1.46 M rows, 600 MB; 861,898 rows have not been used for a year, and the `Link` type (magic links) is 84% of recent rows. `credentials2`, `credentialsrotated` are NULL in 100% of a 5,000-row sample. Purge `Link` rows after 90 days.
- `messages_postings`: 10.3 M rows, 1.1 GB, since 2016, 4.3 rows per message; `groupid` index 0 reads, cardinality 21.
- `memberships_history`: 7.1 M rows, 1.1 GB since 2016; `groupid` index 0 reads.
- `users_notifications`: 6.3 M rows, 2.3 GB since 2017; readers only ask `WHERE touser = ? AND timestamp >= ?`. A 12-month purge would cut most of it.
- `engage`: 5 M rows, 658 MB since 2020; `userid_2 (userid, mailid)` 0 reads, `userid` redundant with it.
- `messages`: `message longtext` holds the raw MIME for email-sourced posts (80% of recent posts, average 4.3 KB, max 100 KB) and a duplicate of `textbody` for platform posts. It is read only by moderators (`iznik-server-go/message/message.go:568`, `if isMod`). `htmlbody` is NULL in 5,000 of 5,000 recent rows and only ever written as NULL. Null `message` for email-sourced posts older than 90 days and drop `htmlbody` on the next rebuild (COMPRESSED table, so not INSTANT).
- `groups.poly` and `groups.polyofficial` are WKT `longtext` copies (average 4.7 KB, max 159 KB) of `polyindex geometry`. `groups.settings longtext` averages 2 KB of JSON.
- `groups_images` still holds inline image data: 1,798 of 2,249 rows, 69 KB average, about 124 MB. `messages_attachments`, `users_images` and `chat_images` have all been moved out (data NULL in every sampled row, old and new), so the `data longblob` columns on those three can go on the next rebuild. `image_store_migration` has 13 rows and no code references.
- `email_tracking.metadata json` averages 377 bytes on 7.8 M rows: about 2.9 GB of the table's 3 GB. It is on a 30-day purge, so this is steady state rather than growth, but worth trimming if the metadata is reproducible.
- Free space: 12.6 GB total. `OPTIMIZE TABLE` on `logs_emails` (1,682 MB free on 136 MB data), `chat_roster` (1,657 MB), `messages` (1,511 MB), `messages_attachments` (873 MB), `users_active` (735 MB on 89 MB data), `admins_users` (515 MB on 22 MB), `logs_api` (557 MB, empty), `cron_job_status` (323 MB on 1 MB), `users_nearby` (250 MB, 9 rows). Each is a rebuild; the small ones take seconds.

## 2. Indexes

### 2.1 Unused (zero reads on the bulk-read node since uptime)

`sys.schema_unused_indexes` lists 195. Those on tables above 100 MB, not already covered: `chat_messages.scheduleid` (column NULL in 10,000 of 10,000 recent rows), `chat_messages.imageid` (50 reads), `chat_messages.refchatid` (357), `chat_messages.reviewedby` (68); `messages.date`, `messages.heldby` (column NULL in all 5,000 recent rows); `locations.gridid`, `postcodeid`, `lat`, `lng`; `users.lastrelevantcheck` (972,829 non-null rows, 3 code references), `users.ljuserid` (884 rows); `users_logins.validated`; `users_active.timestamp` (the purge needs it, keep); `memberships_history.groupid`; `messages_postings.groupid`; `engage.userid_2`; `isochrones.locationid_2` and `userid_2` (both redundant copies of `locationid`); `jobs.bodyhash`, `city`, `geometry`, `canonical_title`, `job_reference` (the `jobs` table is rebuilt daily by `WhatJobsService::swapTables`, and the member-facing jobs API reads on the write node, so check there); `users_images.hash`, `groups_images.hash` and the other image `hash` indexes; `shortlink_clicks.shortlinkid`; `search_history.locationid`; `microactions.item1`, `item2`, `rotatedimage`; `rippling_proximity.rippling_proximity_groupid_index`; `logs_src.date`, `src`; `users_push_notifications.type`; `messages_outcomes.timestamp_3`.

Each of these on a `chat_messages`-sized table is roughly 0.8 to 1 GB. Confirm on the write node before dropping, as the July migration did.

### 2.2 Redundant (left-prefix duplicates, from `sys.schema_redundant_indexes`)

71 pairs. The ones on tables that matter: `memberships.groupid (groupid, collection)` inside `memberships_groupid_collection_emailfrequency`; `memberships.configid` inside `idx_configid_role_groupid`; `messages_groups.messageid (msgid, groupid, collection, arrival)` beside the unique `msgid (msgid, groupid)` (the four-column one had 65,435 reads against 130 billion on the unique; keep whichever the digest-mail query needs and drop the other); `messages_outcomes.timestamp` inside `timestamp_2`; `messages_outcomes_intended` has four overlapping `msgid` indexes; `isochrones.locationid` is covered twice; `users_aboutme.userid` inside `userid_2`; `engage.userid`; `ratings.rater`; `users_banned.userid`; `trysts.arrangedfor`; `stats_outcomes.groupid`; `shortlink_clicks.shortlinkid`; and seven tables where a plain `id` unique index duplicates the PRIMARY KEY (`groups`, `logs_sql`, `messages_history`, `mod_configs`, `spam_countries`, `spam_whitelist_ips`, `spam_whitelist_subjects`, `supporters`, `users_stories_requested`). `authorities.name_2` duplicates `name`.

Dropping a redundant left-prefix index is always safe for reads; `DROP INDEX` is INPLACE.

### 2.3 Low-cardinality single-column indexes on big tables

Cardinality under 0.1% of rows: `logs.type (type, subtype)` and `logs.group` on 45 M rows (both heavily read, 859 M and 564 M, so they are serving `WHERE type = ? AND user = ?` style queries where a composite with `user` or `timestamp` leading would be smaller and faster); `memberships.collection`, `role`, `heldby`, `reviewrequestedat`; `messages.type`; `messages_groups.collection`, `deleted`, `messages_groups_heldby_idx`; `chat_messages.processingrequired`, `reviewrequired`; `email_tracking.email_type`, `groupid`; `users.systemrole`; `users_emails.validatekey`; `items.popularity`; `transport_postcode_classification` (four such, table is dead). These are each worth one review: a flag index is useful only when the flagged rows are rare (`reviewrequired` yes; `collection = 'Approved'` no).

### 2.4 Missing indexes and queries the schema could help

From `performance_schema.events_statements_summary_by_digest` on the bulk-read node (17.5 days):

| Query | Calls | Total | Per call | What is wrong | Where |
|---|---|---|---|---|---|
| Chat-room list with `JSON_EXTRACT(u1.settings, '$.useprofile')` on both users and six correlated `chat_messages` subqueries. | 1,463,067 | 118,683 s (33 h) | 81 ms, 3,700 rows | Largest consumer on the node. `users.settings` is `mediumtext`, parsed as JSON per row per call. | `iznik-server-go/chat/chatroom.go:1217` |
| `SELECT msgid, groupid FROM messages_groups WHERE msgid IN (...) AND collection = ? AND deleted = 0` | 21,616 | 45,058 s | 2.1 s, 200,000 rows sent | The embedding store asks for the group of every candidate at once. Filter candidates first, or join inside the main query. | `iznik-server-go/embedding/store.go:156` |
| `SELECT COUNT(*) FROM jobs WHERE visible = ? AND cpc >= ? AND geometry IS NOT NULL` | 2,466 | 19,010 s | 7.7 s, 1.4 M rows | Full scan despite the new `visible_cpc` index (`visible = 1` matches most rows). Not found in the repos; probably a monitoring script. Replace with a counter maintained by `WhatJobsService`. | (outside repo) |
| `rippling_reach WHERE status != ? ...` | 13,827 | 7,581 s | 548 ms | See 1.8. | `ExpandService.php:360` |
| National volunteering list: `volunteering LEFT JOIN volunteering_groups ... WHERE volunteering_groups.groupid IS NULL ...` | 567,247 | 10,096 s | 18 ms, 16,600 rows, 0 sent | Anti-join over the whole table on every list call. A stored `national tinyint(1)` with an index, or caching the ids. | `iznik-server-go/volunteering/volunteering.go:131` |
| `messages_embeddings me JOIN messages_spatial ms WHERE ms.successful = ? AND ms.promised = ?` | 15,868 | 6,394 s | 403 ms, 72,000 rows | Drives from the 1.2 GB embeddings table instead of the spatial index. | `iznik-server-go/embedding/store.go:94` |
| `giftaid WHERE reviewed IS NULL AND deleted IS NULL AND period != ?` | 146,327 | 404 s | 5,800 rows | Small table, but 146,000 full scans; `(reviewed, deleted)` index. | `iznik-server-go/session/session.go:1531` |
| `partners_keys WHERE key = ?` | 632,813 | 184 s | 13 rows | No index on `key`; trivial, add UNIQUE. | `iznik-server-go/spammers/spammers.go:35` |

`sys.schema_tables_with_full_table_scans` also shows `users` (39 billion rows scanned, 16 hours), `rippling_reach_notified` (31.8 billion, 5.6 hours), `users_digests` (21.8 billion), `groups_digests` (11.2 billion), `newsfeed` (4 billion, 4 hours) and `users_searches.PRIMARY` with 34 billion index reads. None of these totals is explained by a single digest, so they are the sum of many batch statements; worth a pass with the slow log on that node.

## 3. Dead and legacy columns

Columns with zero references in `iznik-server-go`, `iznik-batch/app` and `iznik-nuxt3` (tests and migrations excluded), and NULL or constant on live:

- `users`: `yahooUserId` (0 of 10,000 recent; 1 ref), `lastyahoomembersync`, `lastyahoomessagesync`, `covidconfirmed`, `suspectcount` (0 refs), `confirmkey`, `licenseduntil` (groups). `yahooid` is still set on 820,465 historic accounts and has a UNIQUE index with 15 reads; `gotrealemail`, `licenses`, `suspectreason`, `onholidaytill`, `publishconsent`, `lastidlechaseup`, `replyambit` are zero in all 10,000 recent rows. `lastrelevantcheck` is set on 972,829 rows but its index has 0 reads.
- `groups`: `onyahoo` 0 of 507, `type set(...)` is `Freegle` on all 507 rows and has an index, `external` NULL on all, `licenserequired` on 6, `precovidmoderated` (1 ref), `seekingmods` (1 ref), `activitypercent` (1 ref), `legacyid` on 321 (2 refs, indexed, 0 reads).
- `messages_groups`: `senttoyahoo`, `yahoopendingid`, `yahooapprovedid`, `yahooapprove varchar(255)`, `yahooreject varchar(255)` all zero or NULL in 10,000 recent rows, 1 ref each. `spamtype` NULL in all.
- `messages`: `heldby` NULL in 5,000 of 5,000 (index has 0 reads), `suggestedsubject`, `htmlbody`, `retrycount`, `retrylastfailure`, `lastroute` all empty; `replyto` NULL in all (23 refs, so check before dropping).
- `chat_messages`: `norfolkmsgid` (0 refs, NULL), `scheduleid` (NULL, indexed), `confirmrequired` (0), `spamscore` (2 of 10,000), `processingfailreason` (0).
- `chat_rooms`: `name`, `description`, `synctofacebook`, `synctofacebookgroupid` all NULL or default in 10,000 recent rows; `msgvalid`/`msginvalid` are counters nobody reads (4 refs).
- `memberships`: `syncdelete` 0 in all, `settings mediumtext` NULL in all 10,000, `ourPostingStatus` set on 54 (Yahoo posting status; 19 refs, mostly ModTools), `volunteeringallowed bigint NOT NULL` is a boolean.
- `locations`: `newareaid` (0 refs), `osm_amenity`, `osm_shop`, `osm_id` zero in all recent rows; `popularity bigint` zero.
- `logs`: `configid`, `bulkopid` NULL in 20,000 of 20,000.
- `users_logins`: `credentials2`, `credentialsrotated`, `salt` (set only on 469 `Native` rows).
- `users_searches`: `maxmsg`, `locationid`.
- `email_tracking`: `delivered_at`, `bounced_at`, `bounce_type`, `unsubscribed_at`, `scroll_depth_percent` (0 or 1 of 10,000 since the journal change), `clicked_link varchar(500)`.
- `messages_likes.pageview` is NULL on 10 of 236 sampled rows and `count` is 1 on 76%, so both are live.

Dropping a column is INSTANT on `ROW_FORMAT=DYNAMIC` tables in 8.0.29 and later and a full rebuild on `COMPRESSED` ones (`messages`, `chat_messages`, `logs`, `messages_attachments`). Group the drops per table and do the COMPRESSED ones only when the table is rebuilt for another reason.

## 4. Naming and type conventions

Two conventions coexist. The legacy tables (239, `utf8mb4_unicode_ci`) use squashed lower-case names (`userid`, `msgid`, `fromuser`, `lastaccess`) and `timestamp`/`date`/`added`/`arrival` for times; tables added since 2025 (35, `utf8mb4_0900_ai_ci`) use Laravel snake_case (`user_id`, `created_at`, `updated_at`). Counts from `information_schema.COLUMNS`:

- Owner column: `userid` 94, `fromuser` 5, `user1`/`user2` 5 each, `byuser` 4, `touser` 3, `createdby` 3, `modid` 2, `user_id` 1, `rater`/`ratee`, and `logs.user` next to `logs.byuser`.
- Post column: `msgid` 55, `messageid` 4 (`messages.messageid` is the RFC Message-ID header, a varchar; `messages_history.message-id` has a hyphen), `group_id` 1 against `groupid` 39.
- Time column: `timestamp` 56, `date` 38, `created_at` 37, `updated_at` 25, `added` 15, `created` 14, `arrival` 9, `updated` 5, `modified` 5. Precision: only `messages_groups.arrival` and `groups_digests.msgdate` are `timestamp(6)`; `email_tracking.replied_at` and `jobs.posted_at` are `datetime` rather than `timestamp`.
- Case: `memberships.ourPostingStatus`, `users.yahooUserId`, `users_donations.Payer`, `PayerDisplayName`, `TransactionID`, `GrossAmount`, `TransactionType`, `messages_deadlines.FOP`, `messages_isochrones.activeUsers`; views `VW_*` and `MB_messages`.
- Booleans: 139 `tinyint(1)`, plus plain `tinyint` flags (`users.gotrealemail`, `newslettersallowed`, `publishconsent`; `groups.onhere`, `publish`, `onmap`), a `bigint` flag (`memberships.volunteeringallowed`), `enum('1','2')` (`messages_bulk_outreach.tier`), and `set` for `users.systemrole`, `users.permissions`, `groups.type`.
- Coordinates: `decimal(10,6)` in `messages`, `groups`, `locations`, `users_approxlocs`; `double` in `rippling_reach`, `rippling_held_replies`, `community_news_areas`, `firstreply_passthroughs`.
- Collation: 659 columns `utf8mb4_unicode_ci`, 97 `utf8mb4_0900_ai_ci`, one `ascii_bin`, and `groups_digests` is `latin1_swedish_ci`. A join or comparison between string columns of different collations cannot use an index. The string columns in the 0900 tables are statuses and keys compared with literals, not joined, so nothing is broken today; the risk is the next table that joins a 0900 `varchar` to a unicode one.
- `bigint` ids are justified: `chat_roster` and `users_expected` auto-increment counters are past 18 billion because `INSERT ... ON DUPLICATE KEY UPDATE` under Galera's auto-increment offset burns ids; an `INT` would have overflowed. Do not shrink id columns. Non-id `bigint`s are few and small.
- `varchar(255)` appears 170 times; the over-wide ones are `email_tracking.subject` and `clicked_link varchar(500)`, `audits.user_agent varchar(1023)`, `users_images.url varchar(1024)`, `messages_attachments.externalurl varchar(2048)`. Cosmetic.

Suggestion: write the convention down once (new tables: snake_case, `id`, `created_at`, `utf8mb4_0900_ai_ci`, `tinyint(1)` for flags, `decimal(10,6)` for coordinates, `timestamp`) and leave the legacy names alone; renaming 94 `userid` columns is all risk and no payoff.

## 5. Normalisation

- Relations in text: `community_news_areas.groupids longtext` (comma list), `messages_edits.olditems`/`newitems`/`oldimages`/`newimages text`, `messages_promises.checkins json`, `rippling_reach.reachable_group_ids json` and `rejected_groups json`, `groups.rules json`, `groups.settings` and `users.settings` as JSON in `longtext`/`mediumtext`. The one that costs is `users.settings`: the chat-room list parses it with `JSON_EXTRACT` 1.46 M times (section 2.4). A stored generated column `useprofile tinyint(1) AS (JSON_EXTRACT(settings, '$.useprofile'))` on `users` would make that a plain column read. Note `users.settings` is declared `mediumtext`, not `json`, so no validation on write.
- Duplicated data: `groups.poly`/`polyofficial` beside `polyindex`; `messages.message` beside `textbody` for platform posts; `users_aboutme` history beside `audits`; `messages_likes` beside `email_tracking` for digest "seen".
- Counters that can drift: `groups.membercount`, `modcount`, `activemodcount`, `activeownercount`, `backupownersactive`, `backupmodsactive`; `chat_rooms.msgvalid`, `msginvalid`; `users.suspectcount`; `items.popularity`; `locations.popularity`. They are recomputed by batch jobs, which is the right pattern; the two `chat_rooms` ones and `users.suspectcount` have no readers.
- Polymorphic: `logs` fans out over `byuser`, `user`, `groupid`, `msgid`, `configid`, `stdmsgid`, `bulkopid`, all nullable, none with an FK; `audits.auditable_type`/`auditable_id` (package design).
- Misnamed: `messages_likes` is a page-view log (section 1.2).
- Tables that could merge: `messages_outcomes_intended` into `messages_outcomes` with a flag (it has four overlapping indexes for 4,331 rows); the five `*_images` tables share one shape and could be one table with a `kind` column, though the `data longblob` columns should go first.

## 6. Integrity

- Foreign keys: 289 (230 `CASCADE`, 59 `SET NULL`). No type mismatches between FK columns and their targets. No `SET NULL` rule on a `NOT NULL` column.
- Id-like columns on big tables with no FK: all of `logs.*`, `users_searches.userid`/`locationid`, `search_history.userid`, `lovejunk.msgid`, `messages.editedby`, `newsfeed_users.newsfeedid`, `rippling_reply_attribution.*`, `eee_classified_attachments.attid`. For high-write log tables that is deliberate (the `email_tracking_journal` migration documents the parent-row lock cost of an FK insert). `messages.editedby` and `newsfeed_users.newsfeedid` could carry `SET NULL`/`CASCADE` FKs at low cost.
- 80 tables cascade on `users` delete, including `messages_likes` (120 M rows) and `chat_messages`. The application soft-deletes users (`users.deleted`), so a hard delete is rare, but one would produce a very large Galera write-set. Keep hard deletes chunked (as `UserManagementService` does).
- No declared primary key (19): `communityevents_groups`, `groups_twitter`, `locations_grids_touches`, `locations_spatial` (6.5 M rows, 1.4 GB), `messages_deadlines`, `messages_groups` (11 M rows, 3.7 GB), `messages_items` (5.4 M, 533 MB), `messages_likes` (120 M, 24 GB), `mod_bulkops`, `mod_stdmsgs`, `newsfeed_likes`, `newsfeed_reports`, `polls_users`, `sessions`, `spatial_ref_sys`, `users_banned`, `users_nearby`, `users_stories_likes`, `volunteering_groups`. `pxc_strict_mode` is `ENFORCING`, which only lets these through because each has a NOT NULL UNIQUE key that InnoDB and the strict check treat as the primary key. Declaring the key as PRIMARY is a rebuild (INPLACE, concurrent DML allowed, but TOI blocks the cluster for the duration), so do it per table when each is rebuilt for another reason; `messages_groups` and `messages_likes` need pt-online-schema-change or a window.
- No `id` column (33), against the house convention: the 19 above plus `cache`, `cache_locks`, `chat_prompts`, `chat_room_redirects`, `eee_classified_attachments`, `firstreply_event_metrics`, `messages_embeddings`, `messages_matched_notified`, `messages_pinned`, `rippling_proximity`, `rippling_proximity_checked`, `rippling_reach`, `rippling_reach_notified`, `rippling_reach_overflow`, `rippling_reply_attribution`, `users_searches_embeddings`, `weights`. The newer ones (`rippling_*`, `messages_embeddings`) have a sensible natural PK, so this is a convention gap rather than a Galera problem.
- ENUM hygiene: `stats.type` and `locations.type` hold `''` (section 1.9); `messages_groups.collection` still lists `QueuedYahooUser`; `logs.subtype` has 48 values and `chat_messages.reportreason` 28, which is where a lookup table starts to pay; `users.newsfeedmodstatus` includes `''` as a legal value. `rippling_reach.status` is checked against `'rejected'`, which is not a value.
- Nullable columns that should not be: `messages.type`, `messages.date`, `messages_groups.collection`, `logs.type`, `chat_rooms.created` are all NULL-able and never NULL in samples.

## 7. Engine, row format, Galera

- All InnoDB, `innodb_file_per_table`, page size 16 KB, default row format DYNAMIC. 18 tables `COMPRESSED` (the big text ones: `chat_messages` 10.7 GB, `messages` 6.2 GB, `logs` 6.1 GB, `paf_addresses` 5.4 GB, `messages_attachments` 4.2 GB), 7 still `COMPACT` (`messages_items` 533 MB, `messages_promises` 244 MB, `alerts_tracking`, `communityevents_dates`, `communityevents_groups`, `groups_digests`, `logs_errors`): convert to DYNAMIC on the next rebuild. `rippling_reach` (7.4 GB of JSON and cell blobs) and `audits` are the two DYNAMIC tables that would compress well.
- `information_schema.INNODB_TABLESTATS` reports `chat_messages` at 96.8 GB clustered plus 73.9 GB secondary, against 6 GB plus 4.6 GB in `information_schema.TABLES`; the first figure counts uncompressed pages and is wrong for a COMPRESSED table. Do not size from it.
- Partitioning candidates (all purge by age, all keyed by a monotonic `id`): `email_tracking_images` (daily), `logs` (yearly), `users_active` (monthly), `users_notifications`. FKs must be removed first; `email_tracking` has two `SET NULL` FKs to `users` and `groups` that would have to go if it were partitioned, so start with `email_tracking_images`.
- Galera-specific: no-PK tables (above); the purge jobs already delete in `LIMIT` chunks, which is right; `jobs` is swapped daily with `RENAME TABLE`, fine under TOI; `UPDATE sessions SET lastactive` and `UPDATE users SET lastaccess` are throttled to once per interval, which is right. The `messages_likes` `INSERT ... ON DUPLICATE KEY UPDATE` is 12 M writes in 17 days onto a 24 GB table with a 7 GB unread index; dropping that index is the single biggest reduction in replicated write work available.

## 8. Suggested order

1. Confirm the unread indexes on the write node (same method as July). Drop `messages_likes_source_ts_user`, `email_tracking_images.email_tracking_id` with its FK, and the redundant left-prefix indexes. INPLACE, no rebuilds.
2. Retention: `messages_likes` 90 days, `logs` two years for message and group noise, `users_searches` and `search_history` 12 months, `users_logins` links 90 days, `audits` 90 days with the noisy models excluded, `users_aboutme` latest row only.
3. Drops: `bounces` (after finding the writer), the simulation and transport sets, the small dead tables.
4. Fix the `stats` ENUM and the `rippling_reach` status query; add the `partners_keys` and `giftaid` indexes.
5. Rebuild-time items, one table per quiet window: `OPTIMIZE` the fragmented tables, `COMPRESSED` for `rippling_reach`, declare primary keys, drop dead columns on COMPRESSED tables, convert the COMPACT tables.
6. Code-side query work from section 2.4, starting with the chat-room list.

## 9. Not checked

- Index and table reads on the write node and the backup node. Everything "unused" here is unused on the bulk-read node only.
- What writes `bounces` and `predictions`, and what issues the `jobs` count: none is in the monorepo.
- Exact per-index sizes on most tables: `mysql.innodb_index_stats` has `size` rows for only 156 indexes (none on `messages_likes`, `chat_messages`, `logs`), so the 7 GB figure for `messages_likes_source_ts_user` is estimated from column widths against the table's 14 GB of secondary index.
- Whether the 39 billion `users` rows and 34 billion `users_searches` PK reads in the full-scan view come from one job or many; no single digest accounts for them.
- Slow-log data; only `performance_schema` digests since 2026-10-01 were available.
- The legacy PHP server is not in this monorepo and was not searched.
