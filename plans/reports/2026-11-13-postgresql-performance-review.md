# PostgreSQL port: adversarial performance review, 2026-11-13

Nine reviewers each attacked one area of the port for places where PostgreSQL would perform worse than MySQL, or differently enough to matter. Every finding was then given to a second reviewer whose job was to refute it with a query plan.

## What it was measured on

A scratch PostgreSQL database, `iznik_perf`, with the port's schema and synthetic rows at about one third of production size (scale 1 : 3.26, from row counts read on production on 2026-11-13). Plan shapes carry over to full size; absolute costs do not. MySQL was not measured at the same scale: its side is reasoned from the existing hints and indexes.

Limits of the data, from the reviewer who built it:

SERVER SETTINGS ARE DEFAULTS, NOT PRODUCTION
- shared_buffers 128MB, work_mem 4MB, max_wal_size 1GB. Buffer hit/read splits and sort or hash spills in EXPLAIN reflect that.
- The container's /dev/shm is 64 MB. A parallel hash join with a large work_mem fails with "could not resize shared memory segment ... No space left on device". Use SET max_parallel_workers_per_gather = 0 if you raise work_mem.

DATA THAT IS NOT REALISTIC
- locations_spatial is 2.0M, not the 2.76M asked for. It has a unique foreign key on locationid, so it cannot exceed locations (2.0M). The production estimates (9.0M against 6.5M) contradict each other.
- The 200k locations added (ids above 1.8M) are not used by any member or post. paf_addresses does reference some of them.
- messages rows are about 850 bytes with no htmlbody. Real `message` holds a full email source and would be TOASTed; here it is not.
- Text is low-cardinality and repetitive.
  - Subjects come from 720 combinations; users_searches.term from about 5,500.
  - messages_items uses only the original 720 items, uniformly (0.15% each). The other 1.1M items are an unused tail.
  - Text indexes (messages.subject, locations.name, users names) therefore have far fewer distinct values than production. LIKE and full-text behaviour is not representative.
- Geography is 500 rectangular catchments at hashed centres, uniform over the box, so points fall in the sea.
  - The nations are rectangles: 'England', 'Wales', 'Scotland' and 'Northern Ireland' partition the box, with 'United Kingdom' over all.
  - Authorities are a regular grid of near-rectangles with dense edges, not nested real boundaries.
  - Isochrones and reach bounds are circles.
- Chat: the largest room has 264 messages; production Mod2Mod and busy rooms are far larger. Room sizes come from a simple rule (a third of messages go to every tenth room).
- messages_likes viewers are picked by an arithmetic stride, so each member's views do not cluster in time the way real browsing does.
- rippling_reach blobs are incompressible noise. If the real label blobs compress, production rows are smaller on disk than their logical size, and this model overstates TOAST I/O.
- rippling_reach_notified covers only current reach posts (recent ones); rippling_reach_leaves uses a synthetic cell number.
- Dates line up only roughly across the two time curves. A chat message's refmsgid and a log's msgid point at posts from about the same days, not exactly.
- users_logins is 1.0M (true ratio would be 340k). users.lastlocation and messages.locationid never point at the new locations.
- memberships_history, engage, audits, background_tasks, email_tracking, stats, users_notifications and the extras are plausible in shape but invented. I had no production distributions for them.
- test-mode.sql defaults are present; production would not have them. No planner effect.

STILL EMPTY (219 tables)
- Tables with production rows that I did not load: bounces (322k, 7.8 GB), jobs (539k), shortlink_clicks (3.0M), messages_embeddings (386k), users_nearby, sessions, and every helper_*, ux_*, messages_bulk_*, firstreply_*, newsfeed_likes and predictions table.
- Small reference tables are populated: config, spam_*, mod_configs, mod_stdmsgs, locations_grids, paf lookups, worrywords.

## Findings, worst first

| # | Severity | Verdict | Area | Finding |
|---|---|---|---|---|
| 1 | outage-risk | confirmed-smaller | functions-on-columns | Prefix LIKE on citext columns cannot use the btree index: the postcode typeahead and WhatJobs geocoding scan all of locations |
| 2 | outage-risk | confirmed | go-hot-paths | Postcode typeahead: prefix LIKE on a citext column cannot use the name index, so every typeahead request scans all of locations |
| 3 | outage-risk | confirmed | go-hot-paths | Statements with ids pasted into the SQL text are a new prepared statement on every request: about 630 kB of backend memory each, 512 kept per connection, and an unbounded translation cache in the Go process |
| 4 | outage-risk | confirmed | schema-conversion | Prefix LIKE can no longer use any btree index (citext or varchar): the postcode typeahead seq-scans all of locations on every keystroke |
| 5 | outage-risk | confirmed | this-session-changes | Prefix LIKE on a citext column cannot use its btree index: the postcode typeahead and the TrashNothing sync scan the whole table |
| 6 | slow-at-scale | confirmed | functions-on-columns | crc32() is a PL/pgSQL bit loop evaluated for every active user on each chunk of the daily digest and daily push shard queries |
| 7 | slow-at-scale | confirmed-smaller | go-hot-paths | With PostgreSQL's default cost settings the mygroups unread badge reads the member's communities' whole posting history on every poll (hash semi-join instead of keyed probes) |
| 8 | slow-at-scale | confirmed-smaller | go-hot-paths | ModTools logs: ORDER BY logs.id DESC LIMIT with a community filter walks the whole logs primary key backwards when the other filters match few rows |
| 9 | slow-at-scale | confirmed | laravel-dialect | Every Laravel `LIKE` is sent as `col::text LIKE ?`, so prefix LIKEs that use an index on MySQL become full scans of users_emails and locations (and turn case-sensitive) |
| 10 | slow-at-scale | confirmed-smaller | schema-conversion | messages_likes loses its InnoDB clustering and its covering secondary index: per-post view sums and per-member viewed lookups become one heap page per row |
| 11 | slow-at-scale | confirmed | spatial | Routing service nearest-postcode lookup seq-scans the whole locations table on PostgreSQL (cast on the bound defeats the lat/lng indexes) |
| 12 | slow-at-scale | confirmed-smaller | translator-rewrites | pgx prepares and caches every distinct statement text per connection: the browse feeds inline the viewer id, group ids and watermark, so each backend fills with about 200 KB per variant (up to about 100 MB a backend, about 20 GB at the 200-connection cap) |
| 13 | slow-at-scale | confirmed-smaller | translator-rewrites | The translator cache is keyed on the full SQL text and never evicts: per-viewer feed texts miss on every call (about 0.5 ms to re-translate) and the map grows for the life of the process |
| 14 | slow-at-scale | confirmed | translator-rewrites | The mygroups feed's inlined group-id list, tuned for MySQL's per-post index probe, is planned as a hash semi-join over the whole Approved history of the member's groups |
| 15 | slow-at-scale | confirmed-smaller | writes-mvcc-locking | Go API opens up to 400 PostgreSQL backends per process against a server that allows 100, and the layer depends on session prepared statements that the planned transaction pooler breaks |
| 16 | worth-measuring | confirmed-smaller | index-hints | ModTools member name/email search: without the FORCE INDEX, PostgreSQL walks the whole memberships primary key backwards for a large community, the exact plan the hint was added to stop |
| 17 | worth-measuring | confirmed-smaller | laravel-dialect | The CRC32 shard predicate calls a PL/pgSQL crc32() per active user, re-evaluated on every 500-row chunk of the daily digest (and of push:daily-posts) |
| 18 | worth-measuring | confirmed-smaller | laravel-dialect | Expiry sweep parses groups.settings as jsonb up to about 20 times per (post, group) row: 23 s of a 32 s statement at one third of production size |
| 19 | worth-measuring | confirmed-smaller | schema-conversion | Hot single-row UPDATEs rewrite every index on the table: users.lastaccess (16 indexes), chat_rooms.latestmessage (11), chat_messages flags (14), messages_likes view bumps |
| 20 | worth-measuring | confirmed-smaller | schema-conversion | ANALYZE under-counts distinct values on the big time-clustered tables (messages_likes.msgid 24x), inflating per-key row estimates and tipping the Browse query into JIT |
| 21 | worth-measuring | confirmed | schema-conversion | Joining a citext column to a varchar column casts the citext side to text: the index on items.name is unusable and the match becomes case-sensitive |
| 22 | worth-measuring | confirmed-smaller | this-session-changes | A citext column compared with a varchar column falls back to case-sensitive text equality: the citext index is unusable and rows that match on MySQL do not match |
| 23 | worth-measuring | confirmed-smaller | translator-rewrites | Mark-seen's ON DUPLICATE KEY UPDATE becomes ON CONFLICT DO UPDATE on the 150M-row messages_likes: 100 bumps wrote 2.4 MB of WAL and dirtied 311 pages |
| 24 | worth-measuring | confirmed-smaller | translator-rewrites | FORCE INDEX is stripped and MAX_EXECUTION_TIME becomes an inert comment: the ModTools member name search goes back to walking the whole memberships primary key, with no time cap |
| 25 | worth-measuring | confirmed-smaller | writes-mvcc-locking | users.lastaccess and other hot single-row updates are never HOT on PostgreSQL: each writes a new row version plus an entry in every index (16 on users) |
| 26 | worth-measuring | confirmed | writes-mvcc-locking | A PostgreSQL deadlock (40P01) is classified as 'not a deadlock' by the Laravel retry code, and the pgsql connection has no statement retry at all |
| 27 | minor | confirmed-smaller | functions-on-columns | GET /donations fails on PostgreSQL: a timestamptz column is compared with DATE_FORMAT() text, the error is not checked, and 'raised' is always 0 |
| 28 | minor | confirmed-smaller | functions-on-columns | Collate(..., '_ci') renders LOWER(x) on PostgreSQL, so the items.name join in the nightly stats is case-sensitive and cannot use items_name |
| 29 | minor | confirmed | functions-on-columns | SUM(condition) runs through a custom sum(boolean) aggregate with a SQL transition function: about 6 times the per-row cost of count(*) FILTER |
| 30 | minor | confirmed-smaller | go-hot-paths | View counts on the browse feed: messages_likes loses InnoDB's clustering by (msgid, userid, type), so each post's views cost about four times the page touches once rows arrive in time order |
| 31 | minor | confirmed-smaller | laravel-dialect | Collate renders `LOWER(bi.name)` against citext items.name: the join becomes a case-sensitive text comparison that cannot use items_name and silently stops matching capitalised item names |
| 32 | minor | confirmed-smaller | spatial | Daily users_approxlocs refresh rewrites every row and adds a GiST entry for each, where MySQL writes nothing for unchanged members |
| 33 | minor | confirmed | spatial | Go translator turns MBRContains into exact ST_Contains, so the degraded reach filter runs the same exact test twice per candidate (Laravel maps it to the bbox operator instead) |
| 34 | minor | confirmed-smaller | this-session-changes | tn:merge-crossposts on PostgreSQL moves rows one savepoint at a time, overflowing the subtransaction cache on any post with more than 64 referencing rows |
| 35 | minor | confirmed-smaller | writes-mvcc-locking | Updates that change nothing are free on MySQL and write a full new row version on PostgreSQL: 'mark all chats read' rewrites every roster row of the member on each press |
| 36 | minor | confirmed-smaller | writes-mvcc-locking | SELECT ... FOR UPDATE on a row that does not exist no longer stops two creators: duplicate User2Mod chat rooms on PostgreSQL |
| 37 | none | refuted | index-hints | Support Tools community list: the 31-day approval counts no longer use (timestamp, type, subtype), and the Autoapproved count could not be measured |
| 38 | none | refuted | index-hints | Email statistics by type and daily series: the sent_at range is used unprompted, but the join to users becomes a hash join over the whole users table |
| 39 | none | refuted | laravel-dialect | groups:update-stats backup-owner/moderator counts: the IN (subquery) arm reads the whole recent approvals index once per group and role, 1,000 times a night |

### 1. Prefix LIKE on citext columns cannot use the btree index: the postcode typeahead and WhatJobs geocoding scan all of locations

- **Severity:** outage-risk (claimed outage-risk). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/location/location.go:388 (worst Laravel site: iznik-batch/app/Services/WhatJobsService.php:1349)
- **How often:** Typeahead: GET /location/typeahead (router.go:780), on each typeahead request from the postcode box. WhatJobs: integrations:sync-whatjobs (routes/console.php:1651), once per distinct outward code per run (cached within a run only). Support search: each ModTools support user search.

**Evidence**

The typeahead part is confirmed as claimed. The WhatJobs cost is about 80 times smaller than claimed, and the support-search part was reasoned, not run, and looks like no regression.

**Code and reach**
- `iznik-server-go/location/location.go:376-392` (`typeaheadQuery`) is real. It serves `GET /location/typeahead` (`router.go:780`) and `GET /locations?typeahead=`.
- The frontend `iznik-nuxt3/components/PostCode.vue` calls it from the postcode box with a 200 ms debounce, so it is hot.
- The translator has no LIKE rewrite and gorm goes through pgx stdlib (extended protocol), so PostgreSQL receives `l1.name LIKE $1 AND l1.type = 'Postcode' AND l1.name LIKE '% %' LIMIT 10`.
- `locations.name` is citext with only `locations_name btree (name)`; the database collation is `en_US.utf8`. MySQL has `KEY name (name)` on a `utf8mb4_unicode_ci` column, so it range-scans.

**Typeahead (confirmed)**
- Prefix `'CA1 5%'` (4 matches) and `'ZZ99 9%'` (0 matches) give the same plan:
  - `Parallel Seq Scan on locations l1  Filter: ((name ~~ 'ZZ99 9%'::citext) AND (name ~~ '% %'::citext) AND (type = 'Postcode'::text))  Rows Removed by Filter: 666667 (x3)  Buffers: shared hit=1 read=48597`
- Prefix `'AB%'` (many matches) stops after 33 buffers, because the LIMIT is met early.
- So any prefix with fewer than 10 matches, which includes every fully typed postcode, reads the whole heap with 3 processes. At 6.5M rows that is roughly 158k buffers per request.

**WhatJobs geocodePostcode (real, but overstated)**
- `WhatJobsService.php:1339-1354` selects AVG as well as MIN/MAX. The claim's 4.1M-buffer figure came from a MIN/MAX-only statement that the code does not send.
- I reproduced that form: four InitPlans, 3.69M hits plus 282k reads. It does not apply here because AVG blocks the MIN/MAX rewrite.
- The actual statement is one scan:
  - `Parallel Seq Scan on locations  Filter: ((lat IS NOT NULL) AND ((name)::text ~~ 'CA1 %'::text) AND (type = 'Postcode'::text))  Rows Removed by Filter: 666656 (x3)  Buffers: shared hit=10 read=48588`
- It runs once per distinct outward code per run, on a 3-hourly batch schedule (`routes/console.php:1651`). That is slow but not dangerous.

**GiftAidClaimService.php:88 (confirmed)**
- `Index Scan using locations_name ... Filter: (((name)::text ~~ 'CA1 2%'::text) AND (type = 'Postcode'::text))  Rows Removed by Filter: 596289  Buffers: shared hit=453320 read=50957`
- I did not check how often this is called.

**Support search, user.go:1321 (not run; probably no regression)**
- The `users_emails` arm is `email LIKE '%q%' OR canon LIKE 'q%' OR backwards LIKE 'q%'`.
- The leading-wildcard branch inside the OR should stop MySQL using an index for that arm too, so it scans `users_emails` on both engines. This is reasoning from the statement; I did not run it.

**Fix trials (both rolled back)**
- Trial 1: `CREATE INDEX ON locations ((lower(name::text)) text_pattern_ops)` (57 MB), with the typeahead predicate rewritten to `lower(l1.name::text) LIKE lower($1)`, run as a prepared statement.
  - The 7th and 8th executions still used a custom plan:
  - `Index Scan using trial_loc_lname  Index Cond: ((lower((name)::text) ~>=~ 'zz99 9'::text) AND (lower((name)::text) ~<~ 'zz99 :'::text))  Buffers: shared hit=3`
  - `'ca1 5%'` took 21 buffers in total.
  - The unchanged Go statement did not use this index (still 48,598 buffers), and neither did the unchanged Laravel `name::text like`.
- Trial 2: `CREATE INDEX ON locations ((name::text) text_pattern_ops)` serves the Laravel statements with no code change.
  - WhatJobs, prepared, 7th execution: `Index Scan using trial_loc_tname  Index Cond: (((name)::text ~>=~ 'AB8 '::text) AND ((name)::text ~<~ 'AB8!'::text))  rows=1024  Buffers: shared hit=1030`
  - GiftAid: Index Scan plus top-N sort, 5 buffers; 3 buffers for a prefix with no match.

**Fix**

Both parts are PostgreSQL-only; MySQL statements stay exactly as they are.

1. **Schema:** add two expression indexes on `locations` to the PostgreSQL schema, as extra indexes in `tools/pg-schema/config.json` / `convert.mjs`:
   - `((lower(name::text)) text_pattern_ops)`
   - `((name::text) text_pattern_ops)`
   - Each was 57 MB here, so roughly 190 MB each in production.
   - The second fixes `WhatJobsService::geocodePostcode` and `GiftAidClaimService` with no code change, because Laravel's vendor PostgresGrammar already emits `name::text like ?`.

2. **Go typeahead:** on PostgreSQL only, render the predicate as `lower(l1.name::text) LIKE lower(?)`. This can be a translator rule for LIKE on known citext columns, or a PostgreSQL branch in `typeaheadQuery`. It must not be sent to MySQL, where `lower(name)` would defeat `KEY name`.

**Alternative:** override `whereBasic` in `JsonTextPostgresGrammar` to emit `lower(col::text) like lower(?)`. Then the `lower()` index alone serves both stacks and restores MySQL's case-insensitive LIKE, at the cost of touching every Laravel LIKE.

**Not needed:**
- No rewrite of `geocodePostcode`: the MIN/MAX InitPlan problem does not arise with AVG in the select list.
- The other listed sites (`users_emails`, `users` names, chat, shortlinks) were not measured. Add indexes for them only after probing each, since several have a leading wildcard or an OR that MySQL cannot index either.

**How the synthetic data could mislead**

- **Table size:** synthetic `locations` is 2.0M rows of about 190 bytes (48.6k pages). Production is 6.5M rows with real geometry columns, so the heap and each seq scan are at least 3.25 times larger, probably more.
- **Postcode shape:** synthetic postcodes are spread uniformly, about 1,000 per outward code, with ids unrelated to name. In production, postcodes were probably loaded in name order, so a short prefix with 10 or more matches may sit far into the heap and scan a long way before the LIMIT stops it. The early-exit case could be worse than the 33 buffers seen here.
- **Type mix:** 90% of synthetic rows are Postcode; the production mix is unknown. That changes only the filter, not the plan shape.
- **Server settings:** the read/hit split reflects `shared_buffers` of 128 MB. On a production-sized cache the cost is CPU across 3 processes per request, not I/O.
- **Not measured:** the typeahead request rate, so the outage rating rests on the endpoint being public and fired on each debounced keystroke.
- **Support search:** not run, and the synthetic name and email text is low-cardinality, so nothing here speaks to that arm.

### 2. Postcode typeahead: prefix LIKE on a citext column cannot use the name index, so every typeahead request scans all of locations

- **Severity:** outage-risk (claimed outage-risk). **Verdict:** confirmed. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/location/location.go:388 (typeaheadQuery; callers at :447 and :570). Schema cause: locations.name is citext (tools/pg-schema/config.json) and the database is created with the image's en_US.utf8 collation (scripts/setup-pg-database.sh:96, bare CREATE DATABASE).
- **How often:** GET /location/typeahead (router/routes.go:780) and /locations?typeahead=: each typeahead request while a member types a postcode in the give, ask, settings and sign-up flows. Member-facing, many requests per postcode entered.

**Evidence**

The claim holds: I reproduced the full scan on iznik_perf, and the proposed fix works when tried in a rolled-back transaction.

**Code and frequency**
- The statement is real: `typeaheadQuery` in `iznik-server-go/location/location.go:376-407` builds `l1.name LIKE ?` (prefix + '%'), `l1.type = 'Postcode'`, `l1.name LIKE '% %'`, with a LIMIT.
- It is reached from `GET /location/typeahead` (`router/routes.go:780`) and from `GET /locations?typeahead=`.
- The frontend calls it from `iznik-nuxt3/components/PostCode.vue:169` with `:min="3"` and `:debounce="200"`, so several requests per postcode typed. It is member-facing and hot.
- The translator has no rule for LIKE on a citext column. Its only LIKE handling (`pgtranslate_more.go:301`) adds `::text` to a string literal that follows a function call, which does not apply here. PostgreSQL receives the same predicate with `$n` placeholders.

**Schema**
- `locations.name` is citext with a plain btree `locations_name`; the database collation is en_US.utf8.
- MySQL has `KEY name (name)`, so `LIKE 'prefix%'` is a range scan there.

**Plans on iznik_perf (2.0M locations), run as a prepared statement with `$1`/`$2` as the driver sends it**
- `'CA1 5%'` (4 matches):
  - `Parallel Seq Scan on locations l1`
  - `Filter: ((name ~~ 'CA1 5%'::text) AND (name ~~ '% %'::citext) AND (type = 'Postcode'::text))`
  - `Rows Removed by Filter: 666665` (x3 processes), `Buffers: shared hit=304 read=48310`
- `'ZZ99 9%'` (no match): the same plan, `Rows Removed by Filter: 666667` x3, 48,598 buffers.
- `'C%'` (many matches): the seq scan stops after 13 buffers. The cost is only paid when fewer than LIMIT rows match, which is the normal state once the member has typed most of a postcode.
- Equality is fine: `Index Scan using locations_name ... Index Cond: (name = 'EM8 0IK'::citext)`, 10 buffers.

At production size (6.5M rows) this is about 158k buffers per request, on up to three processes.

**Sibling sites the claim lists**
- `user/auth.go:84`: `name LIKE 'ZZ9%' AND type='Postcode' LIMIT 1` gave `Seq Scan`, `Rows Removed by Filter: 2000000`, 48,598 buffers.
- `user/user.go:1377`: `l.name LIKE 'EM8 %'` joined to users gave a nested loop driven from `Parallel Index Scan using users_id` (333,333 rows x3), with `Index Scan using locations_pkey ... loops=1000000`. That is 3.9M buffers for 32 rows.
- I did not test the `user.go:1307-1320`, shortlink or domain sites.

**Fix trial (inside BEGIN ... ROLLBACK; `\d locations` afterwards shows the original nine indexes)**
- `CREATE INDEX ON locations (lower(name::text) text_pattern_ops)` is 57 MB.
- With the predicate `lower(l1.name::text) LIKE lower($1)`:
  - `'CA1 5%'`: `Index Scan using locations_name_prefix`, `Index Cond: ((lower((name)::text) ~>=~ 'ca1 5'::text) AND (lower((name)::text) ~<~ 'ca1 6'::text))`, 21 buffers in total including the area join.
  - `'ZZ99 9%'`: 3 buffers.
  - `'em8 0%'` returns the same 8 rows as `'EM8 0%'`, so case folding is preserved.
- The index plan was still chosen on the sixth execution of the prepared statement, so the default plan cache mode keeps custom plans.
- With `plan_cache_mode = force_generic_plan` it falls back to the Parallel Seq Scan (`lower((name)::text) ~~ lower($1)`, 48,598 buffers), because the prefix is not known at plan time.

**Fix**

Two parts, both PostgreSQL-only, so MySQL statements stay exactly as they are.

1. **Index:** add an expression index to the PostgreSQL schema through an extra-index entry in `tools/pg-schema/config.json`, regenerated into `baseline.sql`:
   `CREATE INDEX locations_name_prefix ON locations (lower(name::text) text_pattern_ops);`
2. **Predicate:** make PostgreSQL receive `lower(l1.name::text) LIKE lower($n)` for the prefix arm. Either add a translator rule in `iznik-server-go/database/pgtranslate*.go` for LIKE against a listed citext column, or add a PostgreSQL-only branch in `typeaheadQuery` (and the same at `user/auth.go:84` and `user/user.go:1377`).

- **Schema alone cannot fix it.** citext's `~~` operator has no planner support for btree ranges, and C collation or `text_pattern_ops` on the citext column itself does not help.
- **Generic plans defeat it.** The index is only used when the planner sees the prefix value. The default plan cache mode kept choosing it over six executions, but `force_generic_plan` (or a pooler that pins generic plans) reverts to the seq scan. If that is a concern, send explicit range bounds computed in Go instead: `lower(name::text) >= $lo AND lower(name::text) < $hi`. I did not test that variant.
- **The other prefix arms** the claim names (`users.yahooid`, `users_logins.uid`, `users_emails.canon`) would need their own expression indexes; I did not test those.

**How the synthetic data could mislead**

- **Heap size:** synthetic `locations` rows are narrow (48.6k pages for 2M rows). Real rows carry `geometry` and `ourgeometry`, and polygons under about 2 KB stay inline, so the production heap per row is probably larger and the scan worse than the 3.25x extrapolation.
- **Early exit:** synthetic postcodes are generated by formula and spread evenly through the table by id. Real postcodes may be clustered by load order, so how soon a short prefix such as `LU8` finds 10 rows could differ either way. Any prefix with fewer than LIMIT matches (most of a postcode typed, a typo, a nonexistent prefix) scans everything regardless of data shape.
- **`user.go:1377`:** the synthetic `users.lastlocation` is set on about 95% of users (950,000 index searches). If production has fewer users with a `lastlocation`, the nested loop is proportionally cheaper, but still driven from all of users.
- **Settings:** `shared_buffers` is 128 MB here, so the read/hit split is not meaningful; the buffer totals are.

### 3. Statements with ids pasted into the SQL text are a new prepared statement on every request: about 630 kB of backend memory each, 512 kept per connection, and an unbounded translation cache in the Go process

- **Severity:** outage-risk (claimed outage-risk). **Verdict:** confirmed. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/database/pgconn.go:22 and :59-70 (sync.Map translation cache, never evicted) and database/postgres.go:186-192 (pgx left on its default exec mode). Worst statement: chat/chatroom.go:1206 (idlist concatenated into the chat list detail query).
- **How often:** Chat list (GET /chat and the ModTools chat list) on every chat page load and poll. Browse feed and badge count (GET /message/count, polled about every 60 s per active member). Mygroups feed. These are the busiest member routes.

**Evidence**

The mechanism is real in the code and the measurements reproduce. The "tens of GB" figure is an upper bound, not a measurement.

**Code**
- `iznik-server-go/chat/chatroom.go:1206` builds `idlist` from the member's chat ids and pastes it twice into the text via `ChatRoomListFrom` (`chatroom.go:2267` and `:2269`). It is run with `db.Raw(sql, myid, ...)`, so it has bound arguments and goes through `QueryContext`.
- Routes: `GET /chat` (`router/routes.go:292`) and `GET /chat/rooms` (`:302`). Both are live and member-facing.
- `rippling/pendingreach.go:60` pastes the viewer id. `ReachPendingFilter` is spliced into `isochrone/message.go:723`, `:898`, `:931`, `message/groups.go:126` and `message/bounds.go:269`, so the browse feed, the count (`GET /message/count`) and mygroups each get one text per member.
- `database/postgres.go:64-76` and `:186-192`: the DSN sets only `sslmode` and `TimeZone`, and `pgx.ParseConfig` is passed straight to `stdlib.GetConnector`. Nothing in the repo sets `DefaultQueryExecMode`, `StatementCacheCapacity` or `PreferSimpleProtocol`.
- pgx v5.5.5 source (read in the apiv2 container): `Conn.Query` takes `c.config.DefaultQueryExecMode`, and `getStatementDescription` under `QueryExecModeCacheStatement` does `c.Prepare(ctx, stmtcache.StatementName(sql), sql)` then `statementCache.Put(sd)`. That is a named server-side statement per distinct text. The "always use simple protocol when there are no arguments" shortcut exists only in `exec()`, not in `Query`.
- `database/pgconn.go:22` and `:59-70`: `cache sync.Map` is keyed by the full original SQL, written by `c.cache.Store(q, st)`. A grep for Delete, Range or Clear on it finds nothing, so it is never evicted.
- MySQL: `database/database.go:25` has `interpolateParams=true`, so nothing is kept on the server.

**Measured on iznik_perf**
I hand-translated the chat list statement (3.9 kB of text) and prepared it 100 times with the real chat id lists of users 500000 to 500099, reading `pg_backend_memory_contexts` in the same session.

| Step | Backend total | CachedPlan* contexts |
|---|---|---|
| Before | 1544 kB | none |
| After 100 PREPAREs | 65 MB | 63 MB (CachedPlanQuery 50 MB, CachedPlanSource 13 MB) |
| After 6 EXECUTEs of each | 119 MB | 114 MB (adds CachedPlan 52 MB, 100 generic plans) |
| After DEALLOCATE ALL | 3556 kB | none |

- That is about 630 kB per statement on prepare, matching the claim, and about 1.14 MB once a generic plan exists (the claim said about 900 kB).
- A one-off text is rarely executed six times on the same connection, so 630 kB is the relevant figure.
- Calibration: 100 PREPAREs of a trivial one-table lookup came to 1204 kB for 200 contexts, about 12 kB each. Only the large hand-built statements are expensive.

**What I did not verify**
- The exact translator output: I could not run the translator under the read-only rules, so the SQL is my hand translation. Memory follows the query tree, so this should not move the result much.
- The claim's 12.7 MB for 20 nearby-feed PREPAREs.
- A live API: `pg_prepared_statements` is per session, so I could not watch a pooled connection climb to 512.

**Where the claim overstates**
- 512 x 630 kB (about 300 MB a connection) assumes every slot holds a chat-list-sized statement. In practice the LRU holds a mix: hot static texts at 10 to 50 kB, plus a churn of one-off chat list and feed texts.
- 200 connections per pool is the cap, not the steady state.
- A more realistic expectation is tens to low hundreds of MB per backend, which is still several GB across a pool, none of it budgeted on MySQL. The Go-side growth is unbounded until restart.
- Because a member's request lands on an arbitrary pooled connection, per-member texts miss the cache almost every time. These routes also pay Parse + Describe and a full plan on each call.

**Fix**

Two PostgreSQL-only changes; neither touches a MySQL statement.

1. **Stop keeping one-off texts on the server.** In `newPostgresDialector` and `newPostgresFailoverDialector` (`iznik-server-go/database/postgres.go`), set `cfg.DefaultQueryExecMode = pgx.QueryExecModeCacheDescribe` after `pgx.ParseConfig`. This uses the unnamed statement, keeps binary formats and parameter typing as now, and plans every call, which is what MySQL does today under `interpolateParams`. A milder alternative is a small `statement_cache_capacity` in the DSN.
2. **Bound the translation cache.** Replace the `sync.Map` in `database/pgconn.go` with an LRU of a few thousand entries.

What I tested on the scratch database (server-side effect only; I did not change or run any Go code):
- **Unnamed statements:** the same 100 distinct chat list texts sent through psql `\bind` left the backend at 3.5 MB with no CachedPlan contexts and 0 rows in `pg_prepared_statements`, against 65 MB when named.
- **Array parameter:** one text with `chatid = ANY($5::bigint[])`, executed for the same 100 id lists, held 1.1 MB in total. Under `plan_cache_mode = force_generic_plan` the plan stayed on indexes: `Index Scan using chat_rooms_pkey on chat_rooms (rows=4)`, `Index Scan using chat_messages_chatid on chat_messages (rows=22)`, `Buffers: shared hit=277`.

I would not take the array-parameter route as the first fix: MySQL has no array bind, so it means changing the shared statement or adding a PostgreSQL-only branch at about ten call sites. The exec-mode change covers all of them in one place.

After the change, check backend memory and Go heap under replayed traffic before settling pool size and `max_connections`.

**How the synthetic data could mislead**

The memory per prepared statement depends on the query tree, not on table contents, so the synthetic data barely affects the 630 kB figure.

What the scratch database cannot show is the traffic mix:
- how many distinct one-off texts arrive per connection per hour;
- how many connections the pools really hold open;
- how long real chat id lists are. Mine had 3 to 30 ids; busy members and moderators have hundreds, which makes the text and the Go cache entries larger but changes server memory little.

The steady-state total (GB across the pool, growth rate of the Go map) is therefore arithmetic, not a measurement. The scratch server has default settings (`max_connections` 100), so the 200-connection pools could not all open against it as configured.

### 4. Prefix LIKE can no longer use any btree index (citext or varchar): the postcode typeahead seq-scans all of locations on every keystroke

- **Severity:** outage-risk (claimed outage-risk). **Verdict:** confirmed. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/location/location.go:385-392 (typeaheadQuery); schema cause: tools/pg-schema/config.json (locations.name as citext) plus the database collation en_US.utf8, with no pattern-ops or expression index emitted by tools/pg-schema/convert.mjs
- **How often:** Every keystroke of the postcode box on the give/ask/find flows and at sign-up: GET /location/typeahead and GET /locations?typeahead= (public, unauthenticated, the highest-volume site). The support search (GET users search in ModTools) is per support/mod query; auth.go:84 is per LoveJunk partner sign-in.

**Evidence**

The claim holds for the main site; two of its secondary sites are not regressions.

CODE
- iznik-server-go/location/location.go:376-392 (typeaheadQuery) builds `l1.name LIKE ?` with prefix+"%", plus `l1.type = 'Postcode'` and `l1.name LIKE '% %'`, with a LIMIT.
- It is reached from two public routes: GET /location/typeahead (router/routes.go:780, limit capped at 10) and GET /locations?typeahead= (location.go:441-447, limit up to 100).
- The frontend postcode box debounces at 200 ms (iznik-nuxt3/components/PostCode.vue:39), so it runs a few times per postcode typed.
- The translator has no LIKE rewrite and no knowledge of citext columns: the only citext mention in database/*.go is a type-name case in pgconn.go:382.
- pgx runs through stdlib with its default mode (database/postgres.go:91-95), so the statement is prepared with a bound parameter, which is what I tested.

MECHANISM
- locations.name is citext with only `locations_name btree (name)`; the database collation is en_US.utf8 (libc).
- pg_operator shows citext's `~~` is `public.texticlike` with prosupport `-`, whereas text's `~~` has `textlike_support`. The planner therefore cannot derive an index range from a citext LIKE under any operator class.

PLANS ON iznik_perf (2.0M locations, PostgreSQL 18.6, prepared statement)
- Hit, 'AN98 0%' (7 rows): 
  `Parallel Seq Scan on locations l1 ... Filter: ((name ~~ 'AN98 0%'::text) AND (name ~~ '% %'::citext) AND (type = 'Postcode'::text))`
  `Rows Removed by Filter: 666664` (x3 workers), `Buffers: shared hit=1061 read=47566`.
- Miss, 'ZZ99 9%': the same plan, `Rows Removed by Filter: 666667` x3, 48,598 buffers.
- Lowercase 'an98 0%': the same, 48,627 buffers.
- Unselective 'A%' (409,881 matches): the seq scan stops at the LIMIT after 59 buffers, so only selective or missing prefixes pay the full scan. Those are the normal case once two or three characters are typed.
- Equality is fine: `Index Scan using locations_name`, 7 buffers.
- MySQL today serves the prefix as a range on KEY name, a handful of pages.
- At production's 6.5M rows this is roughly 158k heap pages and three backends per request. Real rows are wider than the synthetic ones, so probably more.

OTHER SITES
- user/auth.go:84 has the same shape (name LIKE prefix% AND type, LIMIT 1) and the same mechanism; it runs per LoveJunk sign-in.
- user/user.go:1376 (support search by outcode): the planner drives from users, `Index Scan using locations_pkey ... loops=1000000`, `Buffers: shared hit=3096564 read=829718`. It is support-only, so rare.
- Not regressions:
  - authority/authority.go:325 uses '%term%' (line 313), which MySQL cannot index either.
  - The users_emails branch at user.go:1321 ORs in `email LIKE '%q%'`, so it already scans the whole table on MySQL.
  - user/partner.go:347 is a prefix LIKE on email, the same class as the main site; I did not plan it.
- I did not re-run the users fullname/firstname/lastname, users_logins.uid, yahooid or users_donations Payer branches. They are support-only.

RANGE ALTERNATIVE (the claim's option b) IS UNSAFE
- `name >= 'AN98 0' AND name < 'AN98 1'` does use the existing index (`Index Cond` on locations_name, 39 buffers).
- But across 12 sampled prefixes, 'BG8 ' (trailing space) returned 0 rows by range against 48 by LIKE. en_US ordering does not treat the space as a plain byte, so a range on the citext btree silently loses rows.

**Fix**

Tried in BEGIN ... ROLLBACK on iznik_perf (the trial index is gone afterwards). The fix needs two parts, both PostgreSQL-only, so MySQL statements stay exactly as they are.

1. Index, emitted by tools/pg-schema/convert.mjs or its config.json as an extra index:
   `CREATE INDEX locations_name_prefix ON locations (lower(name::text) text_pattern_ops);`
2. Predicate, on PostgreSQL only (a branch in typeaheadQuery and at auth.go:84 and user.go:1376, or a translator rule for these columns):
   `lower(l1.name::text) LIKE lower(?)` in place of `l1.name LIKE ?`.

The index alone does nothing: with it present, the original predicate still planned `Parallel Seq Scan`.

Results with both parts:
- Typeahead hit: `Index Scan using perf_trial_locname ... Index Cond: ((lower((name)::text) ~>=~ 'an98 0'::text) AND (lower((name)::text) ~<~ 'an98 1'::text))`, 36 buffers in total (was 48,627).
- Typeahead miss: 3 buffers (was 48,598).
- Unselective 'A%': still stops at the LIMIT, 43 buffers.
- auth.go:84 shape: 4 buffers.
- user.go:1376 shape: drives from locations through the new index, then `users_lastlocation`, 259 buffers (was 3.9M).

Constraints on the fix:
- The pattern must reach the planner as a value. Under `plan_cache_mode = force_generic_plan` the rewritten query went back to `Parallel Seq Scan` (48,612 buffers). pgx's default prepared mode should keep custom plans because the generic cost is far higher, but nobody should set force_generic_plan, and this is worth one check through the real API.
- Do not use the `name >= ? AND name < ?` range on the existing citext index: it lost rows for a prefix ending in a space.
- `lower()` matching equals citext's case folding for postcodes; for non-ASCII names it should be checked against citext before applying the same pattern to users_emails or users name columns.

**How the synthetic data could mislead**

- Synthetic postcodes are uniform over two-letter areas (65 rows per 'AN98 ' outcode, 7 per sector prefix). Real postcodes cluster, with busy outcodes holding thousands of units. That changes how soon a seq scan reaches its LIMIT for short prefixes, but not the full scan on a selective or missing prefix.
- 90% of the synthetic rows are type Postcode, and every one has a space, so the other two filters remove almost nothing. Production has a larger share of Road and Polygon rows with real geometry, which makes the heap wider and the scan dearer than measured here.
- The 200k added locations are unreferenced, which does not matter for this scan.
- The custom-versus-generic plan choice was tested with psql PREPARE, not through pgx. I inferred that pgx keeps custom plans from the cost gap (about 16,005 generic against about 17 custom), not from a run through the Go API.
- The buffer read/hit split reflects shared_buffers = 128MB. With production-sized memory the table would be cached, and the cost becomes CPU on three backends per request instead of I/O.

### 5. Prefix LIKE on a citext column cannot use its btree index: the postcode typeahead and the TrashNothing sync scan the whole table

- **Severity:** outage-risk (claimed outage-risk). **Verdict:** confirmed. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/location/location.go:388 (schema cause: citext list in tools/pg-schema/config.json, which this commit extends to users_emails.canon and the paf_* name columns)
- **How often:** GET /location/typeahead and GET /locations?typeahead= run per keystroke while a member types a postcode (post, signup and settings flows). tn:sync is scheduled everyTenSeconds in iznik-batch/routes/console.php:869 and runs the LIKE once per TrashNothing username added since the last pass. auth.go:84 runs on a LoveJunk partner login that carries a postcode prefix.

**Evidence**

The claim holds for the Go sites. For the Laravel sites the scan is real, but the SQL PostgreSQL receives is not what the claim says, which makes that half easier to fix.

**Code and reach**
- `iznik-server-go/location/location.go:376-393` (`typeaheadQuery`) is live. It is called from `GET /location/typeahead` (`router/routes.go:780`) and from the `GET /locations?typeahead=` handler (`location.go:570`).
- The frontend calls it from `iznik-nuxt3/components/PostCode.vue` with a 200 ms debounce, so roughly once per keystroke pause.
- No rule in `pgtranslate*.go` rewrites LIKE, so PostgreSQL gets the Go statement as written and the operator is citext `~~`.
- `iznik-server-go/user/auth.go:84` has the same shape (LoveJunk login carrying a postcode prefix).
- `tn:sync` is scheduled `everyTenSeconds` (`iznik-batch/routes/console.php:869`). The LIKE at `TNSyncCommand.php:641` runs once per TrashNothing username added since the last pass, so it is not hot when nothing is new.
- Laravel's vendor `PostgresGrammar::whereBasic` renders any LIKE as `"col"::text like ?`, and `JsonTextPostgresGrammar.php` does not override it. So the Laravel sites (`TNSyncCommand.php:641`, `GiftAidClaimService.php:88`) send `email::text LIKE`, not citext `~~`. That is also case-sensitive, unlike MySQL.
- `user.go:1321` should not be counted: its `email LIKE '%q%'` arm is OR'd in, so MySQL scans there too.

**Plans on iznik_perf (locations 2.0M rows, users_emails 1.39M)**
- Typeahead, rare prefix `'AN98 0I%'`, exact Go SQL:
  - `Parallel Seq Scan on locations l1  Filter: ((name ~~ 'AN98 0I%'::citext) AND (name ~~ '% %'::citext) AND (type = 'Postcode'::text))  Rows Removed by Filter: 666666` (x3 processes)
  - `Buffers: shared hit=236 read=48367`, to return 1 row.
- Typeahead, no match `'ZZ99 9%'`: the same scan, `shared read=48598`, 0 rows.
- Typeahead, common prefix `'AN%'` (12,002 matches): the LIMIT stops the scan after 174 buffers. Only selective or unmatched prefixes pay the full scan, but those are the normal case once a member has typed 4 or more characters.
- `auth.go` form (`name LIKE 'AN98%' ... LIMIT 1`): `Seq Scan ... Rows Removed by Filter: 645589`, 14,207 buffers.
- Equality on the same column is fine: `Index Scan using locations_name  Index Cond: (name = 'an98 0iw'::citext)`, 7 buffers.
- tn:sync as Laravel renders it (`email::text like 'member7-g%@user.trashnothing.com' ORDER BY id`): `Parallel Seq Scan on users_emails  Filter: ((email)::text ~~ ...)  Rows Removed by Filter: 463333` x3, `shared read=36915`.
- Gift Aid as Laravel renders it (`name::text like 'AN98 0%' order by name limit 1`): it walks `locations_name` in order with the LIKE as a filter, `Rows Removed by Filter: 299212`, 269,002 buffers. That is worse than a seq scan.

**MySQL today**
`locations` has `KEY name` and `users_emails` has a unique key on `email`, both under a case-insensitive collation. `LIKE 'prefix%'` is a range scan on those keys: a handful of pages per call.

**Scale**
Production `locations` is 6.5M rows, about 3.25 times the scratch table. That is roughly 158k pages per typeahead call, across three processes, per keystroke pause per typing member. A few dozen members typing postcodes at once would occupy every parallel worker and most of the I/O. That is why I kept outage-risk for the typeahead. The tn:sync part alone would be slow-at-scale: one scan of about 120k pages per new TrashNothing username, run serially.

**Fix trial (inside BEGIN ... ROLLBACK; confirmed no `trial_%` indexes remain)**
- Trial indexes on `(name::text) text_pattern_ops` and `lower(name::text) text_pattern_ops` do nothing for the unchanged Go SQL: still `Parallel Seq Scan ... Filter: (name ~~ 'AN98 0I%'::citext)`, 48,598 buffers. The Go statement must change on PostgreSQL.
- Go predicate rewritten to `lower(l1.name::text) LIKE 'an98 0i%'`:
  - `Index Scan using trial_loc_lname  Index Cond: ((lower((name)::text) ~>=~ 'an98 0i'::text) AND (lower((name)::text) ~<~ 'an98 0j'::text))`
  - 8 buffers including the l2 join; the no-match case is 3 buffers.
- The same as a prepared statement with `LIKE lower($1)`: the custom plan uses the index (4 buffers). Under `plan_cache_mode = force_generic_plan` it falls back to the Parallel Seq Scan, because no prefix can be taken from a parameter.
- tn:sync with a trial index on `(email::text) text_pattern_ops` and no code change: `Index Scan using trial_ue_temail  Index Cond: (((email)::text ~>=~ 'member7-g'::text) AND ((email)::text ~<~ 'member7-h'::text))`, 4 buffers (was 36,993).
- Gift Aid with a trial index on `(name::text) text_pattern_ops` and no code change: `Index Scan using trial_loc_tname`, 8 buffers (was 269,002).
- Trial index sizes: 57 MB (locations) and 73 MB (users_emails).

**Fix**

All of this is PostgreSQL-only; the MySQL statements stay exactly as they are.

1. **Schema (`tools/pg-schema/convert.mjs` / `config.json`).** Emit extra expression indexes for the prefix-searched citext columns:
   - `(col::text) text_pattern_ops` on `users_emails.email` and `locations.name`. This alone fixes the Laravel sites (`TNSyncCommand.php:641`, `GiftAidClaimService.php:88`) with no code change, because Laravel already renders `"col"::text like ?`.
   - `(lower(col::text)) text_pattern_ops` on `locations.name` for the Go sites. Add `users_emails.canon` and `users_logins.uid` only if their prefix sites are split out of the OR in `user.go`.
   - A single `lower()` index per column would serve both stacks if the Laravel sites also moved to `lower(col::text) LIKE lower(?)` through an engine-aware expression. That would also restore MySQL's case-insensitive matching, which `::text like` currently loses on PostgreSQL.

2. **Go (`location.go:388`, `auth.go:84`).** No index helps the citext `~~` operator, so these need a PostgreSQL branch or translator rule that sends `lower(l1.name::text) LIKE ?` with the prefix lowercased in Go. Passing the pattern as a plain value (not wrapped in `lower($1)`) keeps the custom plan able to take the prefix. It still depends on PostgreSQL choosing a custom plan, which it does by default because the generic plan costs far more.

Not included: a `>=` / `<` range on the existing citext btree. Under the en_US.utf8 collation a range is not equivalent to a prefix when the value contains a space.

**How the synthetic data could mislead**

- **Postcode prefixes are spread evenly.** Synthetic postcodes are hashed, so a 7-character prefix matches about one row. Real postcodes cluster (an outcode has hundreds of units), so the LIMIT 10 early exit in the seq scan would trigger somewhat more often for short prefixes. Unmatched and near-complete prefixes still scan everything.
- **Row width is understated.** Synthetic `locations` rows are small (48.6k pages for 2M rows). Real rows carry polygon geometry for non-postcode types, so the production heap per row is probably larger and the scan worse than the linear extrapolation.
- **Buffer splits reflect a small cache.** `shared_buffers` is 128 MB here, so the hit/read split is pessimistic. A production-sized cache would hold `locations`, but the CPU cost of evaluating citext LIKE on 6.5M rows per call remains.
- **TrashNothing addresses are regular.** Synthetic addresses are `memberN-gM`, one per username. That does not change the plan shape.
- **Not tested under load.** The generic-plan fallback was shown only with `force_generic_plan`. I did not check which query mode pgx uses or how it behaves over many executions.

### 6. crc32() is a PL/pgSQL bit loop evaluated for every active user on each chunk of the daily digest and daily push shard queries

- **Severity:** slow-at-scale (claimed slow-at-scale). **Verdict:** confirmed. **Fix tried on the scratch database:** yes.
- **Where:** iznik-batch/database/postgres/functions.sql:199 (callers: iznik-batch/app/Services/UnifiedDigestService.php:1745 and iznik-batch/app/Console/Commands/Push/SendDailyPostsPushCommand.php:148)
- **How often:** mail:digest:unified --mode=daily: 8 shards, relaunched every minute through the morning send window (routes/console.php:961), one statement per 500 users plus one per relaunch. push:daily-posts: 4 shards every 30 minutes 07:30 to 12:00 (routes/console.php:1099), one statement per 200 users.

**Evidence**

The claim holds for the digest: the mechanism is in the code and the plan. The push half is real but smaller, and the suggested CREATE STATISTICS makes the digest's idle tick worse.

**Code**
- `iznik-batch/database/postgres/functions.sql:199` defines `crc32(text)` as LANGUAGE plpgsql with an 8-iteration loop per byte; `pg_proc` shows procost 100.
- `iznik-batch/app/Database/Expressions/Crc32.php` renders `CRC32(CAST("users"."id" AS text))` on PostgreSQL.
- Callers are `UnifiedDigestService.php:1745` (daily mode, shards > 1) and `SendDailyPostsPushCommand.php:148`.
- `routes/console.php:961` schedules 8 digest shards `everyMinute()` between 07:00 and 12:00; `:1099` schedules 4 push shards every 30 minutes between 07:30 and 12:00.
- `streamDailyOverdueFirst` issues a fresh statement per 500 users (phase 1 by id, phase 2 by lastsent, id), so each relaunch runs at least two statements even when nothing is due.
- The daily digest is gated by `FREEGLE_DIGEST_DAILY_ALLOWLIST`; an empty list adds `whereIn('messages.id', [])`. I did not check what production sets it to.

**Function cost** (serial, `users` id <= 200000, index-only scan)

| Variant | Execution time |
|---|---|
| `public.crc32(id::text)` as shipped | 1120 ms |
| `pg_catalog.crc32(convert_to(id::text,'UTF8'))` | 44 ms |

The two agree on all 300,000 ids compared (0 mismatches).

**Digest phase 2, first chunk** (hand-rendered from the builder, serial)
- Plan node: `Bitmap Heap Scan on users ... Filter: (... AND ((crc32((id)::text) % '8'::bigint) = 3))`, actual 130..3240 ms, rows=40868, Rows Removed by Filter 300681, estimated rows=1618.
- Whole statement: 3455 ms, about 113k buffers.
- Same statement with the built-in rendered directly: users scan 386 ms, statement 616 ms, about 113k buffers. The difference is CPU in the filter, not I/O.
- With default parallel settings (2 workers) the shipped statement took 2309 ms.
- Phase 1 first chunk, serial: 3807 ms, same users scan.

**Idle tick** (everyone already sent today, 0 rows returned)
- Serial: 3119 ms. With 2 workers: 1346 ms.
- This is the statement the scheduler comment calls "a cheap no-op"; it runs twice per shard per minute for the rest of the window.

**MySQL today**
- Neither engine has an index led by `lastaccess` (only `added(added, lastaccess)`), so MySQL also re-filters the active set per chunk.
- MySQL's CRC32 is native, so the extra cost on PostgreSQL is the function body alone.

**Push query** (shard 1 of 4, `lazyById(200)`)
- First chunk: estimated rows=74 against 4838 actual; the planner chose HashAggregate over `users_push_notifications`, 43601 probes of `users_id`, then a Sort. 455 ms, 176k buffers.
- A later chunk (`id > 900000`) already walks `users_id`: 77 ms, 7k buffers.
- The job is about 94 chunks per run across the shards, so this is a minor cost. I did not reproduce the claim's 57,533 buffers / 1.1 s figure; my plan differed.

**CREATE STATISTICS suggestion**
- Push first chunk improves: PK walk, 97 ms, 14k buffers.
- Digest first chunk improves: walk of `users_digests_mode_lastsent`, 127 ms.
- Digest idle tick gets worse: the same index walk probes users 200000 times, 1298 ms and 1,034,179 buffers, against 644 ms and 92k buffers with the function fix alone.

**Not tested**
- The other functions the claim lists (`date_format`, `json_valid`, `haversine`, the FirstReply CRC32 over about 12k rows).

**Fix**

Replace the body of `public.crc32(text)` in `iznik-batch/database/postgres/functions.sql` with a one-line call to PostgreSQL 18's built-in, and lower its cost:

```sql
CREATE OR REPLACE FUNCTION crc32(s text) RETURNS bigint
LANGUAGE plpgsql IMMUTABLE STRICT PARALLEL SAFE COST 1 AS $$
BEGIN
  RETURN pg_catalog.crc32(convert_to(s, 'UTF8'));
END
$$;
```

**MySQL impact:** none. Only the PostgreSQL function changes; `Crc32.php`, the raw `CRC32(CONCAT(...))` in `FirstReply/Rollout.php` and every MySQL statement stay exactly as they are.

**Tried in BEGIN ... ROLLBACK on iznik_perf** (confirmed afterwards that the original function, procost 100, is back and no statistics objects remain):

| Statement (serial) | Before | After |
|---|---|---|
| 200k-row microbench | 1120 ms | 102 ms |
| Digest phase 2, first chunk | 3455 ms | 633 ms |
| Digest idle tick | 3119 ms | 644 ms |
| Push first chunk | 455 ms, 176k buffers | 348 ms, 54k buffers (seq scan + hash semi join) |

- Results are identical on the test strings, including multibyte, backslash and empty; `crc32('123456789')` = 3421780262; NULL in gives NULL out.
- Rendering the built-in directly in `Crc32.php` is slightly faster again (44 ms against 102 ms on the microbench) but leaves the raw-SQL callers on the slow function. The function-body change covers every caller.
- This depends on `pg_catalog.crc32(bytea)`, which exists only from PostgreSQL 18.

**Do not add the expression statistics for the digest.** They fix the push first chunk, but they flip the digest's idle tick to a 200,000-probe index walk (1,034,179 buffers against 92k). If the push estimate matters, a statistics object on the `% 4` expression only would have to be tested against the `% 8` digest statements first. The `date_format` to `to_char` port in the claim was not tested.

**How the synthetic data could mislead**

- **Active set size.** The cost is proportional to the number of users passing `lastaccess > now - 182.5 days`. Here that is 401k of 1M (40%), because the generator makes users denser towards now. Production's 6-month-active count is unknown to me; if the fraction is lower, the per-statement cost falls with it.
- **Daily population.** Synthetic `users_digests` has 200k daily rows, all stamped in the last 24 hours. The scheduler comment puts production at about 79k, so chunk counts per shard here are not production's.
- **Phase 1.** 23,850 never-sent users fall in one shard here, which is unrealistic; production's phase 1 is normally short.
- **Other filter costs.** `users.settings` is small JSON, so the jsonb parse in the same filter is cheap. Larger real settings would make crc32 a smaller share of the scan, though the absolute saving stays.
- **Push.** There are no `mode = 'push'` rows in `users_digests`, so the once-per-day anti-join is always empty and the idle-tick behaviour of the push query was not exercised.
- **Server settings.** `shared_buffers` is 128 MB and `work_mem` 4 MB, so the read counts and the 4-batch hash spill reflect defaults. The crc32 CPU difference does not depend on them.
- **Parallelism.** Two workers were free on this host; with 8 digest and 4 push shards running together, production may be closer to the serial figures.

### 7. With PostgreSQL's default cost settings the mygroups unread badge reads the member's communities' whole posting history on every poll (hash semi-join instead of keyed probes)

- **Severity:** slow-at-scale (claimed outage-risk). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/isochrone/message.go:883 (myGroupsCountUnfiltered); predicate built at message/membership.go:85-87 (ApprovedInMyGroups). No PostgreSQL planner settings are chosen anywhere in the branch.
- **How often:** GET /message/count (router/routes.go:848): the unread badge, polled about every 60 s by each active member whose browse view is mygroups. The feed variants run on each uncached browse page load.

**Evidence**

The plan flip is real and reproduces exactly, but it is a missed 7x saving on big-community members rather than an outage risk: the default plan lands roughly where production MySQL already is.

CODE
- The statement is real and live: `GET /message/count` (`iznik-server-go/router/routes.go:848`) -> `isochrone.Count` -> `myGroupsCount` -> `myGroupsCountUnfiltered` (`iznik-server-go/isochrone/message.go:883`).
- The predicate comes from `approvedInMyGroupsPredicate` (`iznik-server-go/message/membership.go:63`): group ids are inlined as constants up to 50 groups, above that it falls back to a join on `memberships`.
- The pending guard is `rippling.ReachPendingFilter` (`iznik-server-go/rippling/pendingreach.go:52`), on by default.
- No planner setting is committed anywhere: grep for `random_page_cost`, `effective_cache_size`, `jit` and `shared_buffers` in the compose files, `pgconn.go`, `config/database.php` and `database-engines.md` finds nothing. The scratch server has `random_page_cost` 4, `jit` on, `max_parallel_workers_per_gather` 2.
- Frequency is lower than "every poll": `iznik-server-go/browsecount/cache.go` reuses the count per viewer for 30 s. Its header says about 24,700 badge requests an hour and 380-720 ms per query on production MySQL; the code comment at the call site says this statement runs 14,283 times a day.
- `membership.go` records MySQL at 901-1080 ms for this clause before the constant-list rewrite, and 2.2-2.4x better after.

MEASURED on iznik_perf (viewer 12345, communities 1, 2, 3; 12,434 open posts)
I ran the statement hand-rendered in PostgreSQL form with literals (`INTERVAL '10 minute'`), not captured from the translator.

Default settings, 148-181 ms, 45,220 buffers:
```
Hash Right Semi Join (rows=993)
  Hash Cond: (mg.msgid = ms.msgid)
  -> Bitmap Heap Scan on messages_groups mg (cost=5568.07..54104.66 rows=235797) (actual rows=235537)
       Recheck Cond: (groupid = ANY ('{1,2,3}'))
       Rows Removed by Filter: 10144   Heap Blocks: exact=43807
       Buffers: shared read=44751
       -> Bitmap Index Scan on messages_groups_groupid_3
JIT: ... Total 16.219 ms
```
Estimates are accurate (235,797 estimated, 235,537 actual), so this is a costing choice, not a misestimate.

`SET random_page_cost = 1.1` alone, 22 ms, 38,764 buffers, all hits:
```
Nested Loop Semi Join (rows=993)
  -> Seq Scan on messages_spatial ms (rows=12434)
  -> Index Scan using messages_groups_msgid on messages_groups mg (rows=0.08 loops=12434)
       Index Cond: ((msgid = ms.msgid) AND (groupid = ANY ('{1,2,3}')))
```

Which setting matters:
- `random_page_cost` 2 and 1.5 give the same nested loop (34 ms with caches not yet warm, 21 ms).
- `effective_cache_size = '24GB'` alone leaves the hash plan (153 ms).
- `jit = off` alone leaves the hash plan (170 ms).

By community size at defaults:
- Community 1 alone: Hash Join over 131,915 rows, 41,525 buffers, 146 ms.
- Community 6 (29k rows): Hash Join, 21,310 buffers, 105-124 ms.
- Community 400 (3.2k rows): nested loop driven from `messages_groups`, 9,797 buffers, 17 ms.
So only members of mid-sized and large communities are affected.

WHY SMALLER THAN CLAIMED
- "All read from disk" is an artefact of the 128 MB `shared_buffers`. `messages_groups` is 343 MB here, about 1.1 GB in production, and would sit in cache on a sized server. The cost is CPU and buffer touches (about 235k rows hashed), not cold I/O.
- Scaled 3.25x, the default plan is roughly 450-550 ms for a member of the largest communities. That is the same range as the 380-720 ms the code records for MySQL today.
- The 30 s cache bounds the rate.

OTHER STATEMENT IN THE CLAIM
`group/groupMessages.go:22` for community 1 does plan `Gather Merge, Workers Planned: 1` to return 857 ids, at `random_page_cost` 4 and 1.1 (11,834 buffers, 17.6 ms). With `max_parallel_workers_per_gather = 0` it is a plain nested loop on `messages_groups_arrival`, 11,772 buffers, 10.4 ms. Confirmed, minor.

NOT TESTED
- The sibling statements (`myGroupsMsgIDs`, `message/groups.go`, `message/search.go`) and the logs finding.
- The over-50-communities join form used for moderators.
- Bound-parameter (generic plan) behaviour through the Go driver.

**Fix**

Set `random_page_cost` for the PostgreSQL database (about 1.1 for SSD; anything at or below 2 flips this plan). This is server configuration only, so no SQL changes and MySQL statements stay exactly as they are.

- Where: `ALTER DATABASE iznik SET random_page_cost = 1.1`, or the equivalent in the postgres service command in `docker-compose.yml` and the production `postgresql.conf`. Record it in `docs/developers/reference/database-engines.md` and apply it to iznik_perf so later reviews measure the intended configuration.
- `effective_cache_size` and `jit = off` do not change this plan. `jit = off` saves about 16 ms of compile on the hash plan and is sensible for an OLTP API, but it is not the fix.
- `max_parallel_workers_per_gather = 0` for the API role (`ALTER ROLE ... SET`) removes the one-worker Gather Merge on the community page listing: 17.6 ms to 10.4 ms for the same buffers.
- Pinning the shape in SQL (LATERAL ... LIMIT 1, or an un-pullable EXISTS) is not needed and would have to live in a PostgreSQL-only branch to keep MySQL unchanged. I did not try it.

Tested with session-level `SET` only; nothing persistent was changed on the scratch database.

**How the synthetic data could mislead**

- Server settings: `shared_buffers` is 128 MB, so the read-versus-hit split overstates I/O. Compare buffer totals (45k against 39k) and rows processed (235k against 12k probes), not reads.
- Open-post count: `messages_spatial` has 12,434 rows, which scales to about 40k, while the code comments put production at about 27,000 candidates. The nested loop is therefore relatively cheaper in production than here, which strengthens the case for the keyed plan.
- Community size: the largest community here has 137k `messages_groups` rows against a claimed 450k in production (3.3x), so the hash plan scales as stated.
- Rippling: only 5% of posts here are on a second community. Production rippled posts have many `messages_groups` rows per msgid. With the constant IN list the probe stays keyed on (msgid, groupid), so this should not hurt, but it is not modelled.
- Viewer: 12345 has only 239 View likes. A heavy browser has thousands, which makes the anti-join side bigger in both plans.
- `browse_cleared` is empty, so the watermark is 0 and every open post is a candidate. This is the worst case.
- Heap order: `messages_groups` is in msgid order with communities hashed uniformly, so one community's rows touch nearly every heap page. Production will be the same, since communities interleave in time.

### 8. ModTools logs: ORDER BY logs.id DESC LIMIT with a community filter walks the whole logs primary key backwards when the other filters match few rows

- **Severity:** slow-at-scale (claimed slow-at-scale). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/logs/logs.go:156 (query assembled at :60-158). Schema cause: logs has indexes on (groupid), ("user"), (byuser) but none ending in id.
- **How often:** GET /modtools/logs (router/routes.go:1370): each time a moderator opens the Logs page or changes a filter, and on each scroll page. Moderators only, but one request can hold three cores and evict gigabytes of cache.

**Evidence**

CODE. The statement is real and reached on PostgreSQL: iznik-server-go/logs/logs.go buildGetLogsQuery (lines 28-158), run by GetLogs via db.Raw, route GET /modtools/logs (router/routes.go:1370). PostgreSQL gets the same shape with IFNULL as COALESCE and logs.user quoted (pgtranslate_more.go:556-564). MySQL has KEY `group` (`groupid`), which InnoDB stores as (groupid, id); PostgreSQL has logs_group btree (groupid) only.

What the page actually sends: iznik-nuxt3/modtools/components/ModLogs.vue sends only limit, groupid, logtype (messages or memberships) and search. With a groupid the handler only ever emits type IN ('Message') or type IN ('Group','User'), each with a subtype list. The claim's headline case (groupid = 1 AND type IN ('Config')) cannot be produced by this handler. Its second case (group filter plus modmails) is not sent by the page either: modmailsonly comes from ModLogsModal with logtype=user and a userid, which uses the user indexes.

All runs below use the full statement (messages join and messages_edits subquery) on iznik_perf with default settings.

Default views are fine:
- groupid=1, messages: "Index Scan Backward using logs_pkey ... Rows Removed by Filter: 701", 227 buffers.
- groupid=371 (smallest, 8,558 log rows), messages: same plan, "Rows Removed by Filter: 42223", 980 buffers.

Reachable bad cases:
- groupid=371, memberships tab, first page: "Parallel Index Scan Backward using logs_pkey ... Rows Removed by Filter: 825456" (loops=3, about 2.5M rows walked to find 20), "Buffers: shared hit=11593 read=39828" (51,421). Estimated 2,054 matching rows. random_page_cost=1.1 does not change this plan (51,439 buffers).
- groupid=1, messages, search term matching nothing: "Parallel Index Scan Backward using logs_pkey ... Rows Removed by Filter: 4221598" (loops=3, the whole table), 270,490 buffers on logs, plus messages and users probed 335,205 times each; 2,952,140 buffers in total. Most of that total is the join probes for the community's 335k message rows, which MySQL pays as well; the PostgreSQL-only part is the 270k-buffer walk.
- The claim's Config case reproduces as measured (Rows Removed by Filter: 4333333 x 3, 270,511 buffers) but is unreachable.

Cases the planner already handles: small community plus search uses a bitmap scan on logs_group (56,334 buffers, nearly all join probes); a rare subtype uses BitmapAnd of logs_group and logs_type (338 buffers); a late page with a low context id uses BitmapAnd of logs_group and logs_pkey (73 buffers).

So the mechanism is as claimed, and a default tab on a small community pays about 51k buffers per page here. The 271k-buffer full walk needs a search with no match on a large community (or a hand-made logsubtype), not an ordinary page open.

**Fix**

Add a PostgreSQL-only index logs (groupid, id). The MySQL statements stay exactly as they are. The converter (tools/pg-schema/convert.mjs, index emission around line 720) has no extra-index setting in config.json today, so it needs one, or a rule that appends the primary key to a single-column KEY.

Tried in a rolled-back transaction (CREATE INDEX logs_group_id_trial ON logs (groupid, id)):
- groupid=371 memberships: "Index Scan Backward using logs_group_id_trial ... Index Cond: (groupid = 371) ... Rows Removed by Filter: 91", 115 buffers (was 51,421). Paged with id < 9000000: Index Cond on both columns, 79 buffers.
- groupid=371 messages: 179 buffers (was 980).
- groupid=1 at the default random_page_cost=4: no change. The no-match search and the Config case still take the logs_pkey walk (about 270k buffers on logs), because the planner prices 480k random heap fetches above the walk.
- groupid=1 with random_page_cost=1.1: the trial index is used. Config reads the community's 480,687 rows, 168,447 buffers; the no-match search totals 2,851,299 buffers, almost all join probes. That is MySQL's plan and MySQL's cost.

Two corrections to the claim's fix:
- The index alone does not cover the largest communities; it also needs random_page_cost near 1.1 for the API role or server, which is a separate decision.
- Do not treat it as a free replacement for logs_group. It is 391 MB against 86 MB here (about 1.3 GB against 280 MB at production size), because (groupid, id) entries cannot be deduplicated. With random_page_cost=1.1 and the new index, the unreachable Config case moves from a BitmapAnd of 18,651 buffers to the 168,447-buffer ordered scan, so rare-filter queries on big communities can get worse than the bitmap plan, though no worse than MySQL.

Not tried: capping parallel workers.

**How the synthetic data could mislead**

The memberships result depends on how the generator spread Group/User log rows over communities and time. Community 371 has about 1 matching row per 7,300 log rows near the top of the table. Real small communities may have denser or sparser recent joins, so 51k buffers could be much lower or reach the full walk for a dormant community. Synthetic logs has 18% of rows with no groupid and a top community holding 3.7% of rows; production skew is unknown. Search uses a term that matches nothing; the text columns are low-cardinality filler, so real LIKE hit rates are not represented. shared_buffers is 128MB and random_page_cost is 4, so the planner's choice between logs_pkey and a (groupid, id) index for large communities is settings-dependent, as shown.

### 9. Every Laravel `LIKE` is sent as `col::text LIKE ?`, so prefix LIKEs that use an index on MySQL become full scans of users_emails and locations (and turn case-sensitive)

- **Severity:** slow-at-scale (claimed slow-at-scale). **Verdict:** confirmed. **Fix tried on the scratch database:** yes.
- **Where:** iznik-batch/app/Console/Commands/TrashNothing/TNSyncCommand.php:641 (rendering: iznik-batch/vendor/laravel/framework/src/Illuminate/Database/Query/Grammars/PostgresGrammar.php:56 whereBasic, inherited by app/Database/JsonTextPostgresGrammar.php)
- **How often:** - tn:sync runs about once a minute (routes/console.php ~859-867) and issues one such scan per newly seen TrashNothing username.
- geocodePostcode runs inside the nightly WhatJobs load (04:40), per distinct outward code.
- DonationThankPrepService runs per donation being matched.
- SpamCheckService runs per incoming email.

**Evidence**

The claim holds: the statements are real and reached on PostgreSQL, and each one scans the whole table or a whole index. A trial expression index turned all three into 3-61 buffer range scans.

**Code**
- `iznik-batch/vendor/laravel/framework/src/Illuminate/Database/Query/Grammars/PostgresGrammar.php:56` `whereBasic` emits `%s::text %s %s` for any operator containing "like".
- `iznik-batch/app/Database/JsonTextPostgresGrammar.php` does not override it.
- `TNSyncCommand.php:641` is live: `->where('email','LIKE', <username>.'-g%@user.trashnothing.com')->orderBy('id')`, once per newly seen TrashNothing username.
- `tn:sync` is scheduled `everyTenSeconds()` (`routes/console.php:869`), which is more often than the claim's "once a minute".
- `DonationThankPrepService.php:619` runs from `mail:donations:thank-prep`, daily at 20:30, once per donation.
- `WhatJobsService.php:1349` `geocodePostcode` runs from `integrations:sync-whatjobs`, every 3 hours between 08:00 and 22:00, not nightly at 04:40 as claimed. I did not check whether it caches per outward code.
- I did not open the other sites the claim lists (SpamCheckService, GiftAidClaimService and the rest).

**Schema**
- `users_emails.email` and `locations.name` are `citext`.
- Their only indexes are the default-opclass btrees `users_emails_email` (unique) and `locations_name`.
- `datcollate` is `en_US.utf8` for `iznik` and `iznik_perf`.
- The baseline has no `text_pattern_ops` or expression index.

**Plans on iznik_perf, as Laravel sends them**

1. TN sync, `"email"::text LIKE 'member3206-g%@user.trashnothing.com' order by "id"`, returning 1 row:
   - `Parallel Seq Scan on users_emails  Filter: ((email)::text ~~ ...)  Rows Removed by Filter: 463333` (x3 workers)
   - `Buffers: shared read=36915`
   - Estimated 139 rows, actual 1.
2. Donation thank prep, 0 rows:
   - `Index Scan using users_emails_userid  Index Cond: (userid IS NOT NULL)  Rows Removed by Filter: 1390000`
   - `Buffers: shared hit=613445 read=193083`
   - The planner walks the userid index to satisfy `ORDER BY userid LIMIT 5`, expecting 119 matches, and reads the whole index when there are none.
3. WhatJobs, `name::text like 'ES86 %'`, 80 rows:
   - `Parallel Seq Scan on locations  Rows Removed by Filter: 666640` (x3)
   - `Buffers: shared read=48598`

**Removing the cast does not help**
- `email LIKE '...'` on the citext column is still `Parallel Seq Scan  Filter: (email ~~ '...'::citext)`, 36,915 buffers.
- It was slower in the same session (437 ms against 67 ms), because the citext `~~` operator has no index support and lowercases every row.
- So the first option in the claim's suggested fix (override `whereBasic` to drop the cast) does not fix performance by itself.

**MySQL today**
- A left-anchored LIKE is a range read on the `email` unique key or the `name` key: a few pages.
- On PostgreSQL the cost is linear in table size: about 120k pages for 4.5M `users_emails` rows and about 158k pages for 6.5M `locations` rows, per call.

**Trial fix, inside BEGIN ... ROLLBACK**
- Created `users_emails ((email::text) text_pattern_ops)` and `locations ((name::text) text_pattern_ops)`, then ANALYZE.
- Q1: `Index Scan using t_ue  Index Cond: (((email)::text ~>=~ 'member3206-g') AND ((email)::text ~<~ 'member3206-h'))`, `Buffers: shared read=4`, 0.04 ms.
- Q2: `Index Scan using t_ue  Index Cond: (~>=~ 'zzznobody@' AND ~<~ 'zzznobodyA')`, `Buffers: shared read=3`.
- Q3: `Index Scan using t_loc  Index Cond: (~>=~ 'ES86 ' AND ~<~ 'ES86!')`, `Buffers: hit=53 read=8`.
- With a bound parameter the index is used only under a custom plan. `PREPARE` + `EXECUTE` in the default `plan_cache_mode` gave the index scan. `force_generic_plan` gave the seq scan again (`Filter: ((email)::text ~~ $1)`).
- Laravel prepares a fresh statement per query, so it should get custom plans. I found no `EMULATE_PREPARES` override in `app/` or `config/`, but did not confirm this through PDO itself.
- Rollback verified: 0 trial indexes remain.

**Case sensitivity**
- Confirmed: with the cast, `'MEMBER3206-g%...'` returns 0 rows where the lower-case pattern returns 1. MySQL would match both.

**Fix**

Add two PostgreSQL-only expression indexes; MySQL statements and schema stay exactly as they are.

```sql
CREATE INDEX users_emails_email_text_pattern ON users_emails ((email::text) text_pattern_ops);
CREATE INDEX locations_name_text_pattern ON locations ((name::text) text_pattern_ops);
```

- **Where to put them:** `tools/pg-schema/config.json` has only `citext` and `caseSensitive` keys today, so `convert.mjs` needs a small "extra indexes" entry to emit them into `baseline.sql`.
- **Cost:** roughly the size of the existing btrees, which are 108 MB and 85 MB on the scratch database (about 3.25x that in production), plus one more index to maintain on each write.
- **Constraint:** the indexes only work with custom plans. Keep `plan_cache_mode` at its default and do not force generic plans.
- **Not a fix by itself:** overriding `whereBasic` to drop the `::text` cast, because citext LIKE has no index support and was slower in the trial.
- **Case sensitivity is a separate decision.** The indexes keep Laravel's case-sensitive match. To restore MySQL's behaviour, a PostgreSQL-only `whereBasic` override in `JsonTextPostgresGrammar` could render `lower(col::text) LIKE lower(?)`, with the indexes built on `(lower(email::text)) text_pattern_ops` instead. I did not trial that variant. It also leaves MySQL untouched.

**How the synthetic data could mislead**

- **Scale:** the scratch tables are about 3.25x smaller than production, so the seq-scan buffer counts understate production by that factor. The mechanism does not depend on the data.
- **Q2's empty result:** I chose a local part that matches nothing, which is the worst case for the userid-ordered walk. A common local part would stop at the LIMIT sooner. Real emails share local parts across providers, which these unique `memberN` addresses do not.
- **Row estimates:** synthetic emails and postcodes are low-variety, so the estimates (139 against 1 actual) and the planner's choice of the userid-ordered walk may differ on real statistics.
- **Index selectivity after the fix:** a short real TrashNothing username such as `al` would range-scan every address starting `al-g`. That is still tiny, but not 4 buffers.
- **Case sensitivity:** the synthetic addresses and postcodes are single-case, so this data cannot show how many production rows the case-sensitive match would miss.
- **Timings:** the server runs default settings (128 MB shared_buffers) on a shared host. Read the buffer counts, not the milliseconds.

### 10. messages_likes loses its InnoDB clustering and its covering secondary index: per-post view sums and per-member viewed lookups become one heap page per row

- **Severity:** slow-at-scale (claimed slow-at-scale). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/isochrone/message.go:211 (also :556 and :759); documented reliance at iznik-server-go/message/bounds.go:47-55; table DDL in iznik-batch/database/postgres/baseline.sql (messages_likes has no primary key)
- **How often:** The view-sum subquery runs once per post on every Browse/isochrone list fetch (the main member page) and per post in every digest build; the per-member viewed lookup runs on every map-bounds fetch for a signed-in member.

**Evidence**

The mechanism is real and I reproduced it, but it is extra buffer work on mostly hot pages for Browse, and the heavy-member case depends on what the planner knows about that member.

**Code and schema**
- The statements exist as quoted: `iznik-server-go/isochrone/message.go:211` and `:556` (view sum per post, no LIMIT on the candidate set), and `iznik-server-go/message/bounds.go:47` (viewed lookup, chunks of 1000 ids).
- Routes: `GET /isochrone/message` (Browse) and `GET /message/inbounds`, in `iznik-server-go/router` at lines 700 and 861.
- MySQL has no PRIMARY KEY on `messages_likes`, only `UNIQUE (msgid,userid,type)` on NOT NULL columns, so InnoDB clusters on it. That makes the `bounds.go:52-55` comment true on MySQL.
- `baseline.sql:4354-4356` carries the same three indexes onto a PostgreSQL heap. The scratch table has msgid correlation 1.0, so it hides the effect.

**View sum per post**

I copied the newest 449,221 rows into a temp table in hashed order, loaded with COPY FREEZE inside BEGIN ... ROLLBACK so the visibility map was set (3,744 of 3,784 pages all-visible).

| Case | Plan node | Buffers |
|---|---|---|
| Scratch table as generated, 200 posts | `Index Scan using messages_likes_msgid ... rows=14.89 loops=200` | 824 |
| Scattered copy, 200 posts | `Bitmap Heap Scan on ml_t ... Heap Blocks: exact=2963` | 3,563 |
| Scattered copy, all 12,434 open posts | `Heap Blocks: exact=178412` | 215,714 |

The scattered copy reads one heap page per view row, as claimed.

**Viewed lookup for a member with 5,000 views**

I planted 5,000 views for one member (userid 42) in the scattered copy and ran the lookup with 1000 literal ids.

- When ANALYZE has the member in its most-common-values list, the plan is bounded: `Index Only Scan using ml_t_msgid_2 ... Index Searches: 37, Heap Fetches: 0`, 241 buffers. A BitmapAnd variant took 196.
- The plan PostgreSQL picks when it does not know the member is heavy is the one on the real table: `Index Scan using messages_likes_userid ... Filter: type = 'View' AND msgid = ANY(...)`, cost 259 against about 2,880 for the index-only alternative.
- Forcing that userid-driven shape for the heavy member gave `Heap Blocks: exact=2773, Rows Removed by Filter: 4851`, 2,780 buffers for 149 result rows.
- At production size a member with 5,000 of 150M rows will not be in the most-common-values list, so this is the plan they would get. Their lifetime views sit on old pages across the whole heap.

**Not tested**
- The other cited sites (the LEFT JOIN on msgid, userid, type; `dashboard/popular.go`; the digest and first-reply queries) were read but not run.
- The replica-identity remark was not tested.
- Whether `tools/pg-schema/convert.mjs` can already emit PostgreSQL-only index definitions was not checked.

**Fix**

Two PostgreSQL-only index changes on `messages_likes`, with no change to any MySQL statement or to the Go and PHP code:

1. Replace `messages_likes_msgid (msgid, type)` with `(msgid, type) INCLUDE (count)`.
   - 200 posts: `Index Only Scan using ml_t_inc ... Heap Fetches: 0`, 614 buffers against 3,563.
   - All 12,434 open posts: 38,125 buffers against 215,714.
2. Replace `messages_likes_userid (userid)` with `(userid, type, msgid)`. It still serves the userid foreign key.
   - Ordinary member, once the old userid index was dropped: `Index Only Scan using ml_t_u3 ... Index Searches: 1, Heap Fetches: 0`, 3 buffers.
   - While the old userid index existed the planner still preferred it, so this must be a replacement, not an addition.
   - For the planted heavy member the planner kept `ml_t_msgid_2` (241 buffers), which is also fine.

Both were tried on the scattered copy inside a rolled-back transaction, not on the 46M-row table.

Needed alongside: per-table autovacuum settings on `messages_likes`, for example `autovacuum_vacuum_insert_scale_factor = 0` with a threshold of a few hundred thousand. The default 20% insert trigger means about 30M inserts between vacuums, so the index-only scans would fall back to heap fetches on the newest posts.

Optional: declare `(msgid, userid, type)` as the primary key, and reword the `bounds.go:52-55` comment, which is true only on InnoDB.

**How the synthetic data could mislead**

- **Heap order:** the scratch `messages_likes` is in msgid order, so every probe on it under-reports. My scattered copy mixes 30,000 posts uniformly, which is an upper bound; real views cluster in a post's first days, so views of one post may share some pages.
- **Views per post:** open posts here average 14.9 views (max 119). Production's table has roughly doubled since the July migration note of 75M, so recent posts probably carry several times more, and the per-post cost scales with that.
- **Members:** scratch members have at most about 14 recent views, so the heavy member was planted by me. The planner knew about it because 5,000 of 449k rows is 1.1%; in production it would not.
- **Cache:** `shared_buffers` is 128MB and the temp table used local buffers, so hit/read splits mean nothing. Whether the newest part of a 9.8 GB heap stays cached in production was not measured.
- **Visibility map:** the fix was measured with it fully set by COPY FREEZE. In production the newest pages are the ones Browse reads and the ones not yet vacuumed.

### 11. Routing service nearest-postcode lookup seq-scans the whole locations table on PostgreSQL (cast on the bound defeats the lat/lng indexes)

- **Severity:** slow-at-scale (claimed slow-at-scale). **Verdict:** confirmed. **Fix tried on the scratch database:** yes.
- **Where:** iznik-routing-go/dbsql.go:340 (PostgreSQL text of sqlNearestPostcode; called from iznik-routing-go/geocode.go:21, twice per request from server.go:388-389)
- **How often:** Per GET /v1/group-extent on the routing service (server.go:632, moderator-gated: the ModTools catchment view). Two lookups per request. Low volume, but each request is two full scans of locations.

**Evidence**

The claim holds: the statement is real, is reached on PostgreSQL, and full-scans locations on every call.

Code:
- iznik-routing-go/dbsql.go:333-346 `sqlNearestPostcode()` uses `sqlFor(mysql, pg)`, which returns the hand-written PostgreSQL text when the engine is Postgres (dbsql.go:34-39). No translator is involved.
- The PostgreSQL text is as the claim quotes it: `p.lat BETWEEN ?::double precision-0.1 AND ?::double precision+0.1 AND p.lng BETWEEN ?::double precision-0.15 AND ?::double precision+0.15`.
- The only caller is `resolvePlace` (geocode.go:21), called twice in `handleGroupExtent` (server.go:388-389), route `GET /v1/group-extent` (server.go:632, gated).
- The only frontend caller is `drawGroupExtent` in iznik-nuxt3/modtools/composables/rippling/setupRipplingExplorer.js:509, once per group shown in the ModTools catchment view. No scheduler runs it, so volume is low and moderator-only.

Schema: `locations.lat` and `locations.lng` are `numeric(10,6)` with btree indexes `locations_lat` and `locations_lng` on PostgreSQL, and `decimal(10,6)` with `KEY lat` and `KEY lng` on MySQL.

Test on iznik_perf (2.0M locations, 1.8M postcodes, heap 48,598 pages, 380 MB), as `PREPARE` with six float8 parameters to mimic pgx.

As shipped, point 52.4, -1.5:
```
Parallel Seq Scan on locations p (rows=393.67 loops=3)
  Filter: ((type = 'Postcode'::text) AND ((lat)::double precision >= '52.3'::double precision) AND ((lat)::double precision <= '52.5'::double precision) AND ((lng)::double precision >= '-1.65' ...
  Rows Removed by Filter: 666273
  Buffers: shared hit=496 read=48102
```
- Total buffers were 54,977 (6,733 hit, 48,244 read).
- The planner estimated 45 rows against 1,181 actual.
- With parallelism off, point 57.0, -3.0: `Seq Scan on locations p`, Rows Removed by Filter 1,996,747, 61,588 buffers.
- With `plan_cache_mode=force_generic_plan` it is still a Parallel Seq Scan, so custom or generic plan makes no difference. The cast sits on the column and no index can serve it.

With the four bounds cast `::numeric` instead, same parameters:
```
Parallel Bitmap Heap Scan on locations p
  Recheck Cond: ((lat >= 52.3) AND (lat <= 52.5) AND (lng >= '-1.65'::numeric) AND (lng <= '-1.35'::numeric))
  Heap Blocks: exact=134
  -> BitmapAnd
     -> Bitmap Index Scan on locations_lat (rows=44880) Buffers: shared read=247
     -> Bitmap Index Scan on locations_lng (rows=79031) Buffers: shared read=437
```
- Total buffers were 851 for 52.4, -1.5 and 981 for 57.0, -3.0.
- The forced generic plan is also BitmapAnd, at 5,542 buffers, most of them the 1,181 primary-key probes for the area join.
- Both versions returned the same row (FU97 2SP, Jacksonton 36280).

MySQL today: the DECIMAL columns are compared with constant bounds, so the range optimizer can use `lat` or `lng` (or an index merge) and never scans the table. I reasoned this from the schema; I did not run EXPLAIN on MySQL.

Scale: at 6.5M production rows the scan is about 158k pages (around 1.2 GB) per call, and the handler makes two calls. That is my extrapolation from 48,598 pages at 2.0M rows.

I did not test the claim's KNN alternative on the GiST index.

**Fix**

Change only the PostgreSQL text in iznik-routing-go/dbsql.go `sqlNearestPostcode()`: cast the four bounds to numeric instead of double precision.

```
p.lat BETWEEN ?::numeric-0.1 AND ?::numeric+0.1 AND p.lng BETWEEN ?::numeric-0.15 AND ?::numeric+0.15
```

- **MySQL unchanged:** the MySQL text is the first argument of `sqlFor` and is not touched. No schema or index change is needed.
- **Tested on the scratch database:** as a prepared statement with float8 parameters, the plan moved from Parallel Seq Scan (about 55,000 buffers) to BitmapAnd of `locations_lat` and `locations_lng` (851 to 981 buffers, or 5,542 under a forced generic plan), with the same result row.
- **Not tested through pgx:** I did not run the Go code. If pgx objects to binding a float64 to a numeric parameter, `(?::double precision-0.1)::numeric` keeps the parameter typed as double and gives the same plan shape. `dbengine_integration_test.go:347` covers `resolvePlace` and would show it.
- **Larger alternative:** the claim's KNN on `locations_spatial.geometry <->` would avoid the dense-city band cost, but it changes semantics (planar degrees, no box limit) and I did not try it.

**How the synthetic data could mislead**

The synthetic data probably understates the scan cost and overstates how well the fix works.

- **Heap size:** synthetic locations rows are narrow (380 MB for 2.0M rows). Production rows carry real OSM `geometry` and `ourgeometry` for roads and polygons, so the production heap is likely larger per row than the 158k-page extrapolation assumes.
- **Type mix:** the scratch table is 90% postcodes. Production's mix may differ, which changes the filter's selectivity but not the fact of the full scan.
- **Point density:** synthetic points are uniform over lng -6..2, lat 50..58. Real postcodes are dense in cities, so a 0.2 x 0.3 degree box around London holds far more rows than the 1,181 to 3,253 seen here. Each lat or lng band may match several hundred thousand index entries, and the fixed plan will cost several times the roughly 900 buffers measured, though still far less than a full scan.
- **Buffer split:** shared_buffers is 128 MB, so the hit/read split is not production-like.
- **Empty box:** for a point in the sea or with no postcode in the box, the shipped query still scans everything; the fixed one returns after two index range scans.

### 12. pgx prepares and caches every distinct statement text per connection: the browse feeds inline the viewer id, group ids and watermark, so each backend fills with about 200 KB per variant (up to about 100 MB a backend, about 20 GB at the 200-connection cap)

- **Severity:** slow-at-scale (claimed outage-risk). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/database/postgres.go:190 (stdlib.GetConnector with pgx defaults; pool caps at :160-162 and :175-177). Texts that vary per caller: iznik-server-go/rippling/pendingreach.go:60, iznik-server-go/message/membership.go:86, iznik-server-go/message/groups.go:118
- **How often:** Every database statement the Go API sends. The per-viewer texts are the hottest reads: GET /message/mygroups/:id (message.Groups), GET /isochrone/message and its count, GET /message/inbounds, once per browse page view for every logged-in member.

**Evidence**

The mechanism is real and the per-backend figure reproduces. The 20 GB total is a ceiling that needs every pooled connection warm at once, so I rate it below outage-risk.

**Code (all reached on PostgreSQL, hot path)**
- `iznik-server-go/database/postgres.go:185-191`: `newPostgresDialector` passes `pgx.ParseConfig(dsn)` straight to `stdlib.GetConnector` with only `OptionAfterConnect(registerLenientTypes)`. A grep of the non-test Go code finds no `DefaultQueryExecMode`, `statement_cache_capacity` or `plan_cache_mode` anywhere.
- pgx v5.5.5 `conn.go` (read in the apiv2 container): `Query` and `exec` start from `mode := c.config.DefaultQueryExecMode`, and an LRU cache is created whenever `StatementCacheCapacity > 0`. The defaults (`cache_statement`, 512) are therefore in force.
- Pools: 200 open / 200 idle, lifetime 1 hour on the primary and 5 minutes on the replica resolver.
- `iznik-server-go/rippling/pendingreach.go:57-60` inlines `m_pending.fromuser <> <viewerID>`. `HidePendingReach()` is on unless `RIPPLE_HIDE_PENDING` is set off, so the feed text differs per viewer by default.
- `iznik-server-go/message/groups.go:35` inlines the watermark, and `message/membership.go:79-87` inlines up to 50 group ids.
- MySQL side: `database/database.go:25` sets `interpolateParams=true`, so MySQL keeps no per-statement state.

**Memory, measured on iznik_perf in one psql session**
I hand-rendered the mygroups feed for PostgreSQL ($1..$8 bound, viewer id, watermark and group list inline) and varied only the inlined literals.

| State of the backend | Total context bytes | CachedPlanSource count | Cached* bytes |
|---|---|---|---|
| Baseline | 1,998,488 | 0 | 0 |
| 1 feed prepared and executed | 3,092,176 | 1 | 196,608 |
| 51 prepared | 13,262,640 | 51 | 10,039,392 |
| 513 prepared and executed once | 108,988,384 (88.3 MB used) | 513 | 102,783,120 |
| After `DEALLOCATE ALL` | 3,092,256 | 0 | 0 |

That is about 200 KB per variant and about 103 MB for a full 512-entry cache, as claimed.

**Where the claim overstates**
- 103 MB is the ceiling for a cache holding nothing but feed variants. Ordinary statements cost far less: `SELECT * FROM users WHERE id=$1` 23 KB, `messages` by id 23 KB, a memberships/groups join 39 KB, chat_messages by chatid 12 KB, `browse_cleared` 8 KB. Recurring statements stay at the head of the LRU, so a warm backend sits somewhere between about 11 MB and 103 MB. My estimate is tens of MB; I could not measure the real mix without traffic.
- 20 GB needs 200 backends all warm. 200 is the pool cap, reached only with 200 queries in flight within the connection lifetime. The scratch server's `max_connections` is 100 and the port sets none, so it could not accept 200 today. The real exposure is a pile-up: when the database stalls, the pool grows to its cap and every new backend fills its cache.
- The extra Parse/Describe round trip for a first-seen text is real but small next to the feed itself: `Planning Time: 1.7-4.9 ms`, `Execution Time: 230-288 ms`, `Buffers: shared hit=7424 read=46100` (shared host, indicative only).
- The generic-plan switch happens but is harmless for the feed. After 7 executions `pg_prepared_statements` showed `generic_plans 2, custom_plans 5` and a 269,312-byte CachedPlan context appeared. Buffers were the same either way: force_custom_plan `Buffers: shared hit=7024 read=46497`, force_generic_plan `Buffers: shared hit=7014 read=46507` (53,521 each), because the selective values are inlined literals. A per-viewer text is also almost never executed six times on one connection.
- I did not test the ModTools member-search figures (47,138 against 16,139 buffers).

**Problem with the suggested fix**
`registerLenientTypes` (`iznik-server-go/database/pgtypes.go:109-122`) wraps the int2/int4/int8 and text codecs so a Go bool can bind to an integer parameter and a number to a text parameter. Those codecs are chosen by the parameter OID the server describes. `QueryExecModeExec` and `QueryExecModeSimpleProtocol` take the type from the Go value instead, so a bool would be sent as boolean against a smallint column. This is read from the code and pgx's documented behaviour; I did not run it.

**Fix**

**Smallest fix: PostgreSQL only, MySQL untouched.** In `newPostgresDialector` and the failover connector in `iznik-server-go/database/postgres.go`, set `cfg.DefaultQueryExecMode = pgx.QueryExecModeDescribeExec` before `stdlib.GetConnector`.

- It uses the unnamed statement, so nothing accumulates in the backend, and every statement is planned with its real values, as on MySQL.
- It still describes the parameter OIDs, so the lenient bool-to-int and number-to-text codecs in `pgtypes.go` keep working. `QueryExecModeExec` and `SimpleProtocol`, as the claim suggests, would not give them the OIDs.
- The cost is two round trips for every statement and no reuse of parse work for hot statements.

**Alternative that keeps the cache.** Leave `cache_statement`, lower `statement_cache_capacity` in the DSN (for example 64 to 128, which caps the worst case at about 13 to 26 MB a backend), and set `ALTER ROLE ... SET plan_cache_mode = force_custom_plan`. Also bind the viewer id and watermark as `?` in `pendingreach.go` and `groups.go`. Because MySQL runs with `interpolateParams=true`, a bound uint64 is interpolated client-side to the same decimal text, so MySQL should receive the same statement as today; I did not verify this.

**What was tested.** Only the server-side effect, on iznik_perf: 200 feed variants sent through the unnamed extended-protocol statement (psql `\bind`) left 0 CachedPlanSource contexts and the backend at 2.9 MB, against 103 MB for 513 named ones. `DEALLOCATE ALL` returned a full backend to 3.1 MB. The Go configuration change itself was not run, because the review is read-only with no test runs. Run the Go suite on PostgreSQL after changing the mode.

**How the synthetic data could mislead**

The memory result depends on statement text and schema, not on row counts or data skew, so the synthetic data does not distort the 200 KB per variant figure. Three things could:

- **Hand-rendered SQL.** I wrote the PostgreSQL text by hand (same joins, subqueries and columns; I added `m.fromuser` to the GROUP BY and a cast on `promised`) and did not run the translator. The real text may differ by a few per cent in tree size.
- **Unknown production mix.** The steady-state share of the 512 slots held by large per-viewer texts depends on real traffic, which nothing here models. The same goes for how many backends are live at once, and for the PostgreSQL host's RAM and `max_connections`, which are not decided.
- **psql is not pgx.** psql `PREPARE` and `\bind` stand in for pgx's named and unnamed statements. The server-side memory behaviour is the same, but the client was not pgx.

The generic-plan comparison for the feed used one viewer (user 20, groups 1, 2, 3) on uniform synthetic geography. The equal-buffers result follows from the literals being inline, not from the data.

### 13. The translator cache is keyed on the full SQL text and never evicts: per-viewer feed texts miss on every call (about 0.5 ms to re-translate) and the map grows for the life of the process

- **Severity:** slow-at-scale (claimed slow-at-scale). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** no.
- **Where:** iznik-server-go/database/pgconn.go:59-70 (statement(); the sync.Map is declared at :22)
- **How often:** Per browse page view for logged-in members (GET /message/mygroups, GET /isochrone/message and count, GET /message/inbounds), plus every statement with a variable-length IN list or an inlined id.

**Evidence**

The memory half of the claim holds from the code; the latency half does not. I could not reproduce the translation timings: my Go harness was blocked by the repository's test-command hook, which deliberately forbids `go build` and throwaway toolchain containers, so I did not work round it. The 469 microsecond and 5 KB figures below are the claim's, not mine.

**What the code shows (read, not run)**
- `iznik-server-go/database/pgconn.go:59-70`: `statement()` does `c.cache.Load(q)`, otherwise translates and `c.cache.Store(q, st)`. The `sync.Map` at :22 has no size limit, no eviction and no clear anywhere in the file.
- It is reached on PostgreSQL for every statement: `postgres.go:95` and `:190` wrap every pool in `newPgTranslatingConnector`, and `QueryContext`/`ExecContext` (:227, :267) call `statement(q)`.
- The feed texts do differ per viewer:
  - `rippling/pendingreach.go:60` inlines `m_pending.fromuser <> <viewerID>` (the gate is on by default).
  - `message/membership.go:79-87` inlines `mg.groupid IN (<ids>)` for members of up to 50 groups.
  - `message/groups.go:35` and `isochrone/message.go:209, 368, 757, 897, 930` and `isochrone/reachspatial.go:100` inline the browse watermark.
- Callers are hot: `message/groups.go:126` (GET /message/mygroups), `message/bounds.go:269`, `isochrone/message.go:723, 898, 931`.
- `pgconn.go:64` sets `jsonArgs` for any text containing `concat(`, and :230/:270 then call `typeJSONParams`, which runs `lexSQL` on every execution (:311). That part of the claim is accurate as written.

**Where the claim overstates**
- "Miss on every call" is wrong. The key is the full text, so the same member with the same groups and watermark hits the cache on every later call. A miss happens once per distinct (viewer, group set, watermark) per shape per process.
- The translation cost is immaterial next to the query. I ran the spatial arm of the mygroups feed on `iznik_perf`, hand-translated, for viewer 20 (groups 5, 6, 7; no member in the synthetic data has more than three):
  - `Planning Time: 3.804 ms`
  - `JIT: ... Total 31.806 ms`
  - `Execution Time: 245.906 ms`, `Buffers: shared hit=17057 read=25471`
  - Dominant node: `Bitmap Heap Scan on messages_groups mg ... rows=86499 ... Heap Blocks: exact=38405`
- Against that, the claimed 0.47 ms is about 0.2% of the request's database time, and an eighth of PostgreSQL's own planning for the same text.

**What survives**
- The map grows by one entry (key text plus translated text) per distinct statement text for the life of the process. That is a leak proportional to distinct members served times feed shapes, plus one entry per distinct IN-list length.
- The claim's "about a gigabyte per day" is arithmetic on an assumed member count and I could not measure it. The mechanism is certain; the rate is not.

**Side finding, not tested**
- The plan above (hash of 86k `messages_groups` rows to keep 376 posts, 42k buffers) is a query-shape issue separate from this claim.

**Fix**

Bound the cache in `pgconn.go` only. Keep an atomic entry counter beside the `sync.Map`; when a `Store` takes it past a cap (say 20,000 entries), call `c.cache.Clear()` (available in the Go 1.23 the module uses) and reset the counter. Optionally skip storing texts above a size limit (say 16 KB) so 10,000-placeholder chunks are never retained. That is a few lines in a PostgreSQL-only file and leaves every MySQL statement exactly as it is.

I would not take the claim's other suggestion of binding the viewer id and group list as parameters: it changes the MySQL statement text, and `message/membership.go` inlines the group ids deliberately so MySQL can use the (msgid, groupid) index.

The `typeJSONParams` re-lex could be decided once at translation time, but at tens of microseconds per execution it is optional.

Not tested: it is a Go change, and building Go outside the status API is blocked here.

**How the synthetic data could mislead**

The synthetic data has little bearing on the cache finding, which is about Go heap, not the database. The growth rate depends on numbers the scratch database cannot give: distinct logged-in members browsing per day, how often each member's watermark or group set changes, how many API processes share the traffic and how long a process lives between deploys.

For the feed plan used as the comparison:
- No member here is in more than three groups; production members in 5 to 12 groups produce longer texts and different plans.
- `messages_spatial` has 12,434 rows against roughly 27,000 candidates quoted in the code comment for production.
- Server settings are defaults (128 MB shared_buffers, JIT on), so the 246 ms and the 32 ms of JIT would differ in production.

None of that changes the conclusion that translation is a small fraction of the request.

### 14. The mygroups feed's inlined group-id list, tuned for MySQL's per-post index probe, is planned as a hash semi-join over the whole Approved history of the member's groups

- **Severity:** slow-at-scale (claimed slow-at-scale). **Verdict:** confirmed. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/message/membership.go:85-87 (used at iznik-server-go/message/groups.go:62 and iznik-server-go/isochrone/message.go:716, :885, :925)
- **How often:** GET /message/mygroups/:id and GET /isochrone/message (feed and unseen count): per browse page view for every logged-in member. The code comment calls these four of the top spatial queries on the production database.

**Evidence**

The claim reproduces. The mechanism is real and the site is hot, but the gap is 3-4x only for members of large groups and under 2x for typical members.

CODE
- iznik-server-go/message/membership.go:85-87 renders `EXISTS (SELECT 1 FROM messages_groups mg WHERE mg.msgid = <col> AND mg.groupid IN (<constants>) AND mg.collection = 'Approved' AND mg.deleted = 0)`.
- It is used live at message/groups.go:62 (the mygroups feed when gid=0) and at isochrone/message.go:716, :885 and :925. It is request-path code, not batch or test-only.
- The group ids are inlined as constants and nothing in the predicate is MySQL-specific, so PostgreSQL receives it as written. To run the spatial arm by hand I only had to wrap `m.fromuser` in ANY_VALUE.
- I ran the spatial arm of groups.go:82-127, including the visible_since subquery and ReachPendingFilter, with viewer 40700. I did not run the full UNION or the isochrone variants.

DEFAULT PLAN, 12 biggest groups (1..12)
```
Hash Right Semi Join (cost=120173.61..175931.95 rows=1472) (actual rows=1953)
  -> Bitmap Heap Scan on messages_groups mg (rows=468879) (actual rows=472982)
       Recheck Cond: (groupid = ANY ('{1,...,12}'))
       Rows Removed by Filter: 20286
       Heap Blocks: exact=43927
       Buffers: shared read=45819
     -> Bitmap Index Scan on messages_groups_groupid_3
  -> Hash -> Seq Scan on messages_spatial (actual rows=12434)
Statement Buffers: shared hit=14090 read=47829; Execution 262-288 ms with jit off
```
- Row estimates are accurate (468,879 estimated, 472,982 actual), so this is a costing choice, not a misestimate.
- No index carries (groupid, msgid, collection, deleted), so every history row costs a heap page, about one page per row.

PROBE PLAN (enable_hashjoin and enable_mergejoin off)
```
Nested Loop Semi Join (cost=0.72..353313.55)
  -> Index Scan using messages_groups_msgid on messages_groups mg (cost=0.43..26.15) (actual rows=0.16 loops=12434)
       Index Cond: ((msgid = messages_spatial.msgid) AND (groupid = ANY (...)))
       Buffers: shared hit=39248 read=7
Statement Buffers: hit=54195 read=1198
```
- The planner prices each 12-element probe at 26.15, so 353k against 176k for the hash. The probe actually took about 3 buffer hits.
- `random_page_cost = 1.1` with `effective_cache_size = 48GB` still chose the hash plan (cost 98,707).

OTHER MEMBER SHAPES (jit off, default plan against a forced probe)

| Groups | Default plan | Default buffers | Default time | Probe time |
|---|---|---|---|---|
| Group 1 only (137,545 rows) | Bitmap Heap Scan, 131,915 rows, Heap Blocks 40,527 | 46k, about 37-42k read | 198-231 ms | 38-65 ms |
| 200, 201, 202 | Merge Semi Join over Bitmap Heap Scan, 14,456 rows, Heap Blocks 12,309 | 13.3k | 98 ms | 62-72 ms |
| 480 (small) | Bitmap Heap Scan, 3,443 rows | 10.5k | 34 ms | 40 ms (no gain) |

SCALE
- The default plan's work follows the lifetime post count of the member's groups: 3.3x more in production, and growing.
- The probe follows the live posts (12,434 here; the code comment says about 27,000 in production).
- The group's history is not hot data. Here it was read from outside shared_buffers on every run.

A SEPARATE COST: JIT
- jit is on by default and nothing in the port turns it off; I grepped iznik-server-go/database/*.go, the compose files and database-engines.md.
- The default plan paid about 28 ms of JIT.
- A forced-probe rewrite is costed at 905k, which crosses jit_optimize_above_cost: `JIT: Inlining 84.6 ms, Optimization 157.7 ms, Emission 123.9 ms, Total 368.7 ms` on a statement that executes in about 65 ms.
- The claim's "87 ms vs 266-354 ms" comparison therefore includes JIT noise. The buffer counts are the reliable part.

**Fix**

Add a PostgreSQL-only partial index. MySQL statements and the Go code stay exactly as they are:

`CREATE INDEX messages_groups_approved_live ON messages_groups (groupid, msgid) WHERE collection = 'Approved' AND deleted = 0;`

It needs a home in the PostgreSQL schema generation. I found no existing partial-index or extra-index mechanism in tools/pg-schema/config.json or convert.mjs, and baseline.sql has no `CREATE INDEX ... WHERE`, so a small "extra indexes" hook is needed.

Tested in BEGIN ... ROLLBACK, jit off. The planner keeps its history-first join, but the history side becomes `Index Only Scan using trial_a ... Heap Fetches: 0` in place of the Bitmap Heap Scan:

| Groups | Before | After |
|---|---|---|
| 1..12 | 262-288 ms, 47.8k buffers read | 136 ms, 4k read |
| Group 1 only | 198-231 ms, 46k buffers | 88 ms, 5.8k buffers |
| 200, 201, 202 | 98 ms, 13.3k buffers | 26 ms, 2.0k buffers |
| 480 | 34 ms, 10.5k buffers | 17 ms, 7.5k buffers |

The work still grows with the groups' history, but as a sequential index range at about 250 entries a page, not one heap page per row.

Alternatives I tried:
- **Partial index on (msgid, groupid) with the same WHERE:** no plan change (still Bitmap Heap Scan, Heap Blocks 43,927).
- **`OFFSET 0` inside the EXISTS in a PostgreSQL-only code branch:** this forces the per-post probe (SubPlan, loops=12434, about 3 buffers each) and gave 38-107 ms for the big cases. It is slightly slower for a small single group (40 ms against 34 ms), and its 905k cost estimate triggers 369 ms of JIT unless jit is off or jit_above_cost is raised. Not recommended on its own.

Whichever is chosen, set `jit = off` for the API's PostgreSQL connections or raise the jit thresholds. That is a 28 ms saving per call on the default plan and is PostgreSQL-only.

**How the synthetic data could mislead**

- **Group count per member:** no synthetic member is in more than 3 groups (median 1, p99 3). The "12 biggest groups" case is adversarial, not a real member. Real members in 12 groups exist (the code comment measures one), but those would not be the 12 largest.
- **Group size skew:** group 1 holds 137k of 3.2M messages_groups rows (4.3%). If production's largest communities are less dominant, the worst case shrinks; the typical case (5-20k rows per group here, about 21k average in production) is the 1-2x gap.
- **Live-to-history ratio:** messages are spread evenly over ten years, giving about 257 history rows per live post. Production is about 260-390 to 1 by the quoted figures, so this looks fair.
- **Cache:** shared_buffers is 128MB, so the history heap pages were always read from outside it. With messages_groups fully cached in production the default plan costs CPU, not I/O, and the gap narrows towards 2-3x.
- **Row width:** synthetic messages_groups rows are narrow. Real rows with spamreason and contentcheck text are wider, which favours the index-only fix further.
- **Index-only scan:** the trial index showed `Heap Fetches: 0` because the table was just vacuumed. In production, recent messages_groups pages are updated often, so some heap fetches will occur on the newest rows.
- **Rippled posts:** only 5% of synthetic posts have a second group row. Rippling in production gives posts more rows, which makes the hash side larger.

### 15. Go API opens up to 400 PostgreSQL backends per process against a server that allows 100, and the layer depends on session prepared statements that the planned transaction pooler breaks

- **Severity:** slow-at-scale (claimed outage-risk). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** no.
- **Where:** iznik-server-go/database/postgres.go:160-162 and :175-177 (pool sizes), :186-190 (pgx config with default exec mode); session-scoped temp tables at iznik-batch/app/Services/AuthorityStatsService.php:510-631 and iznik-batch/app/Console/Commands/AI/UpdateAIImageUsageCountsCommand.php:106-196
- **How often:** Every API request; pool growth happens under load peaks (the plan records 306 peak MySQL connections today). The temp-table jobs are scheduled batch commands.

**Evidence**

Nothing here is a query plan, so the evidence is code reading plus one memory measurement on iznik_perf. I did not open 100+ connections to prove the "too many clients" failure, because the server is shared with the test databases and exhausting it would break other people's runs. No pgbouncer was run.

**What holds**

- **Pool sizes are as claimed.** `iznik-server-go/database/postgres.go:160-162` and `:175-177` set `SetMaxOpenConns(200)`, `SetMaxIdleConns(200)` on both pools, copied from `database.go:113-119` and `:155-157`. The replica pool exists only when `PGSQL_HOST_READ` is set, so it is 200 per process without a replica and 400 with one.
- **The server limit is 100.** On the scratch server `max_connections|100`, `superuser_reserved_connections|3`. The `postgres` service in `docker-compose.yml` (lines 92-113) has no command or config. MySQL gets `max_connections = 500` from `conf/percona-my.cnf:2`; PostgreSQL has no equivalent file.
- **Prepared statements are per backend.** `newPostgresDialector` (`postgres.go:186-190`) uses `pgx.ParseConfig` with no exec-mode override, and nothing in non-test Go code sets `QueryExecMode`, `PreferSimpleProtocol` or `statement_cache_capacity`. pgx v5.5.5 therefore caches up to 512 named statements per connection.
- **Memory cost, measured.** One psql session, 512 distinct `PREPARE`s of a four-table messages/messages_groups/users/messages_outcomes query with a correlated count, each executed six times:
  - start: 1,544 kB total in `pg_backend_memory_contexts`
  - after 512 PREPAREs: 44,184 kB
  - after executions (`generic_plans > 0` on all 512): 78,239 kB
  - `CachedPlan|512|32768 kB`, `CachedPlanQuery|512|32768 kB`, `CachedPlanSource|512|8192 kB`
  - backend `RssAnon` 80,080 kB
  
  That is about 150 kB per statement, so up to roughly 75 MB of private memory per backend at a full cache: about 15 GB at 200 backends, 30 GB at 400. MySQL with `interpolateParams=true` (`database.go:25`) spends none of this.
- **The translator cache has no bound.** `pgconn.go:23` is a `sync.Map` filled at `pgconn.go:69` with no eviction. Call sites that inline id lists into the SQL text (for example `ChatRoomListFrom(idlist)` in `chat/chatroom.go:2247`) produce unlimited distinct texts. Each one also costs a Parse/Describe round trip and evicts something from the 512-entry cache, where MySQL does one round trip.
- **The two Laravel temp-table jobs have no transaction.**
  - `AuthorityStatsService::getByAuthority` (`AuthorityStatsService.php:510-631`): drop, `Schema::create(... temporary())`, `insertUsing`, four later `DB::table('pc')` reads, drop.
  - `UpdateAIImageUsageCountsCommand::fullRecompute` (lines 106-196): the same shape, with a `lazyById(500)` loop of single-row updates reading the temp table.
  - Schedule: `ai:usage-counts:update --full` runs `dailyAt('02:45')` (`routes/console.php:1843`); `partnerships:stats:run` runs `everyMinute` but takes one queued job (`:2145`).
- **Simple protocol would break at least one lenient codec.** `select 1 where 'abc'::text = 5` gives `ERROR: operator does not exist: text = integer`, so the number-for-text leniency in `pgtypes.go:89-118` does need described parameter types. The bool-for-smallint half looks less fragile: `users.bouncing = true` ran on the scratch schema, so some bool leniency exists server-side. I did not trace where it comes from, so it may be test-mode only.

**What does not hold, or is smaller**

- **"Up to 400 backends" is a ceiling, not a load.** `database/sql` opens connections on demand, so the count follows concurrent in-flight queries. The 306 peak in `plans/database-migration-evaluation-2026-07.md:84` is across all MySQL clients, and it does exceed 97 usable slots, so the limit would be hit, but through an unset server parameter rather than anything in the port's SQL.
- **The retry gap is partly parity.** `retry.go:47-49` does not retry MySQL's "Too many connections" either. It is true that no PostgreSQL connection-loss wording is matched there; `database/errors.go` does handle `pgconn.PgError` for other cases.
- **Both temp-table jobs work today.** Each runs on one PDO connection with no pooler. They break only behind a transaction pooler that is not in the repository. The Go equivalent (`authority/stats.go:42-54`) is already pinned inside a transaction.
- **Other session state is thin.** No advisory locks and no session `SET` in PostgreSQL paths; `TimeZone=UTC` is a startup parameter (`postgres.go:67`). One addition the claim missed: the Laravel `pgsql` connection (`config/database.php:140-152`) sets no emulate-prepares option, so PDO also uses server-side prepared statements, the same pooler exposure as pgx.
- **The 5-minute replica lifetime** is copied from MySQL. Its extra cost on PostgreSQL (fork plus re-prepare) is real but small: 200 connections per 300 s is under one per second. It also caps how full each replica backend's plan cache gets.

**Fix**

Not tested: none of this can be tried as a rolled-back change on the scratch database. Every item is PostgreSQL-only and leaves MySQL statements and `database.go` untouched.

1. **Size the pools in `initPostgres` only** (`postgres.go:160-162`, `:175-177`). Read them from env with a default of tens, set `MaxIdleConns` below `MaxOpenConns`, and give the PostgreSQL service an explicit `max_connections` (a `command: postgres -c max_connections=...` in `docker-compose.yml`, mirroring `conf/percona-my.cnf`).
2. **Bound per-backend plan memory.** Add `statement_cache_capacity=<small>` to the DSN built in `buildPostgresDSN`. If a transaction pooler is chosen, require pgbouncer 1.21+ with `max_prepared_statements`; do not switch to simple protocol, because the number-for-text codec depends on described types.
3. **Wrap the two Laravel temp-table jobs in `DB::transaction`.** That is harmless on MySQL and makes them pooler-safe, but the nightly AI recompute would then hold one long transaction, so it may be better pointed at a non-pooled session connection.
4. **Add PostgreSQL connection errors to `isConnectionError`** (SQLSTATE 57P01, 08xxx, and optionally 53300) via `pgconn.PgError`. This only adds matches.
5. **Cap `pgTranslatingConnector.cache`** with an LRU or a size limit.

**How the synthetic data could mislead**

The synthetic data barely matters here: connection limits and pooler semantics do not depend on rows. Three things could mislead.

- The memory figure comes from one fairly heavy four-table statement repeated 512 times with all generic plans built. The real API mix has many simpler statements, and connections recycled after 5 minutes (replica) will not fill the cache, so 75 MB per backend is near an upper bound, not an average. The long chat-list and browse statements would cost more each.
- `max_connections = 100` and `shared_buffers = 128MB` are stock image defaults on a dev container. A production PostgreSQL server would be configured separately, and no such config exists in the branch to judge.
- Real concurrency (how many of the 306 peak MySQL connections belong to the Go API rather than batch or PHP) was not measurable here.

### 16. ModTools member name/email search: without the FORCE INDEX, PostgreSQL walks the whole memberships primary key backwards for a large community, the exact plan the hint was added to stop

- **Severity:** worth-measuring (claimed slow-at-scale). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/membership/membership.go:701 (searchTx; executed at about line 772)
- **How often:** Per moderator search in ModTools Members: GET /api/memberships with a non-numeric search term (go API). Interactive, a few hundred to a few thousand a day; the comment cites Discourse 10179 for the production incident that led to the hint.

**Evidence**

**Code.** The statement is real and live: `iznik-server-go/membership/membership.go` lines 701-706 (`searchTx`) and 769-771, reached from `GET /memberships` (`router/routes.go:2241`) whenever the search term is not a number. It is interactive, one query per moderator search. `pgtranslate_more.go:54-83` removes `FORCE INDEX (...)`, and `pgtranslate_group.go` wraps the ungrouped columns in `ANY_VALUE()`.

**What I ran.** The full select list (all `m.` columns including `settings`, plus a mail-delayed subselect), not the claim's three-column version. The ModTools client asks for `limit=10` first (`iznik-nuxt3/modtools/composables/useModMembers.js:11`), not 100, so I tested both. The term `zzqqxx` matches nobody.

**The mechanism reproduces.** Group 1 (45,116 Approved members), `max_parallel_workers_per_gather = 0`:
```
Index Scan Backward using memberships_pkey on memberships m (rows=45314) (actual rows=45116)
  Filter: ((groupid = 1) AND (collection = 'Approved'))
  Rows Removed by Filter: 1504884
  Buffers: shared hit=72 read=28584
```
- The query touches 407,179 buffers in total; the planner estimated 376 matches and got 0.
- With default settings it picks the parallel version of the same walk (2 workers).

**The claimed 4x gap does not reproduce.** The fenced form (`OFFSET 0` subquery) with the real select list uses the same nested loops:
```
Bitmap Heap Scan on memberships ... Heap Blocks: exact=19991
```
- It touches 398,574 buffers against 407,179, a 2% difference.
- About 378,000 buffers in either plan are the 45,116 probes each into `users` and `users_emails`, which no access path avoids.
- The claim's 109,741 figure came from its reduced select list letting the planner hash-join; it is not the cost of the primary-key walk.
- The extra cost of the bad plan is bounded by one ordered pass over `memberships`: 28,656 buffers here, roughly 95,000 at production row counts.

**The claim's "smaller communities are not affected" is wrong at limit 10.** Mid-size communities are where the ratio is worst:

| Group | Approved members | Plan as translated | Buffers | Fenced buffers | Ratio |
|---|---|---|---|---|---|
| 1 | 45,116 | pkey backward | 407,179 | 398,574 | 1.02x |
| 20 | 7,417 | pkey backward, 1,542,583 rows removed | 91,075 | 68,888 | 1.32x |
| 48 | 4,923 | parallel pkey backward | 74,200 | 45,924 | 1.62x |
| 83 | 3,607 | bitmap on the group index | 33,737 | 33,725 | none |
| 400 | 1,766 | group index | 16,589 | 16,589 | none |

Groups 123 and 212 also use the group index.

**Common terms.** For `Smith` on group 1 the primary-key walk stops after 515 memberships and 5,666 buffers. That is better than what MySQL's hint allows, since the hint forces a read of all 45,000.

**Against MySQL.** The hinted MySQL plan reads the whole community and probes `users` and `users_emails` per member; the code comment records 0.9s for that and 13.0s unhinted. PostgreSQL's worst case here is that same work plus one pass over the `memberships` heap. I did not test the `groupid = 0` form or the `message_list.go` branch the claim mentions.

**Fix**

**Recommended: a PostgreSQL-only index on `memberships (groupid, collection, id)`**, added through the schema generator (`tools/pg-schema/convert.mjs` or its config; I did not confirm the config has an extra-index hook). MySQL statements, hints and schema stay exactly as they are.

Tried in a rolled-back transaction at `limit=10` (index count back to 12 afterwards):
- **Group 20, no match:** `Index Scan Backward using trial_mem_g_c_id`, 68,869 buffers against 91,075. That equals the fenced plan, with no rows removed by filter.
- **Group 20, `Smith`:** stops after 666 memberships, 6,315 buffers, so the early stop for common terms is kept.
- **Group 1:** the planner still chose the primary-key walk (411,173 buffers). The index does not help there, but the excess for that group is only about 2%.

**Rejected: `ORDER BY m.id + 0`.** It does stop the primary-key walk, but it loses the early stop. `Smith` on group 1 went from 5,666 to 239,904 buffers, and the no-match case spilled a sort to temp.

**Not needed for correctness: the claim's `MATERIALIZED` CTE or `OFFSET 0` rewrite.** It would need a PostgreSQL-only branch in Go, and like the MySQL hint it forces a read of the whole community even for common terms.

If nothing is done, the cost is one extra pass over `memberships` per rare-term search in communities above roughly 5,000 members on this data.

**How the synthetic data could mislead**

- **Physical order.** Synthetic `memberships` rows sit in id order, so the backward primary-key walk is close to a sequential read of 28,600 pages. If production rows end up less correlated after years of updates, the walk costs more per row, though the planner would also price it higher and choose it less.
- **Row width.** `memberships.settings` is small filler here. Wider real rows make the full pass proportionally bigger while leaving the per-member probes unchanged, so the real ratio could exceed 1.6x.
- **Flip point.** Group sizes are not scaled (500 groups, largest 45,000, against 5.1M production memberships). The community size where the planner switches to the primary key depends on the group-to-table ratio and the 0.83% default LIKE selectivity, so it will sit elsewhere in production.
- **Names and terms.** Names are low-cardinality filler, so only the nobody-matches and very-common cases were tested, not a realistic two-hit term. The plan is chosen before the term's rarity is known, so a two-hit term should behave like the no-match case.
- **Server settings.** `shared_buffers` is 128MB, so hit/read splits mean little. Timings (404ms against 136ms for group 20) are from a shared host and are indicative only.

### 17. The CRC32 shard predicate calls a PL/pgSQL crc32() per active user, re-evaluated on every 500-row chunk of the daily digest (and of push:daily-posts)

- **Severity:** worth-measuring (claimed slow-at-scale). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-batch/app/Services/UnifiedDigestService.php:1745 (function: iznik-batch/database/postgres/functions.sql:199; also app/Console/Commands/Push/SendDailyPostsPushCommand.php:148)
- **How often:** mail:digest:unified --mode=daily is scheduled everyMinute for each of 8 shards (routes/console.php:961); a sweep is one statement per 500 recipients. push:daily-posts runs 4 shards (routes/console.php:1099).

**Evidence**

The mechanism is real and measured; the impact is smaller than claimed, and the suggested fix only gets a quarter of the gain.

**Code**
- `iznik-batch/app/Services/UnifiedDigestService.php:1745` and `iznik-batch/app/Console/Commands/Push/SendDailyPostsPushCommand.php:148` both build `Comparison(Arithmetic(Crc32('users.id'), '%', $shards), '=', $shard)`.
- From `Expressions/Crc32.php`, `Arithmetic.php` and `Comparison.php` this renders on PostgreSQL as `(CRC32(CAST("users"."id" AS text)) % 8) = 1`. I read the rendering from the classes; I did not run Laravel.
- `\df+ crc32` shows `public.crc32(s text)` in plpgsql (`functions.sql:199`) beside the built-in `pg_catalog.crc32(bytea)`. A text argument resolves to the PL/pgSQL one.
- Schedule in `routes/console.php`: line 961 runs daily mode `everyMinute` for 8 shards between 07:00 and 12:00, with a flock per shard. Line 1099 runs `push:daily-posts` every 30 minutes for 4 shards.
- `streamDailyOverdueFirst` (lines 1894-1951) re-runs the whole eligibility query per 500-row chunk in both phases. Phase 2 sorts by `lastsent`, so every chunk filters every active user. MySQL does the same; the only PostgreSQL-specific cost is the function.

**Per-call cost** (400,000 calls, `Index Only Scan using users_id`, `Filter: ((crc32((users.id)::text) % 8) = 1)`, JIT off, no parallelism)

| Definition | Execution time | Per call |
|---|---|---|
| As shipped (plpgsql) | 2,542 ms | about 6.3 microseconds |
| Claim's fix: `LANGUAGE sql IMMUTABLE` | 606 ms | about 1.5 microseconds |
| `LANGUAGE sql STABLE` | 123 ms | about 0.3 microseconds |

- The claim's fix is not inlined: the plan still shows `crc32((users.id)::text)`. `convert_to(text, name)` has `provolatile = 's'`, and an IMMUTABLE SQL function with a STABLE body cannot be inlined.
- Declared STABLE it inlines: the plan shows `crc32(convert_to((users.id)::text, 'UTF8'::name))`.
- The built-in and the PL/pgSQL function agree on all of 1..200,000 (0 mismatches).

**Active-user filter alone** (the claim's test)
- PL/pgSQL: `Bitmap Heap Scan on users ... rows=1618 ... actual rows=40622`, `Rows Removed by Filter: 300961`, 2,620 ms. The planner picks a `BitmapAnd` of `users_tnuserid` and `users_added` because the function's cost is 100.
- Built-in: `Seq Scan`, 321 ms. No crc test: 240 ms.

**Full phase 2 chunk** (the claim's `q11.sql`, re-run with the rendered predicate)

| Statement | As shipped | sql IMMUTABLE | sql STABLE |
|---|---|---|---|
| Phase 2 chunk (500 rows) | 3,376 ms | 2,145 ms | 561 ms |
| Phase 1 first chunk | 4,289 ms | 1,963 ms | 784 ms |
| Phase 2 terminal empty chunk | 3,268 ms | 1,668 ms | 412 ms |

- In the shipped plan the time is all in `Bitmap Heap Scan on users (actual time=81.689..3201.368 rows=40622)`.
- The estimate there is 1,614 rows against 40,622 actual with every definition. That only makes the hash go to 4 batches; the join order is the same.

**Why it is smaller than claimed**
- The CRC cost is about 2.2 to 2.8 s per statement here, so roughly 7 to 9 s at 3.26x users, if production's 182-day-active non-TrashNothing population is in proportion.
- A worker sends about 250 mails a minute (`console.php:1110`), so a 500-row chunk is about two minutes of sending. The CRC adds around 5% to a sweep.
- The daily population is about 79k (`console.php:973`), so about 10k a shard and about 20 phase 2 chunks, not the claim's 100.
- The steadier cost is the idle tick. After a shard finishes, each every-minute relaunch runs one empty phase 1 and one empty phase 2 statement. Both pay the full CRC cost before the once-a-day anti-join can discard anyone. At 3.26x that is roughly 14 CPU-seconds per shard per minute across 8 shards until 12:00.
- `push:daily-posts` uses `lazyById(200)`, so it pays per row once, as the claim says.
- The claim's last line is wrong: the Go API does not call SQL crc32. It uses Go's `hash/crc32` (`ux/serverarm.go:70`, `firstreply/passthrough.go:91`). The only other SQL caller is `FirstReply/Rollout.php:92` and `:107`.

**Fix**

In `iznik-batch/database/postgres/functions.sql`, replace the PL/pgSQL body with a SQL wrapper declared STABLE, not IMMUTABLE:

`CREATE OR REPLACE FUNCTION crc32(s text) RETURNS bigint LANGUAGE sql STABLE STRICT PARALLEL SAFE AS $$ SELECT pg_catalog.crc32(convert_to(s, 'UTF8')) $$;`

- **Why STABLE:** `convert_to` is STABLE, so an IMMUTABLE wrapper (the claim's version) is not inlined and only gets about 4x. STABLE inlines to the C built-in and gets about 20x.
- **What STABLE costs:** the function could no longer be used in an index expression or generated column. Nothing does: there is no crc32 in `baseline.sql`, `test-mode.sql` or `tools/pg-schema`.
- **Results agree:** identical on ids 1..200,000, and on '1234567' (1342400927), '', NULL, '42|firstreply' and a multibyte string.
- **Tried on iznik_perf inside BEGIN ... ROLLBACK:** the phase 2 chunk went from 3,376 ms to 561 ms, and the planner dropped the `BitmapAnd` for a single bitmap scan on `users_added`. I confirmed afterwards that the function is plpgsql again.
- **MySQL:** unchanged. This is a PostgreSQL-only file and the PHP and rendered SQL stay as they are.

Optional and separate, because it changes PHP on both engines: fetch the shard's ordered `(lastsent, id)` list once in phase 2, and skip the two statements on an idle tick. Not tested.

**How the synthetic data could mislead**

- **Active share:** the synthetic users table has 34% of members active in the last 182 days (341k of 1M reach the crc test). The per-statement cost is linear in that count. If production's active non-TrashNothing share is nearer 10%, the cost is 2 to 3 s a chunk, not 7 to 9 s.
- **Digest state:** the synthetic `users_digests` has 200k daily rows, all stamped in the last 24 hours and about half of them today. That does not match a real 07:00 state, where nobody has been sent today. This changes how many rows the joins see, but not the CRC cost, which is paid at the users scan before any join.
- **Id length:** synthetic ids are at most 7 digits. Production ids are longer because of the Galera auto-increment stride, so the PL/pgSQL loop runs 8 to 10 bytes a call there, somewhat more than measured.
- **Cache:** shared_buffers is 128 MB, so the users heap is read from the OS cache each time. The 180 to 320 ms scan floor would differ in production; the CRC delta would not.

### 18. Expiry sweep parses groups.settings as jsonb up to about 20 times per (post, group) row: 23 s of a 32 s statement at one third of production size

- **Severity:** worth-measuring (claimed slow-at-scale). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-batch/app/Services/MessageExpiryService.php:280 (rendering: app/Database/Expressions/JsonNumber.php:30-36)
- **How often:** messages:process-expired --spatial, daily (routes/console.php:1725); one statement per run, returning up to 20,000 posts.

**Evidence**

The mechanism is real, but the claim overstates the parse count and the PostgreSQL-specific share.

**Code**
- The statement is real: `iznik-batch/app/Services/MessageExpiryService.php` lines 280-345 (`getExpiredCandidates`), bound with lookback 730 days and LIMIT 20000.
- It runs once a day: `routes/console.php:1725`, `messages:process-expired --spatial`, `dailyAt('03:00')`, `runInBackground`, `withoutOverlapping(360)`. No member waits on it.
- `JsonNumber.php:30-36` renders as the claim says: `CAST(g.settings AS jsonb) #> path` appears three times per use. `groups.settings` is `text` in iznik_perf and `longtext` on MySQL.
- I did not render the statement through Laravel (artisan is off limits). I used the claim's hand-built q16b.sql, checked against the expression classes. Two differences, neither affecting the plan: `TimestampDiff` adds `CAST(... AS bigint)`, and the real LIMIT is 20000 (I ran with 20000, the claim used 2000).

**Reproduced on iznik_perf (730 days, LIMIT 20000)**
- Execution Time 36,472 ms; Buffers shared hit=15,830,034 read=174,679.
- The node that matters: `Index Scan using groups_id on groups g (actual time=0.050..0.050 rows=0.06 loops=531793)`, `Buffers: shared hit=1595199 read=180`. That is about 26 s on three cached buffers per loop, so it is CPU in the filter expression.

**Where the claim is wrong**
- It is 5 parses per row here, not 15 to 20. Both CASE expressions are lazy: only one `m.type` arm runs, and only the matching `WHEN` branch of each JsonNumber runs.
  - A numeric path costs 2 parses, a missing path costs 1. The synthetic settings have no `maxagetoshow`, so 1 + 2 + 2 = 5. A group that sets it would cost 6.
  - Proof: with each JsonNumber wrapped in a scalar subquery, the plan shows `SubPlan 3 loops=531794`, `SubPlan 4/5 loops=404542` (Offer arm) and `SubPlan 6/7 loops=127252` (Wanted arm), each 0.009 ms. That is three JsonNumbers per row at about 9 microseconds a parse.
- MySQL pays most of this too. It also re-parses the longtext on every `JSON_EXTRACT`, three times per row.
  - Rough check, on a shared host and so indicative only: `SELECT BENCHMARK(500000, JSON_EXTRACT(@s,'$.reposts.offer')+0)` on the Percona container, with the same 519-byte string and no table touched, took 4.1 s, about 8 microseconds a call.
  - PostgreSQL microbench (500 groups x 1000 rows): one `settings::jsonb` parse about 8-9 microseconds a row; one full JsonNumber about 16-21.
  - So the PostgreSQL excess is about 2 extra parses per row. That is roughly 10 s of the 26 s on this node, not all 23 s the claim attributes to it.

**Fixes tried**

| Variant | `groups g` node per loop | Execution Time |
|---|---|---|
| As rendered today | 0.050 ms | 36,472 ms |
| `settings` already jsonb (session temp table `groups(id, settings jsonb)` with a primary key) | 0.003 ms | 8,684 ms |
| JsonNumber parsing once (scalar subquery with OFFSET 0) | 0.031 ms | 24,947 ms |
| JsonNumber using `json` / `json_typeof` instead of jsonb | 0.030 ms | 23,391 ms |

- A SQL helper function does not help: PostgreSQL inlined it and the plan still showed the duplicated CASE.

**The planner evaluates the OR arm twice**
- The filter on `messages m` reads `(EXISTS(SubPlan 1) OR (NOT EXISTS(2) AND NOT EXISTS(3) AND NOT EXISTS(4))) AND (EXISTS(SubPlan 1) OR (live.arrival < ... AND NOT EXISTS(2) AND NOT EXISTS(3) AND NOT EXISTS(4)))`.
- Qualifying rows therefore run every subplan twice: SubPlan 2 loops=750,097 for 613,764 outer rows; SubPlan 3 loops=487,961 for about 261k postings with no outcome.
- That doubles the JSON cost on PostgreSQL independently of JsonNumber.

I did not check the other sites the claim lists (`Spatial::memberCoordinate`, the Stories services).

**Fix**

Two PostgreSQL-only options; both leave MySQL's statement byte-for-byte unchanged.

1. **Smallest: make `JsonNumber`'s PostgreSQL branch parse once.**
   - In `app/Database/Expressions/JsonNumber.php`, render for example `(SELECT CASE jsonb_typeof(v) WHEN 'number' THEN (v #>> '{}')::numeric WHEN 'boolean' THEN (CASE WHEN v = 'true'::jsonb THEN 1 ELSE 0 END) END FROM (SELECT CAST(col AS jsonb) #> path AS v OFFSET 0) j)`.
   - The `OFFSET 0` is needed, or the planner flattens the subquery and duplicates the cast again.
   - Tested: the `groups g` node went from 0.050 to 0.031 ms per loop, and the statement from 36.5 s to 24.9 s. This brings it to MySQL's three parses per row.
   - It also helps every other JsonNumber caller. Using `json` / `json_typeof` instead of jsonb gave the same gain (0.030 ms per loop) without a subplan.

2. **Larger effect, wider blast radius: make `groups.settings` jsonb in the PostgreSQL schema** (an override in `tools/pg-schema/config.json`).
   - The `CAST(... AS jsonb)` becomes a no-op. Tested with a session temp table: 0.050 to 0.003 ms per loop, 36.5 s to 8.7 s.
   - Every reader and writer of `groups.settings` would need checking: jsonb re-serialises on output and rejects `''` or invalid JSON. `JsonTextPostgresGrammar` already tolerates a jsonb column.

Given this is one background statement a day and the steady-state cost is a few seconds, option 1 or simply measuring after cutover is proportionate. I would not restructure the query with a CTE: inside the correlated NOT EXISTS a CTE scan has no index and would cost about as much per loop.

Separately, the repeated OR arm could be removed in a PostgreSQL-only branch by restructuring the predicate. I did not test that.

**How the synthetic data could mislead**

The synthetic data makes this look several times worse than production steady state.

- **Backlog that production would not have.** Outcomes are spread evenly over age, so 227,209 of the 261,123 no-outcome Approved postings in the 730-day window are older than 90 days. In production this job withdraws those daily, giving them an outcome, so `NOT EXISTS(mo2)` fails before the JSON subplan runs.
  - Only about 34k postings here (0-90 days, no outcome) would reach the JSON filter in steady state. That is roughly 110k at production size, or about 5 s of JSON work on PostgreSQL after doubling for the repeated OR arm.
  - The 36 s figure is the "first run against a large backlog" case, which drains at 20,000 a day.
- **Settings size and content.** Every group has the same 519-byte settings with `reposts.*` present and `maxagetoshow` absent. Real settings are probably larger, so each parse costs more, and groups that set `maxagetoshow` take 6 parses instead of 5.
- **Timings.** The MySQL BENCHMARK figure and the microbenchmarks are wall-clock on a shared host. Repeat runs of the same microbenchmark varied by about 30% (8.4 s against 11.2 s). Trust the per-loop ratios and loop counts more than the milliseconds.

### 19. Hot single-row UPDATEs rewrite every index on the table: users.lastaccess (16 indexes), chat_rooms.latestmessage (11), chat_messages flags (14), messages_likes view bumps

- **Severity:** worth-measuring (claimed slow-at-scale). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/user/authMiddleware.go:113-114; schema cause in iznik-batch/database/postgres/baseline.sql (every MySQL KEY carried over one-for-one, duplicates included, no fillfactor on any table)
- **How often:** users.lastaccess: once per signed-in member per 10 minutes, from the auth middleware on every authenticated API call. chat_rooms.latestmessage: every chat message sent. chat_messages flags: several updates per chat message (processing, mailing, seen). messages_likes: every post view and every mark-seen batch on Browse.

**Evidence**

The write amplification is real and I reproduced it, but no statement is slow because of it; the cost is WAL, dirtied pages and autovacuum work at a low write rate.

CODE
- iznik-server-go/user/authMiddleware.go:110-114 is live and runs on PostgreSQL. It fires at most once per signed-in member per 10 minutes: an app-side staleness check plus the SQL guard.
- The translator test (database/pgtranslate_test.go:60) gives the PostgreSQL predicate as `lastaccess < (NOW() - INTERVAL '10 minute')`.
- The chat_rooms.latestmessage sites exist as cited: chat/chatroom.go:601 and :1577, message/bulkItem.go:319 and :464, message/helper.go:663, alert/reply.go:220, amp/amp.go:712, chat/chatmessage.go:2157.
- The chat_messages flag updates are in iznik-batch: app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php:1084, 1197, 1254, 1727 and app/Services/ChatProcessService.php:295.
- The messages_likes upserts are at message/markseen.go:183 and message/message.go:6971.
- MySQL has the same duplicate on users (`PRIMARY KEY (id)` and `UNIQUE KEY id (id)`) and `KEY added (added, lastaccess)`. InnoDB would touch only that one secondary index for this update.

MEASURED ON iznik_perf (all inside BEGIN ... ROLLBACK)
- users, single row, real statement: `Index Scan using users_id`, `WAL: records=19 fpi=18 bytes=112296`, `dirtied=18`. The plan is fine (1.3 ms); the cost is the write.
- users, 1,000 cold rows: `WAL: records=18517 fpi=5489 bytes=39591487`, `dirtied=6039`.
- users, 1,000 neighbouring rows straight after: `records=18292 fpi=1710 bytes=11819549`. So there are always about 18 WAL records a row; the bytes are mostly full-page images (once per page per checkpoint), and only about 1.5 KB a row is not.
- chat_rooms.latestmessage, 1,000 rows: `records=14184 fpi=4096 bytes=31401665`.
- chat_messages newest 996 rows, processingrequired flipped: `records=16279 fpi=1122 bytes=9668217`. Inserting 996 chat_messages gives `records=15137`, so each lifecycle update costs about as many WAL records as the insert did.
- messages_likes, 1,119 scattered bumps: `records=6715 fpi=4456 bytes=34531582`, about 6 records a row.
- 18 exact duplicate index pairs exist, matching the claim's count (users_pkey/users_id, chat_messages_refchatid/_2, sessions_pkey/sessions_id, groups_pkey/groups_id and others).

WHERE THE CLAIM IS WRONG OR OVERSTATED
- Fillfactor alone does nothing for users.lastaccess. With fillfactor 85 and users_added kept, a second update of the same 1,000 rows gave `records=18008`, n_tup_hot_upd 0 of 2,000. lastaccess is an index column, so the update can never be HOT.
- "No room on the page" is partly a fresh-load artefact. The scratch tables are packed and only ever saw rolled-back updates. In a running system the first non-HOT update of a row leaves a hole that pruning reuses, so the 2-in-3,004 HOT ratio overstates steady state for unindexed-column updates.
- The partial-index suggestion does not make a flag change HOT. Predicate columns count as indexed, so 1 to 0 stays non-HOT, and 0 to 0 is already HOT-eligible. Partial indexes would only shrink the three 85 MB flag indexes. This is from PostgreSQL's rules; I did not run it.
- Rate (my estimate, not measured): production users_active has 2.2M hourly rows over 30 days, about 3,000 member-hours per hour. At 6 updates per member-hour that is at most about 5 lastaccess updates a second. Chat is under 1 message a second on average (42.5M over ten years). At those rates 18 index inserts per update is far inside what PostgreSQL absorbs.
- What is left is WAL volume: tens of KB per update when pages are cold, which matters for replicas and backups. There is also about 0.4M dead users tuples a day for autovacuum, against a 3.26M-row table.

No read plan changes, and MySQL also pays undo, redo, doublewrite and binlog for the same update.

**Fix**

All of this is PostgreSQL-schema only (tools/pg-schema/convert.mjs and config.json, which today has only the keys `citext` and `caseSensitive`); no MySQL statement changes.

1. **users_added without lastaccess.** Emit it as `(added)` on PostgreSQL. I found no query that needs the composite, and NotificationExhortService.php:49 already notes the composite cannot serve its lastaccess predicate. This is the change that makes the lastaccess update HOT-eligible.
2. **Fillfactor about 85** on users, and on chat_rooms, chat_messages and messages_likes for their unindexed-column updates.
3. **Drop exact-duplicate indexes** in the converter (18 pairs).

Trial of 1 to 3 on users, in one rolled-back transaction (DROP users_id, users_added recreated as `(added)`, fillfactor 85): rows first moved onto 85% pages, then 125 of them re-updated. Result: `WAL: records=369 bytes=31366`, `dirtied=1`, and 110 of 125 updates HOT. That is about 3 WAL records and 250 bytes a row, against 18 records and 1.5 KB or more before.

Not tried:
- chat_rooms.latestmessage is in four indexes that the chat-list queries need, so it stays non-HOT; accept it.
- chat_messages: dropping the duplicate refchatid_2 and the prefix index chat_messages_chatid removes 2 of 14 entries per update.
- Skip the partial-index idea as a HOT fix.
- Measure WAL per hour and n_tup_hot_upd in a soak with real traffic before tuning autovacuum.

**How the synthetic data could mislead**

- Every measurement here is on freshly loaded, 100%-packed tables whose only updates were rolled back. That is the worst case for HOT and for full-page images.
- Batches of 1,000 strided ids hit 1,000 distinct heap pages. Real active members recur every 10 minutes, so their heap and index pages stay hot and take one full-page image per checkpoint. Real WAL per update should sit nearer 1.5 KB than 40-112 KB.
- The second pass of the fix trial put all moved rows on the same new pages, which limited HOT to the 15% free space. That is why I re-ran it on a sparse subset.
- Scratch users rows are about 400 bytes. Production rows with real settings JSON are wider, so there are fewer per page and less HOT headroom at a given fillfactor.
- shared_buffers is 128 MB here, so the read counts are inflated.
- The write rates are my estimates from row counts, not production measurements. The split of messages_likes upserts between insert and update is unknown.
- The sessions table, which the same middleware updates, is empty here.

### 20. ANALYZE under-counts distinct values on the big time-clustered tables (messages_likes.msgid 24x), inflating per-key row estimates and tipping the Browse query into JIT

- **Severity:** worth-measuring (claimed worth-measuring). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/isochrone/message.go:211 (the query whose cost crosses the JIT threshold); cause is the absence of any statistics settings in iznik-batch/database/postgres/baseline.sql
- **How often:** Every Browse/isochrone list fetch (per signed-in page view of the main browse page); the nightly messages:count-views batch; every digest build that sums views per post.

**Evidence**

**Statistics: reproduced.** `pg_stats` after the full VACUUM ANALYZE gives `messages_likes.msgid` n_distinct 127,959 (the claim's figure; real is about 3.05M), `messages_attachments.msgid` 450,588, `chat_messages.chatid` 516,261, `chat_messages.refmsgid` 406,167, `email_tracking_images.email_tracking_id` 257,529. Server has `jit = on`, `jit_above_cost = 100000`, `default_statistics_target = 100`. Nothing in `baseline.sql`, `docker-compose.yml` or `iznik-server-go/database/postgres.go` sets jit or statistics.

**Reduced 200-post query: reproduced.** Cost 125,645, subplan `Index Scan using messages_likes_msgid ... rows=350 ... actual rows=14.89 loops=200`. JIT block: 8 functions, 2.7-3.8 ms. Execution 3.6 ms warm with JIT against 0.82 ms with `SET jit = off`, same plan.

**Cited statement (`isochrone/message.go:205-216`, `fetchReachCandidates`): JIT does not fire.** I hand-wrote the full statement in PostgreSQL dialect (spatial-id-list path, `ms.msgid IN (300 literal ids)`); I did not capture the translator's output.
- `Nested Loop Left Join (cost=1.99..4102.73 rows=1 ...) (actual ... rows=296)`, no JIT block, 7.4 ms.
- The `INNER JOIN rippling_reach` makes the planner estimate one outer row (300 of 3.05M messages against a 13.7k-row table), so the 628-cost subplan is charged once, not 300 times.
- With n_distinct corrected the cost is 3,507 and the plan is identical.
- That one-row estimate comes from the table-size ratio, which is the same in production (9.9M against 45k).

**Sibling statement (`message.go:752-768`, `myGroupsMessages`): JIT fires, worse than claimed.** Same select list, but `LEFT JOIN rippling_reach` and `ms.msgid IN ?`, so the outer estimate stays at N. Reached from `GET /isochrone/message` (`router/routes.go:700`) when the member's browse view is 'mygroups'. With 300 ids:
- `Nested Loop Left Join (cost=65.58..198322.52 rows=300 ...)`
- `JIT: Functions: 54 ... Timing: ... Emission 19.281 ms, Total 23.298 ms`; Execution 30.3 ms warm (81 ms on the first run, JIT 32.5 ms).
- `SET jit = off`: same plan, 6.85 ms.
- n_distinct fixed in a rolled-back transaction: cost 19,848, subplan `rows=15`, no JIT, 8.1 ms.
- The crossover is about 155 ids. The top group's share of the 12,434 open posts is in the hundreds, so big-group members cross it.

**CountViews (`CountViewsCommand.php:82`, daily 01:10, batches of 1000 ids): no effect.** `Index Only Scan using messages_likes_msgid ... rows=289662 ... actual rows=11662`, cost 15,308, no JIT, 1.5 ms. Corrected stats give the same plan.

**No join method changed in any probe.** The views and replies counts are correlated scalar subqueries, which PostgreSQL always runs as per-row SubPlans; a wrong rows-per-key figure cannot turn them into a hash join over `messages_likes`. The `LEFT JOIN messages_likes ml` is on the unique `(msgid, userid, type)` index and is estimated at one row. The claim's "risk of hash joins" is therefore not shown for these statements. I did not test the digest statements or the degraded ST_Contains path.

**MySQL today:** the same correlated lookups on `(msgid, type)`, with no JIT, so the JIT overhead is a PostgreSQL-only addition.

**One slip to report:** a failed `cd` made one command write a 260-byte `all.sql` into `/home/edward/FreegleDockerWSL`, which the brief said not to touch. I deleted it at once and `git status` there is unchanged. That same command ran the ALTER/ANALYZE inside BEGIN...ROLLBACK; I checked afterwards that `attoptions` is empty and `pg_stats` is back to the original values.

**Fix**

**Smallest fix: turn JIT off for the application's PostgreSQL connections.** Either `ALTER ROLE <api role> SET jit = off` / `ALTER DATABASE iznik SET jit = off` in a post-baseline PostgreSQL file, or `-c jit=off` on the postgres service in `docker-compose.yml`, or `jit=off` as a runtime parameter in the pgx DSN built in `iznik-server-go/database/postgres.go`. It is PostgreSQL-only, so MySQL statements are untouched. It removes the overhead whatever ANALYZE estimates.

**Optional second step: n_distinct overrides.** `ALTER TABLE messages_likes ALTER COLUMN msgid SET (n_distinct = <negative ratio>)` and similar for the other columns, in the same post-baseline file. This corrects the estimates (cost 198,322 down to 19,848) and is also PostgreSQL-only. I would not ship it as the JIT fix:
- it only moves the crossover to about 1,500 ids;
- the right ratios must come from production data, not from this scratch database (-0.066 is its ratio);
- no plan changed because of it in any probe.

Tested on the scratch database: `SET jit = off` at session level (30.3 ms to 6.85 ms, same plan), and the n_distinct override inside BEGIN...ROLLBACK (JIT block gone, 8.1 ms). I did not test the role-level or DSN form of the setting.

**How the synthetic data could mislead**

- **The 24x figure is specific to this data.** Likes here were loaded in strict msgid order, so each sampled page holds only a few msgids. Production likes arrive in time order across many posts and the table is 3.25x larger with the same 30,000-row sample, so the under-count will differ; it could be milder or worse. Only ANALYZE on a restored production copy says which.
- **The JIT crossover depends on that figure.** At 350 estimated rows per post it is about 155 ids; with a smaller error it moves up.
- **Views per post are synthetic** (average 15 here), so the per-post subplan cost is too.
- **The number of ids a 'mygroups' member sends** depends on the real spread of open posts across groups, which is modelled only roughly.
- **JIT milliseconds are from a shared host.** The JIT block appearing or not, and the 54 functions, are the reliable part.

### 21. Joining a citext column to a varchar column casts the citext side to text: the index on items.name is unusable and the match becomes case-sensitive

- **Severity:** worth-measuring (claimed worth-measuring). **Verdict:** confirmed. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/authority/stats.go:361 and :392; type decision in tools/pg-schema/config.json (items.name is citext, messages_bulk_items.name is left varchar(255))
- **How often:** The authority statistics report (ModTools/council stats, per request by an admin or partner; low frequency, twice per report).

**Evidence**

The mechanism is confirmed, but the performance cost is small. The part that matters is the wrong answer, and I found a second, worse site the claim missed.

**Code and reach**
- iznik-server-go/authority/stats.go:361 and :392 are real: `LEFT JOIN items i ON i.name = bi.name`.
- They run inside `GetStatsByAuthority`, called from authority.go:162 only when `GET /authority/{id}` asks for stats. That is on demand, twice per report, as claimed.
- The translator has no citext rule (the only mention in pgtranslate*.go and pgconn.go is a type-name switch at pgconn.go:382), so PostgreSQL receives the join text unchanged.
- Types on iznik_perf: `items.name` is citext with `items_name UNIQUE btree (name)`; `messages_bulk_items.name` is `character varying(255)`.
- pg_cast: citext to varchar and citext to text are implicit (`i`); varchar to citext and text to citext are assignment only (`a`). So the operator resolves to text = text with the cast on the indexed side.

**Test setup**
- `messages_bulk_items` is empty on iznik_perf, so inside BEGIN ... ROLLBACK I inserted 5,000 rows (10 on each of the newest 500 posts), names taken from `items`, every second one lower-cased, then ran ANALYZE.
- After the rollback the table is back to 0 rows and varchar(255).

**Go query as written** (England `pc` temp table, 802,276 locations)
- Plan lines:
  - `Hash Right Join ... Hash Cond: ((i.name)::text = (bi.name)::text)`
  - `-> Seq Scan on items i (rows=1100000) Buffers: shared read=11341`
- With `enable_seqscan` off the plan stayed a hash join over `Seq Scan on items (Disabled: true)`: `items_name` cannot serve the cast expression.
- Match count: `LEFT JOIN items i ON i.name = bi.name` gave total 5000, matched 2500. Every lower-cased name lost its weight and fell back to the average. With `bi.name::citext` it matched 5000 of 5000.

**Size of the performance cost**
- It is one full read of `items` per statement: 11,341 pages for 1.1M rows here, so roughly 37k pages (about 300 MB) at 3.7M rows.
- In the same statement the `pc` scan and the messages and locations probes cost about as much (whole query 33k buffers as written, 42k with the index plan, because 5,000 index probes replace the scan).
- MySQL does one `ref` lookup on `UNIQUE KEY name` per bulk item (both columns are utf8mb4_unicode_ci in the dump). The seq scan only loses clearly when few bulk items are in range, and it is bounded: not an outage risk.

**Second site the claim missed: the batch path**
- iznik-batch/app/Services/StatsGenerationService.php:562, :594, :813 and :844 join with `new Collate('bi.name', 'utf8mb4_unicode_ci')`.
- iznik-batch/app/Database/Expressions/Collate.php renders that on PostgreSQL as `LOWER("bi"."name")`.
- `lower()` returns text, so the join is again text = text: `Hash Cond: (lower((bi.name)::text) = (i.name)::text)` over `Seq Scan on items`, with the hash of 1.1M items spilling (`Batches: 16, temp written=6340`).
- It lower-cases only one side, so it matched 0 of 5000 against the capitalised synthetic item names.
- This is the weight arm of `stats:generate-daily` (console.php:1792, daily at 02:30), plus `regenerateWeightForRange`.

**Fix**

Both parts leave every MySQL statement exactly as it is.

1. **Schema (fixes the Go sites with no code change).** Add `messages_bulk_items.name` to the `citext` list in tools/pg-schema/config.json and regenerate baseline.sql.
   - Tried in a rolled-back transaction with `ALTER TABLE messages_bulk_items ALTER COLUMN name TYPE citext`.
   - The plan became `Nested Loop Left Join -> Index Scan using items_name on items i, Index Cond: (name = bi.name)`, matched 5000 of 5000, and the minimal join fell from 11,415 buffers to 2,954.
   - The alternative of casting at the site (`i.name = CAST(bi.name AS citext)`) gave the same plan and the same matches, but needs a PostgreSQL-only branch in stats.go.

2. **Batch (needed as well; the schema change alone does not fix it).** After the column became citext, `i.name = LOWER(bi.name)` still matched 0 of 5000, because `lower()` returns text. Change the PostgreSQL branch of Collate.php for `_ci` collations to render `CAST(<operand> AS citext)` instead of `LOWER(<operand>)`.
   - Tried as `i.name = CAST(LOWER(bi.name) AS citext)` and `i.name = CAST(bi.name AS citext)`: both matched 5000 of 5000, and the latter used the index.
   - The MySQL branch of Collate is untouched.
   - Check Collate's other callers first. This is right when the other side is citext; a text column on the other side would need `LOWER` on both sides instead.

The claim's suggested ratchet (report varchar columns joined to citext ones) is reasonable but not needed for this fix.

**How the synthetic data could mislead**

- **Bulk item volume is invented.** `messages_bulk_items` is empty in the scratch database; the 5,000 rows, and the half-lower-cased names, are mine. The fraction of weight lost depends entirely on how production bulk item names are cased against `items.name`. Here it was 50% for the Go path and 100% for the batch path; the real figure needs a count on production MySQL.
- **Item names are all capitalised here.** Every synthetic `items.name` starts with a capital ("Large Armchair"), which makes the `LOWER()` batch path look total. Any all-lower-case production item names would still match.
- **`items` may be narrower here.** The synthetic names are short, so the 37k-page estimate for 3.7M rows could be low.
- **Buffer splits reflect 128 MB shared_buffers.** The hit/read split is not production's; total buffers are the comparable figure.
- **The seq scan can beat the index plan.** With many bulk items in the date range the 5,000-probe index plan used more buffers than the scan (42k against 33k). The performance half of the claim is weak; the correctness half does not depend on scale.

### 22. A citext column compared with a varchar column falls back to case-sensitive text equality: the citext index is unusable and rows that match on MySQL do not match

- **Severity:** worth-measuring (claimed slow-at-scale). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-batch/app/Services/StatsGenerationService.php:562 (also 594, 813, 844)
- **How often:** StatsGenerationService::generate runs per group per day in the nightly stats job, and regenerateWeightForRange over a date range. authority/stats.go runs per authority report request. job.go:168 runs on each jobs listing API call (job adverts shown on member pages). GiftAidClaimService::correctUserIdInDonations runs on each Gift Aid claim or update command.

**Evidence**

The mechanism is real at every cited site, but the performance cost is smaller than claimed; the wrong answers are the more serious part.

CODE AND SCHEMA
- iznik-batch/app/Database/Expressions/Collate.php renders a _ci collation on PostgreSQL as LOWER(operand). Its only four uses are StatsGenerationService.php lines 562, 594, 813 and 844, all `i.name = Collate('bi.name', ...)`.
- The Go sites send their SQL unchanged: authority/stats.go 361 and 392 (`LEFT JOIN items i ON i.name = bi.name`), job/job.go 168 and 237 (`ai_images.name = jobs.canonical_title`). pgtranslate*.go has no citext rule.
- GiftAidClaimService.php:166 joins `users_emails.email = users_donations.Payer`.
- Scratch column types: items.name, ai_images.name and users_emails.email are citext; messages_bulk_items.name, jobs.canonical_title and users_donations.Payer are varchar.
- On MySQL all six columns are utf8mb4_unicode_ci, with UNIQUE KEY name on items and ai_images and UNIQUE KEY email on users_emails. So MySQL does an indexed, case-insensitive lookup per row.

FREQUENCY IS LOWER THAN CLAIMED
- Lines 562 and 594 are in buildDailyContext(), which runs once per date for all groups (GROUP BY mg.groupid), not per group. The trigger is the daily stats:generate-daily schedule in routes/console.php.
- Lines 813 and 844 are in regenerateWeightForRange, a manual command, once per date.
- So the nightly job pays two scans of items, not about 500.

SEMANTICS (confirmed)
- `select 'Sofa'::citext = lower('Sofa'::varchar), 'Sofa'::citext = 'sofa'::varchar, 'Sofa'::citext = 'sofa'::varchar::citext` returned f, f, t.
- With 200 bulk items copied from items.name (in a rolled-back transaction): the LOWER form matched 0 of 200, the plain Go form matched 200 of 200 (identical case only), and the ::citext form matched 200 of 200.
- Jobs trial (rolled back): ai_images.name 'Job title 1' against canonical_title 'job title 1' matched 0; with the column as citext it matched 1.

PLANS
- Stats query as Collate renders it:
  - `Hash Left Join  Hash Cond: (lower((bi.name)::text) = (i.name)::text)` over `Seq Scan on items i ... rows=1100000`
  - `Buffers: shared hit=809 read=11345, temp read=5578 written=5578`; the hash spilled to 16 batches.
- Go form:
  - `Hash Right Join  Hash Cond: ((i.name)::text = (bi.name)::text)` over a Seq Scan of items, 11,344 buffers.
- With `i.name = bi.name::citext`:
  - `Nested Loop Left Join -> Index Scan using items_name on items i  Index Cond: (name = (bi.name)::citext)  loops=200`
  - 806 buffers for that join.
- Jobs single-row query (job.go:237) with 50,000 trial ai_images rows:
  - `Hash Right Join  Hash Cond: ((ai_images.name)::text = (jobs.canonical_title)::text)` over `Seq Scan on ai_images rows=50000`, on every call.
  - With the cast: `Index Scan using ai_images_name  Index Cond: (name = (jobs.canonical_title)::citext)`.
  - The 50-id listing shape has the same seq scan.
- Gift Aid:
  - `Hash Join  Hash Cond: ((users_emails.email)::text = (users_donations."Payer")::text)` over `Parallel Seq Scan on users_emails`, 39,593 buffers.
  - With the cast the planner chose a merge join over the users_emails_email index at 172,679 buffers, which is worse. For Gift Aid the cast fixes correctness only; it is an occasional command, so the scan does not matter.

SIZE OF THE PERFORMANCE COST
- Items scan: about 11k buffers here, about 38k at 3.7M rows, twice a night. Negligible.
- Authority report: two such scans per request, on an already heavy request.
- Jobs: one full scan of ai_images per API call. This is the only hot path, and its cost depends on a table size I do not have.

**Fix**

Two PostgreSQL-only changes; MySQL statements stay byte-identical.

1. Schema: add messages_bulk_items.name, jobs.canonical_title and users_donations.Payer to the "citext" list in tools/pg-schema/config.json and regenerate baseline.sql. The unchanged Go statements and the Gift Aid join then compare citext with citext.
   - Tried in a rolled-back ALTER COLUMN ... TYPE citext: the unchanged `i.name = bi.name` became `Nested Loop Left Join -> Index Scan using items_name  Index Cond: (name = bi.name)`, 803 buffers against 11,344.
   - The unchanged jobs join became `Index Scan using ai_images_name  Index Cond: (name = jobs.canonical_title)` and matched across case.
   - Not tried for users_donations.Payer; I only tested the cast there.

2. Collate.php, PostgreSQL branch only: render a _ci collation as `operand::citext` instead of `LOWER(operand)`. The schema change alone does not fix the four StatsGenerationService sites, because LOWER(citext) returns text. I tested that: still `Hash Cond: (lower((bi.name)::text) = (i.name)::text)` with a Seq Scan of items.
   - Tried as hand-written SQL `i.name = bi.name::citext`: index nested loop, 200 of 200 matched.

Not needed: a Go translator rule or an engine branch, once the columns are citext.

**How the synthetic data could mislead**

- messages_bulk_items, messages_bulk_items_interest, jobs and ai_images are empty in the scratch database. I inserted 200 bulk items, 20,000 jobs and 50,000 ai_images in rolled-back transactions; the real ai_images row count is not in the production list, so the per-call cost of the jobs query is unmeasured.
- Every synthetic items.name contains a capital, so the LOWER form matches nothing here. If production item names are stored lowercase, the Collate/LOWER form matches most rows, and only mixed-case names are lost.
- The plain Go form matched 100% only because I copied names verbatim. Real bulk-item names are typed by members or the splitter and will differ in case from items.name.
- Synthetic Payer addresses (donor…@perfmail.invalid) match no users_emails row, so the Gift Aid join returned 0 rows and says nothing about how many donors differ only by case.
- With many more unlinked donations or bulk items per day, a hash join over the whole table can be the right plan anyway.

### 23. Mark-seen's ON DUPLICATE KEY UPDATE becomes ON CONFLICT DO UPDATE on the 150M-row messages_likes: 100 bumps wrote 2.4 MB of WAL and dirtied 311 pages

- **Severity:** worth-measuring (claimed slow-at-scale). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/message/markseen.go:183-185 (rewritten at iznik-server-go/database/pgtranslate_more.go:709-744)
- **How often:** POST /messages/markseen (message.MarkSeen): fired as a logged-in member scrolls the browse list, in chunks of up to 100 ids. The bump path is taken for every post already seen. messages_likes is the largest table (150M rows, 97% View).

**Evidence**

The per-row mechanism is as claimed; the frequency is not.

CODE
- The statement is real and reached on PostgreSQL: iznik-server-go/message/markseen.go:183-185 (buildInsertViewBatchQuery), route POST /messages/markseen at router/routes.go:985.
- The translator's ON DUPLICATE KEY branch (database/pgtranslate_more.go:709-744) resolves the target from the catalogue. The scratch plan shows "Conflict Arbiter Indexes: messages_likes_msgid_2", which matches the claim's pgSql. I did not run the translator itself.
- "The bump path is taken for every post already seen" is not what the client does. Callers in iznik-nuxt3:
  - components/MessageList.vue:757-776 (duplicates watcher) sends only ids where `message?.unseen`.
  - stores/message.js:698-714 (markSeenSiblings) filters to unseen ("a re-view is a no-op, not a repeated API call").
  - The "Mark seen" button (MessageList.vue:665) calls clearCount, which moves a watermark and writes no likes rows.
  - components/WantedMatches.vue:95 is the only caller that sends ids unfiltered, and it is a small list.
- So a 100-row all-conflict batch needs stale client state (a second device, a race). The normal mark-seen is a fresh insert.
- The per-post open (handleView, message/message.go:6963-6980) also bumps, but one row at a time and at most once per 30 minutes per member and post.
- The batch writer (iznik-batch MarkDigestSeenCommand.php:96) uses insertOrIgnore and never bumps.

MYSQL TODAY
- messages_likes has no primary key, so InnoDB clusters on UNIQUE msgid_2 (msgid, userid, type). timestamp and count are in no index, so a bump is an in-place change to one clustered leaf.
- A member's 100 rows sit on 100 different leaves there too, so MySQL also dirties about 100 pages. The fair comparison is about 100 pages against about 310, not 1 against 310.

SCRATCH (iznik_perf, BEGIN ... ROLLBACK, EXPLAIN (ANALYZE, BUFFERS, WAL), userid 52311 with 156 View rows)
- 100 bumps, cold:
  "Conflicting Tuples: 100"
  "Buffers: shared hit=2553 read=387 dirtied=310 written=131"
  "WAL: records=613 fpi=301 bytes=2359399"
  "Trigger messages_likes_on_update: time=0.920 calls=100"
  This reproduces the claim.
- Same 100 bumps again in the same transaction (pages already imaged since the checkpoint):
  "Buffers: shared hit=2207 dirtied=1" and "WAL: records=520 bytes=37262"
  So 98% of the 2.4 MB is full-page images, paid once per page per checkpoint interval (checkpoint_timeout is 5min, wal_compression off). The logical cost is about 370 bytes a row.
- Row trigger: 0.3 to 0.9 ms per 100 rows, not a factor. With triggers disabled the WAL was 42,264 bytes against 37,262 with them, so no saving.
- 100 fresh inserts (the normal path):
  "Tuples Inserted: 100", "Buffers: shared hit=1719 read=12 dirtied=13", "WAL: records=502 fpi=12 bytes=113672"
  This is cheap, because new rows go to the heap tail and only the userid and msgid leaves are touched.

AUTOVACUUM
- The claim of 30M dead rows before a vacuum assumes a bump rate nothing here supports.
- The table is insert-mostly, and since PostgreSQL 13 inserts trigger autovacuum as well.
- Default scale factors on a 150M-row table are still coarse; that is a general big-table setting, not specific to this statement.

**Fix**

Smallest fix: a PostgreSQL-only storage setting, `ALTER TABLE messages_likes SET (fillfactor = 85)`, emitted from the schema generator. MySQL statements stay exactly as they are.

- It needs adding: neither tools/pg-schema nor baseline.sql has any fillfactor or autovacuum setting today (grep finds none).
- It must be set before the table is loaded, or the table rewritten afterwards, to take effect on existing pages.
- Cost: about 18% more heap in the trial (10,000 pages became 11,765), which would be roughly 1.5 to 2 GB on the production table.
- Per-table autovacuum settings for this table (for example autovacuum_vacuum_scale_factor and autovacuum_vacuum_insert_scale_factor around 0.02) are reasonable alongside it, but I did not test them.

Tried on scratch, rolled back: two 1.2M-row copies of a contiguous slice of messages_likes, with the same three indexes and the same row trigger, and 100 bumps on rows spread over 99 heap pages.

| | fillfactor 100 | fillfactor 85 |
|---|---|---|
| HOT updates (n_tup_hot_upd) | 0 of 100 | 100 of 100 |
| Buffers dirtied | 400 | 99 |
| WAL records | 605 | 200 |

At 85 that is one page per bump, the same as InnoDB.

Not recommended:
- Dropping the ON UPDATE trigger: it measured as negligible.
- Changing the statement to DO NOTHING or adding a time WHERE to the DO UPDATE: it changes what count and timestamp mean, and so would diverge from MySQL.

Given how rarely the client sends already-seen ids, it is reasonable to measure the real bump rate first and apply fillfactor only if it is material.

**How the synthetic data could mislead**

- messages_likes was bulk-loaded and VACUUMed, so every heap page is exactly full and no bump can be HOT. A production table that has been taking bumps and vacuums has some slack on its pages, so some bumps would be HOT even at fillfactor 100. The scratch result is the worst case.
- Viewers are picked by an arithmetic stride, so a member's rows are spread one per heap page and one per index leaf. Real browsing clusters a member's views in time, so a batch would share heap and userid-index pages and dirty fewer.
- The full-page-image share depends on checkpoint spacing. With production settings (a longer checkpoint_timeout, a larger max_wal_size, wal_compression on) the bytes per bump drop a lot. The fpi counts in my fillfactor trial (397 against 45) are confounded by checkpoint timing on freshly built tables; trust the dirtied-page, WAL-record and HOT counts instead.
- The table here is 46M rows against 150M, so production indexes are one level deeper at most and a cold leaf is less likely to be cached with a realistic shared_buffers.
- The real bump rate is unknown. On production MySQL, compare `SELECT SUM(count > 1), COUNT(*) FROM messages_likes WHERE type = 'View'` over a recent msgid range to learn what share of views are ever bumped.

### 24. FORCE INDEX is stripped and MAX_EXECUTION_TIME becomes an inert comment: the ModTools member name search goes back to walking the whole memberships primary key, with no time cap

- **Severity:** worth-measuring (claimed slow-at-scale). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/membership/membership.go:700-703 and :770-772 (hint removed at iznik-server-go/database/pgtranslate_more.go:54-81)
- **How often:** GET /memberships with a search term: each ModTools member search by a moderator. Low volume, but each run scans the whole table, and the comments record moderators retrying and stacking copies. The MAX_EXECUTION_TIME sites are GET /modtools/messages searches.

**Evidence**

**Verdict in short:** the mechanism is real and reproduces, but the cost is a fixed, parallel pass over `memberships` rather than a 13 s walk. The lost time cap is real and matters more.

**Code**
- `iznik-server-go/membership/membership.go:700-705` builds `memberships m FORCE INDEX (memberships_groupid_collection_emailfrequency)`, used only by the non-numeric name/email search at `:770-772`.
- `iznik-server-go/database/pgtranslate_more.go:54-81` deletes any `FORCE|USE|IGNORE INDEX (...)`. `pgtranslate_test.go:85` pins that: `FROM logs FORCE INDEX (timestamp_2) WHERE` becomes `FROM logs  WHERE`.
- The connection is pgx stdlib with default settings (`postgres.go`), so statements are server-side prepared and can go to a generic plan.
- I did not run the translator. I hand-built the PostgreSQL statement in the claimed shape (`ANY_VALUE` wrappers, `GROUP BY "m"."id" ORDER BY m.id DESC LIMIT $8`) with one of the three `mailDelayedCols` subqueries.
- I did not trace the route or how often it is called; I took the claim's "low volume, moderator search" as given.

**Plans on iznik_perf (PREPARE, then EXPLAIN (ANALYZE, BUFFERS)), term matching nobody**

| Case | Access path on memberships | Total buffers |
|---|---|---|
| Group 1 (45,116 Approved), custom plan | `Parallel Index Scan Backward using memberships_pkey`, `Rows Removed by Filter: 501628` x 3 | 411,019 |
| Group 1, group index forced by an `OFFSET 0` subquery | Bitmap scan on the group index | 398,563 |
| Group 480 (1,721 Approved), custom plan | `Parallel Bitmap Heap Scan` via `memberships_memberships_groupid_collection_emailfrequency` | 16,139 |
| Group 480, generic plan | `Parallel Index Scan Backward using memberships_pkey`, `Rows Removed by Filter: 516093` x 3 | 47,063 |

- **Group 1:** the planner estimated 465 matching rows and got 0, so the LIMIT never stopped the scan. But the pkey walk itself is only 32,492 of the 411,019 buffers. The rest is 45,116 lookups each on `users` and `users_emails`, which the hinted MySQL plan pays too. The "bad" plan is about 3% worse than the "good" one.
- **Group 480:** the generic plan is reached without forcing. After five plain EXECUTEs the sixth used it (`pg_prepared_statements`: `generic_plans 1, custom_plans 5`), because its estimated cost (8,950) is below the custom plan's (28,387).
- **Size of the damage:** about 31k extra buffers per search, whatever the community size. That is the whole `memberships` heap plus pkey (24,347 + 4,252 pages, about 220 MB here), read in id order by 3 processes. It took 0.2-0.33 s here, mostly as buffer reads (`read=28200` of the scan's 32.6k buffers), not from cache. At production's 5.1M rows that is roughly 100k buffers (about 750 MB) per search.
- For a common term the pkey walk is the better plan, because it stops early.

**MAX_EXECUTION_TIME**
- Confirmed by reading: no file under `iznik-server-go/database` handles `MAX_EXECUTION_TIME`, and `statement_timeout` appears nowhere in the branch outside `plans/`.
- `message_list.go:96` and `:310` therefore send an inert comment, and `message_list.go` passes no context. `database.IsQueryTimeout` already maps 57014, but nothing can raise it. The 20 s cap that turns into a 400 at `message_list.go:376-386` cannot fire on PostgreSQL.
- I did not run this part; it is from the code only.

**Not checked:** the other hint sites the claim lists (logs, messages_groups, newsfeed, emailtracking), and the `groupid == 0` form of this search.

**Fix**

**Plan fix (PostgreSQL-only branch in `membership.go`; MySQL keeps its statement and hint byte for byte)**

Two forms tried on the scratch database:

| Form | Group 480, generic plan | Group 1 |
|---|---|---|
| Today | 47,063 buffers, pkey walk | 411,019 buffers, pkey walk (custom) |
| `ORDER BY m.id + 0 DESC` | 16,116 buffers, group index | 237,429 buffers plus an 11.7k-block temp spill (custom); bitmap on `memberships_idx_configid_role_groupid`, then a hash join to a seq scan of `users_emails` |
| `FROM (SELECT * FROM memberships WHERE groupid = ? AND collection = ? OFFSET 0) m` | 19,586 buffers, group index | 398,563 (custom) and 489,648 (generic), group index |

- `ORDER BY m.id + 0` is the smaller change: one token when `database.Engine() == EnginePostgres`. It removes the early-stop bet, since the pkey order no longer satisfies the ORDER BY. I did not run it for group 1 under a generic plan.
- The `OFFSET 0` subquery pins the path harder but needs the group arguments passed twice.
- Neither is urgent: the gain is about 31k buffers per small-community search and nothing for large ones.

**Time cap (not tested)**

- Give the API's PostgreSQL connections a default `statement_timeout`, either in `buildPostgresDSN` (`q.Set("statement_timeout", "20000")`, which I expect pgx to pass through as a runtime parameter) or with `ALTER ROLE ... SET statement_timeout`.
- The resulting 57014 already reaches `IsQueryTimeout`, so the existing 400 path would work unchanged.
- A per-statement value taken from the hint would need the translator to wrap the query in a transaction with `SET LOCAL`.
- MySQL is unchanged either way.

**How the synthetic data could mislead**

- **Row width:** synthetic `memberships` rows are about 125 bytes (24,347 pages for 1.55M rows). Real rows carry `settings` JSON and may be wider, which makes the full walk proportionally dearer.
- **Buffer cache:** scratch `shared_buffers` is 128 MB, so the walk was mostly reads. On a production server with `memberships` in memory it is cheaper; on a cold replica it is dearer.
- **LIKE estimates:** names are low-cardinality filler ("Frank Khan"), so the `%term%` selectivity estimate (465 rows) that drives the early-stop bet may differ on real `pg_stats`. The generic plan uses default selectivity either way, so that path does not depend on the data.
- **Group skew:** the largest group here is 45k of 1.55M members. If production's largest communities are a bigger share of the table, the pkey walk is closer to optimal for them.
- **Parallelism:** the walk ran with 2 workers. Under pgbouncer or `max_parallel_workers` pressure it would be serial and about three times slower in wall time.
- **Case sensitivity (correctness, outside this claim):** `users.fullname`, `firstname` and `lastname` are `varchar`, not `citext`, so this LIKE is case-sensitive on PostgreSQL, where MySQL's is not.

### 25. users.lastaccess and other hot single-row updates are never HOT on PostgreSQL: each writes a new row version plus an entry in every index (16 on users)

- **Severity:** worth-measuring (claimed slow-at-scale). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/user/authMiddleware.go:113-114 (schema: users_added (added, lastaccess) in iznik-batch/database/postgres/baseline.sql)
- **How often:** Once per signed-in member per ten minutes of activity, on every authenticated API route (the middleware), plus the hourly batch pass. Mark-seen runs on every browse scroll; email_tracking on every mail open.

**Evidence**

The mechanism is real for users.lastaccess, but the cost is bounded and two of the supporting claims are overstated.

CODE
- iznik-server-go/user/authMiddleware.go:110-113 is live on every authenticated route. An app-side check skips the statement unless lastaccess is over 10 minutes old, and the SQL guard repeats the test.
- The translator test (pgtranslate_test.go:60) gives the PostgreSQL form: UPDATE "users" SET "lastaccess"=NOW() WHERE id = $1 AND (lastaccess IS NULL OR lastaccess < (NOW() - INTERVAL '10 minute')).
- users has 16 indexes on both engines, including users_added (added, lastaccess) and the duplicate users_id / users_pkey. There are no triggers on users and no fillfactor anywhere in the converter or baseline.
- The batch job (iznik-batch/routes/console.php:791-803) runs hourly and nightly, but its own comment says it finds "about 37 users", so it is negligible as a write source.

MEASURED ON iznik_perf (all in BEGIN/ROLLBACK)
- A, one row, exact statement: Index Scan using users_id, "WAL: records=20 fpi=18 bytes=120602", n_tup_hot_upd 0.
  - The "Buffers: shared hit=380 read=72" is mostly a cold relcache in a fresh session, not per-row cost. Over 1,000 rows it is about 45 buffers a row.
- A2, same row again: guard gives "Rows Removed by Filter: 1", "Buffers: shared hit=5", no WAL. Racing requests are cheap no-ops.
- B, 1,000 scattered rows as loaded: "WAL: records=19735 fpi=6069 bytes=43892118", "Buffers: shared hit=38482 read=6712 dirtied=6535", HOT 0 of 1,000. This matches the claim.
- C, users_added dropped, pages still packed: "WAL: records=17618 fpi=15", HOT 1. Dropping the index alone does not help on full pages.
- D, 200k-row copy (LIKE users INCLUDING ALL) at fillfactor 85 with (added, lastaccess) kept: "WAL: records=17052", HOT 0 of 1,000. This proves the index, not page fullness, is the permanent blocker.
- E, same copy with the index replaced by (added): "WAL: records=1000", "Buffers: shared hit=5426 read=574 dirtied=1000", HOT 1,000 of 1,000.

WHY IT IS SMALLER THAN CLAIMED
- Rate: at most one update per active member per 10 minutes. Production users_active (2.2M hourly rows over 30 days) implies roughly 73k member-hours a day, so a ceiling of a few hundred thousand updates a day, single digits per second.
- At about 20 WAL records and 1.6 KB a row without full-page images (C: 1.64 MB per 1,000), that is wasteful but not a throughput problem. Full-page images after each checkpoint are the real cost (B: about 44 KB a row on scattered rows).
- PostgreSQL 18 has bottom-up index deletion, which limits bloat in the 15 indexes whose keys do not change.
- messages_likes is not "never HOT". None of its three indexes covers timestamp or count.
  - F, first conflict-path upsert of 30 existing rows: "Conflicting Tuples: 30", "WAL: records=181 fpi=96", HOT 0, because bulk-loaded pages are 100% full.
  - G, same rows again: "WAL: records=60 bytes=3750", "Buffers: shared hit=300", HOT 30 of 30.
  - Once a row sits on a page with room, updates are HOT, so this self-heals in steady state and fillfactor is only an optimisation.
- chat_rooms.latestmessage (in 4 of 12 indexes) and users.lastlocation (indexed) share the permanent blocker with users.lastaccess. I confirmed the indexes exist but did not measure those writes.
- email_tracking.opened_at is also indexed, so the same blocker applies there by reading; I did not measure it.
- The autovacuum point is correct as a default (autovacuum_vacuum_scale_factor 0.2 on this server), but it is deployment tuning, not a query defect.

**Fix**

Smallest fix, PostgreSQL schema only, MySQL statements and schema unchanged:
- Emit users_added as (added) instead of (added, lastaccess) in the PostgreSQL baseline.
- Give users a fillfactor of about 85.

Tested together on a 200k-row copy: 1,000 updates went from 17,052 WAL records and 0 HOT to 1,000 WAL records and 1,000 HOT. Neither works alone: test C (index dropped, full pages) gave 1 HOT, and test D (fillfactor 85, index kept) gave 0.

Implementation notes:
- tools/pg-schema/config.json has no index-override or storage-parameter mechanism today (only citext and caseSensitive). It needs a small addition to convert.mjs near lines 710-727, where indexes are emitted.
- I found no reader that needs lastaccess as the second column. NotificationExhortService.php:49-50 already says the composite cannot help, and (added) still serves range scans on added.

Optional, same mechanism:
- Skip the duplicate users_id unique index on PostgreSQL (users_pkey is identical).
- Set fillfactor 85-90 on messages_likes and chat_rooms.
- Set per-table autovacuum_vacuum_scale_factor (about 0.01-0.02) on messages_likes and the other very large tables.

Not needed for this problem: moving lastaccess to a side table, or adding a primary key to messages_likes.

**How the synthetic data could mislead**

- Every users and messages_likes page was bulk-loaded and vacuumed, so pages are 100% full and all-visible. That is the worst case for HOT and for full-page images. A live table that has been updated and pruned has scattered free space, so tests B, C and F overstate steady-state cost for columns that are not indexed.
- Test D's result (0 HOT with the index present) does not depend on the data.
- My 1,000-row tests hit 1,000 different heap pages. Real active members cluster towards high ids, so page sharing and fewer full-page images are likely.
- The scratch server has shared_buffers 128MB and max_wal_size 1GB. Checkpoints, and so full-page images, are more frequent than on a tuned production server.
- Synthetic users rows are about 408 bytes. Real rows with larger settings JSON fit fewer to a page, so a fillfactor of 85 may leave room for only one or two extra versions.
- The update rate is my estimate from users_active counts, not a measured production figure.

### 26. A PostgreSQL deadlock (40P01) is classified as 'not a deadlock' by the Laravel retry code, and the pgsql connection has no statement retry at all

- **Severity:** worth-measuring (claimed worth-measuring). **Verdict:** confirmed. **Fix tried on the scratch database:** yes.
- **Where:** iznik-batch/app/Support/TransactionPolicy.php:184-190; iznik-batch/app/Providers/AppServiceProvider.php:43-51; Go side iznik-server-go/database/retry.go:42-63 and iznik-server-go/sentrylog/report.go:44-47
- **How often:** Only when a deadlock happens: the TN ingest (tn:sync), ripple engagement writes and any concurrent batch writers on the same rows. Rare, but each one becomes a failed job or lost write instead of a retry.

**Evidence**

This is a correctness and resilience finding, not a query-plan one, so there is no EXPLAIN. I provoked the real errors on iznik_perf and fed them to the real classifier. Everything ran inside transactions that were rolled back; afterwards there were 0 idle-in-transaction sessions and 0 leftover locks.

**Core claim: reproduced.** A psql session and a PHP PDO session (run in freegle-pg-port-batch, loading the branch's own `App\Support\TransactionPolicy` through the autoloader, no artisan or phpunit) updated memberships id 1 and id 2 in opposite orders. PHP was the victim:

```
PHP errorInfo: ["40P01",7,"ERROR:  deadlock detected\nDETAIL:  Process 105618 waits for ShareLock on transaction 481478; ..."]
PHP getCode: '40P01'
TransactionPolicy::isDeadlock = false
Laravel causedByConcurrencyError = true
after: ["25P02",7]
```

- **Mechanism:** `iznik-batch/app/Support/TransactionPolicy.php` lines 184-190 return as soon as `errorInfo[1]` is numeric. PDO pgsql always puts 7 there, so the test becomes `'40P01' === '40001'` or 7 in [1213, 1205], which is false, and the text fallback that would match "deadlock" is never reached.
- **Laravel disagrees with it:** Laravel's own `causedByConcurrencyError` says true for the same exception, so the project's classifier is stricter than the framework's on PostgreSQL.
- **Lock timeout has the same hole:** with `SET LOCAL lock_timeout = '200ms'` the error is `["55P03",7]` "canceling statement due to lock timeout", and `isDeadlock` is also false.
- **Transaction is dead afterwards:** the next statement in the same transaction fails with 25P02, as the claim says.

**Reach.**
- `TransactionPolicy::atomic()` has four callers: `TnUserProvisioner.php:610` and `:658`, `GroupPostIngestionService.php:690`, and `RippleEngagementService.php:130`.
- The two TN services are the TrashNothing ingest; `tn:sync` is scheduled `everyTenSeconds()` (`routes/console.php:869`).
- `RippleEngagementService` is used from `ripple:clear-engaged`, `User.php` and two backfill services.
- `bulk()` has no callers in `app/`, so that part of the claim is moot.
- `AppServiceProvider.php:43-51` does register `DeadlockRetryConnection` only for 'mysql'; the pgsql resolver returns `JsonTextPostgresConnection`. No file under `app/Database`, `config/database.php`, `database/postgres/*.sql` or `database-engines.md` mentions 40P01, 55P03 or `lock_timeout`.

**Where the claim is overstated.**
- **Autocommit retry matters less on PostgreSQL.** `DeadlockRetryConnection` exists for Galera certification conflicts. A single-primary PostgreSQL cannot deadlock a single-row autocommit statement; only multi-row statements locking rows in different orders can.
- **`tn:sync` is single-instance** (its own flock), so it can only deadlock against other writers such as the Go API, not against itself.
- **Go side is mostly cosmetic.** `IsDeadlockOrLockTimeout` (`database/retry.go:55`) matches "deadlock" in pgx's text, so `RetryOnDeadlock` in abtest and newsfeed works. It does not match "canceling statement due to lock timeout". `sentrylog.IsDeadlock` (`report.go:44`) is MySQL-only, so a PostgreSQL deadlock goes to Sentry instead of the DEADLOCK log line.
- **Not verified:** whether a dropped pgx connection is already retried by database/sql before `isConnectionError` matters, and whether a failed `tn:sync` item is picked up again on the next run.
- **Go account merge:** about 50 of roughly 57 `tx` statements between `user.go:3400` and `3800` discard their error. On PostgreSQL the first failure aborts the rest (25P02 shown above). That is a behaviour difference, not a performance one, and arguably safer than a partial commit.

**Server settings on the scratch server:** `deadlock_timeout` 1s, `lock_timeout` 0, `idle_in_transaction_session_timeout` 0. A blocked writer waits indefinitely, where InnoDB gives up after 50s.

**Fix**

**Smallest fix:** in `TransactionPolicy::isDeadlock`, immediately before the `is_numeric($info[1])` test, add:

```php
if (is_array($info) && in_array($info[0] ?? null, ['40P01', '55P03'], true)) {
    return true;
}
```

I tried this on a patched in-memory copy of the class (the repository file was not edited) against real errors from iznik_perf:

| Error | Current | Patched |
|---|---|---|
| PostgreSQL deadlock 40P01 | false | true |
| PostgreSQL lock timeout 55P03 | false | true |
| PostgreSQL division by zero 22012 | false | false |
| MySQL 1213 | true | true |
| MySQL 1205 | true | true |
| MySQL 3516 (geometry) | false | false |

MySQL never emits those two SQLSTATEs, so MySQL statements and MySQL classification are unchanged. Not tested: `atomic()` actually re-running a transaction end to end, since that needs the Laravel application booted.

**Optional follow-ups, all PostgreSQL-only and untested:**
- Give `JsonTextPostgresConnection` the same autocommit `handleQueryException` retry as `DeadlockRetryConnection`. Low value on a single primary.
- In Go, add an `errors.As(*pgconn.PgError)` check for 40P01/55P03 to `IsDeadlockOrLockTimeout` and `sentrylog.IsDeadlock`.
- Set `lock_timeout` (for example 50s, to match InnoDB) and `idle_in_transaction_session_timeout` on the application role, so a blocked writer cannot wait indefinitely.

**How the synthetic data could mislead**

Data volume is irrelevant here: the result depends on SQLSTATE and the PDO errorInfo shape, which are the same at any size. What the scratch database cannot show is how often deadlocks would happen in production. There is no concurrent workload, so the rate of 40P01 between tn:sync, ripple writers and the Go API is unknown; it is probably lower than the Galera conflict rate today, because there are no certification conflicts. The lock_timeout and idle_in_transaction_session_timeout values of 0 are the scratch container's defaults, not a production PostgreSQL configuration, which does not exist yet.

### 27. GET /donations fails on PostgreSQL: a timestamptz column is compared with DATE_FORMAT() text, the error is not checked, and 'raised' is always 0

- **Severity:** minor (claimed outage-risk). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/donations/donations.go:189
- **How often:** Every call of the donations endpoint (GetDonations in donations/donations.go), which feeds the monthly fundraising target display.

**Evidence**

The mechanism is real, but it is a silent wrong answer rather than an outage, and there is no performance problem once the type error is fixed.

**Code**
- `iznik-server-go/donations/donations.go:189` builds `timestamp >= DATE_FORMAT(NOW(), '%Y-%m-01')`.
- Line 200 is `tx.Where(whereSQL, whereArgs...).Scan(&raised)` with the error discarded, so the handler returns 200 with `raised: 0`.
- The route is live: `router/routes.go:1851` registers `rg.Get("/donations", donations.GetDonations)`, and the frontend calls it from `iznik-nuxt3/api/DonationsAPI.js:5`.
- `test/donations_test.go:36` only asserts `raised >= 0`, which is why the suite passes.

**What PostgreSQL receives**
- The translator has no DATE_FORMAT rule (no match in `iznik-server-go/database/*.go`). It only quotes the mixed-case columns (`pgtranslate.go:205-208`: `"GrossAmount"`, `"Payer"`).
- `functions.sql:317` defines `date_format(timestamptz, text) RETURNS text`.
- `pg_operator` on iznik_perf has `timestamptz >=` only for date, timestamp and timestamptz, and there is no implicit cast from text to timestamptz.

**Run on iznik_perf**
- The statement as sent, both with a literal and as `PREPARE ... $1`:
  `ERROR: operator does not exist: timestamp with time zone >= text`
- With the type corrected (`timestamp >= date_trunc('month', now())`), the plan is:
  `Bitmap Index Scan on users_donations_timestamp ... Index Cond: ("timestamp" >= date_trunc('month', now()))`, estimated 947 rows, actual 951, `Buffers: shared read=33`.
- The group variant (join to memberships on the largest group) is a nested loop from those 951 rows into `Index Only Scan using memberships_userid_groupid` with Memoize: 849 probes, about 3,000 buffers, 40 rows out. That is the same shape MySQL uses (range on KEY timestamp, then probe memberships by userid and groupid).

**Why the severity is lower than claimed**
- Nothing crashes or slows down. The endpoint returns `raised: 0` every time, so the monthly fundraising figure is wrong. It must be fixed before cutover, but it is not an outage risk.

**Fix**

**Smallest fix that leaves MySQL's statement byte-for-byte unchanged:** a translator rule in `iznik-server-go/database/pgtranslate*.go` that wraps a DATE_FORMAT() used as a comparison operand in a cast, or a PostgreSQL-only branch at `donations.go:189`.

- I ran both `DATE_FORMAT(NOW(), '%Y-%m-01')::timestamptz` and `CAST(DATE_FORMAT(NOW(), '%Y-%m-01') AS timestamp)` on the scratch database. Each gave the same Bitmap Index Scan on `users_donations_timestamp` (951 rows, 33 to 36 buffers).
- `CAST(... AS DATETIME)` written in the Go source would also be valid MySQL, but it changes the MySQL statement text and I did not check how the translator renders it.

**Alternative I tried and do not recommend:** a `>=` (timestamptz, text) operator in `functions.sql`, backed by an inlinable SQL function.
- In a rolled-back transaction the unmodified statement then ran with the same index plan.
- A global timestamptz-versus-text operator would hide this class of type mismatch everywhere else.

**The claim's suggestion** (compute the first of the month in Go and bind it) works on both engines but changes the MySQL statement.

**Also needed, whichever fix is chosen:**
- Check the Scan error at `donations.go:200`.
- Make `donations_test.go` seed a donation and assert it is counted.

**How the synthetic data could mislead**

The failure is a type-resolution error, so it does not depend on the data at all. For the fixed plan, the scratch table has 42,000 donations (about 138k in production) spread so that 951 fall in the current month; real month-to-date volume may differ, but it is always a small range at the end of the (timestamp, GrossAmount) index. The group-join cost scales with donations this month, not with the size of memberships.

### 28. Collate(..., '_ci') renders LOWER(x) on PostgreSQL, so the items.name join in the nightly stats is case-sensitive and cannot use items_name

- **Severity:** minor (claimed slow-at-scale). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-batch/app/Database/Expressions/Collate.php:23 (used at iznik-batch/app/Services/StatsGenerationService.php:562, 594, 813, 844)
- **How often:** Nightly: stats:generate-daily (routes/console.php:1792), four statements per run.

**Evidence**

The mechanism is real, but the performance cost is small. The correctness defect is the part that matters.

**Code**
- `iznik-batch/app/Database/Expressions/Collate.php` `getValue()` returns `'LOWER('.$operand.')'` on PostgreSQL for any `_ci` collation.
- It has exactly four uses, all in `iznik-batch/app/Services/StatsGenerationService.php` (lines 562, 594, 813, 844): `$j->on('i.name', '=', new Collate('bi.name', 'utf8mb4_unicode_ci'))`.
- Lines 562 and 594 are in `buildDailyContext`, run once per date by `stats:generate-daily` (scheduled at `routes/console.php:1792`). That is two statements a night, not four.
- Lines 813 and 844 are in `regenerateWeightForRange`, a backfill that runs two statements per date in the range.
- PostgreSQL has `items.name` as citext with a unique btree `items_name`; `messages_bulk_items.name` is `varchar(255)`. The only citext `=` operator is citext = citext, so `i.name = LOWER(bi.name)` resolves as text = text.

**Semantics**
- `select 'Sofa'::citext = lower('Sofa'::varchar)` returns `f`; with `'sofa'::varchar::citext` it returns `t`.
- I inserted 20,000 bulk items and 20,000 interest rows in a rolled-back transaction, with names copied from `items` (all capitalised in the scratch data) and 400 flipped on the target day.
- The LOWER form matched 0 of 400. The `CAST(bi.name AS citext)` form matched 400 of 400.
- The effect is silent: `Coalesce(NullIf(i.weight,0), $avg)` falls back to the average weight for every unmatched item, so the stats are wrong with no error.

**Plan, bulk-flip arm as rendered today (LOWER)**
```
Hash Right Join (actual rows=400)
  Hash Cond: ((i.name)::text = lower((bi.name)::text))
  -> Seq Scan on items i (rows=1100000)  Buffers: shared hit=4251 read=7090
```
- Total 13,272 buffers, 135 ms.

**Plan, collected arm as rendered today (LOWER)**
```
Hash Left Join  Hash Cond: (lower((bi.name)::text) = (i.name)::text)
  -> Hash (rows=1100000) Buckets: 131072 Batches: 16, temp written=5548
     -> Seq Scan on items i
```
- Here the planner hashes all 1.1M items and spills to disk at work_mem 4MB. Total 14,688 shared buffers plus 5,578 temp, 310 ms.

**Plan with CAST(bi.name AS citext)**
```
Nested Loop Left Join
  -> Index Scan using items_name on items i (loops=400)
     Index Cond: (name = (bi.name)::citext)
```
- Bulk-flip arm: 3,531 buffers in total. Collected arm: 7,347 buffers, no temp files.

**Why the performance side is smaller than claimed**
- `items` is 9,577 pages here, so roughly 32k pages (about 250 MB) at production's 3.7M rows.
- One sequential scan, or a 16-batch hash, of that twice a night is well under a few seconds. It does not grow with the number of bulk items.
- Neither engine has an index on `messages_bulk_items.updated_at` or `messages_bulk_items_interest.updated_at`, so both already scan those tables.
- MySQL today does a unique-index lookup on `items.name` per row; PostgreSQL does not, but the absolute cost is minor.
- The backfill path multiplies it by two per date (about 360 scans for a 180-day range), which is minutes at most and run by hand.

A bare `i.name = bi.name` with no wrapper also plans as `Hash Cond: ((i.name)::text = (bi.name)::text)`, so simply dropping the LOWER would not fix it; the explicit citext cast is needed.

The scratch table was confirmed empty again after the ROLLBACK. The test script is `/tmp/claude-1000/-home-edward-FreegleDockerWSL/e9755156-8275-40b1-9e24-50fa4d9c0708/scratchpad/collate_test.sql`.

**Fix**

In `iznik-batch/app/Database/Expressions/Collate.php`, change the PostgreSQL branch for `_ci` collations to render `CAST(<operand> AS citext)` instead of `LOWER(<operand>)`.

- This is a one-line change in the PostgreSQL-only branch. The MySQL rendering (`<operand> COLLATE utf8mb4_unicode_ci`) is untouched, so production statements stay byte-for-byte the same.
- All four call sites compare against `items.name`, which is citext, so the cast gives a citext = citext comparison that is case-insensitive and can use `items_name`.
- Add a test with a mixed-case item name (for example items.name 'Sofa' against a bulk item 'sofa' and 'Sofa') asserting the item's own weight is used, not the average.

What was tested: the rewritten SQL, run by hand on the scratch database inside a rolled-back transaction. It switched to `Index Scan using items_name` and matched 400 of 400. The PHP change itself was not made or run, because the review is read-only.

**How the synthetic data could mislead**

- `messages_bulk_items` and `messages_bulk_items_interest` are empty in iznik_perf. I invented 20,000 rows of each (400 flipped and 1,000 collected on the day), so the driving-side counts are guesses. The seq scan or hash of `items` is the same whatever they are.
- Every synthetic `items.name` is capitalised ("Large Armchair"), so the 0-of-400 match rate is the worst case. If production item names are mostly lower case, most rows would still match under LOWER and only mixed-case names would lose their weight. The real case mix in production `items` was not available.
- citext is case-insensitive but not accent-insensitive, unlike `utf8mb4_unicode_ci`. That gap is in the column type and is the same with either rendering.
- `shared_buffers` is 128MB and `work_mem` 4MB here. The 16-batch hash spill in the collected arm would probably not happen with a production `work_mem`.

### 29. SUM(condition) runs through a custom sum(boolean) aggregate with a SQL transition function: about 6 times the per-row cost of count(*) FILTER

- **Severity:** minor (claimed minor). **Verdict:** confirmed. **Fix tried on the scratch database:** yes.
- **Where:** iznik-batch/database/postgres/functions.sql:411 (largest caller: iznik-server-go/group/group.go:592)
- **How often:** ModTools and admin dashboards (group.go:592 is the group listing with moderation stats; the rippling metrics and analytics pages), plus two periodic Laravel mail commands. Per page load, not per member request.

**Evidence**

The claim's mechanism and its "minor" rating both hold.

**Code**
- `iznik-batch/database/postgres/functions.sql:410-420` defines `iznik_bool_sum_step` as `LANGUAGE sql` and `CREATE AGGREGATE sum(boolean) (SFUNC = iznik_bool_sum_step, STYPE = bigint)`, with no COMBINEFUNC and no PARALLEL marking.
- `iznik-server-go/group/group.go:591-595` is real and is reached only when `isAdminOrSupport` is true in `ListGroups` (the Support Tools community list), so it runs per admin page load, not per member request.
- The translator files have no SUM rewrite and `Sum.php` renders `'SUM('.operand.')'`, so PostgreSQL receives the MySQL text unchanged and resolves it to `public.sum(boolean)`.
- I spot-checked `rippling/metrics.go:152-158` (seven `SUM(bucket = ...)` per row) and `rippling/analytics.go:916-919`; I did not measure those or the two Laravel commands.

**The real statement (30 days, `arrival >= '2026-10-14'`, 26,687 rows, 7,755 with approvedby set)**
- As shipped: `HashAggregate (cost=48796.61..48801.61 rows=500) (actual time=43.444..43.488)` over `Bitmap Heap Scan on messages_groups (rows=27861 est, 26687 actual; ends at 6.2 ms)`, `Buffers: shared hit=223 read=302`. Execution 43.5 ms (39.7 ms fully cached).
- With `count(*) FILTER (WHERE approvedby IS NOT NULL)`: same Bitmap Heap Scan on `messages_groups_arrival`, 525 buffers, `HashAggregate (actual time=7.805..7.848)`. Execution 7.9 ms.
- The scan is identical and well estimated; all the difference is in the aggregate node, about 35 ms for 26.7k rows (1.3 microseconds a row).
- At production's roughly 85k rows that is about 110-120 ms extra on a page whose sibling logs query takes seconds. MySQL reads the same range through its `(arrival, groupid, msgtype)` index, so the access path does not differ.

**The claim's 1.05M-row probe (`msgid <= 1000000`)**
- `SUM(approvedby IS NOT NULL)`: serial `HashAggregate (cost=349946.56...) (actual time=1651.714..1651.772)` over a Bitmap Heap Scan ending at 129 ms; 1,652 ms, repeated at 1,728 and 1,682 ms.
- `count(*) FILTER`: the planner picks `Finalize GroupAggregate -> Gather Merge (Workers Launched: 2) -> Partial HashAggregate -> Parallel Seq Scan`, 148-160 ms.
- With `max_parallel_workers_per_gather = 0`, `count(*) FILTER` takes 261 ms on the same bitmap plan, so the serial per-row ratio is about 6x, as claimed.
- `SUM(CASE WHEN ... THEN 1 ELSE 0 END)` also gets the parallel plan, 148 ms.
- The loss of parallel aggregation is visible: the shipped aggregate never gets a Gather.
- The planner also charges the SQL step at the default function cost: 349,946 against 89,350 for the same serial plan. That inflates the cost of any query using it, though it changed no scan choice here.

**Fix**

Smallest fix, in `functions.sql` only, with no change to any MySQL statement: redefine the step function in plpgsql and give the aggregate `COMBINEFUNC = int8pl, PARALLEL = SAFE`.

Tried inside BEGIN ... ROLLBACK as a separately named aggregate (`perf_bsum`):
- **30-day query:** the plan became `Finalize GroupAggregate -> Gather Merge (2 workers) -> Partial HashAggregate -> Parallel Bitmap Heap Scan`, 11.6 ms against 39.7-43.5 ms.
- **1.05M rows, parallel:** 282 ms against about 1,680 ms.
- **1.05M rows, serial:** 707 ms against 1,682 ms, so plpgsql roughly halves the per-row cost but is still about 2.7x `count(*) FILTER` (261 ms).
- **Results:** identical across the old aggregate, the new one and FILTER (305,384). NULL-only and empty input still return NULL, as MySQL's SUM does.

Two notes on applying it:
- The existing DO block only creates the aggregate when it is absent, so an existing database needs `CREATE OR REPLACE AGGREGATE` or a drop first.
- A lower `COST` on the step function would stop the planner over-charging it; I did not test that.

Getting all the way to FILTER speed would need the translator and `Sum.php` to emit `COUNT(*) FILTER (WHERE cond)` or `SUM(CASE WHEN cond THEN 1 ELSE 0 END)` on PostgreSQL. That also leaves MySQL untouched but is a larger change, and deciding in the translator that an operand is boolean is fragile. At today's row counts it is not worth it.

**How the synthetic data could mislead**

- **Row volume:** the 30-day window here is 26.7k rows against about 85k in production, so the absolute cost is roughly 3.2x what I measured (about 110-120 ms against 35 ms). It is linear in rows aggregated and does not depend on skew or selectivity.
- **Timings:** the result rests on wall-clock deltas inside EXPLAIN ANALYZE on a shared host. They were stable across three runs (1,652-1,728 ms) and the scan beneath was identical, but the per-row figure is good to perhaps 20%.
- **Parallelism:** the win seen with the fix depends on default parallel settings and free workers; production settings may differ.
- **Rippling callers:** `metrics.go` and `analytics.go` aggregate over chat and reach joins whose synthetic shape is invented, and `metrics.go` runs seven SUMs per row. I did not measure them, so their tax could be several times the `group.go` one per row.

### 30. View counts on the browse feed: messages_likes loses InnoDB's clustering by (msgid, userid, type), so each post's views cost about four times the page touches once rows arrive in time order

- **Severity:** minor (claimed worth-measuring). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/isochrone/message.go:211 (also :556 and :759). Schema cause: iznik-batch/database/migrations/2025_12_10_094529_create_messages_likes_table.php has no primary key, so InnoDB clusters on UNIQUE msgid_2 (msgid, userid, type).
- **How often:** Every uncached nearby or mygroups browse feed load (GET /isochrone/message and the mygroups view), once per candidate post, typically hundreds to about a thousand per load. Also dashboard/popular.go:207 and :244.

**Evidence**

The mechanism is real and the claim's numbers reproduce, but the consequence is small, and the comparison with MySQL is weaker than stated.

**Code**
- The statement is at `iznik-server-go/isochrone/message.go` lines 211, 556 and 759: `COALESCE((SELECT SUM(mlv.count) FROM messages_likes mlv WHERE mlv.msgid = ms.msgid AND mlv.type = ?), 0) AS views`.
- It is reached from `GET /isochrone/message` (`router/routes.go:700`), once per candidate post, with no LIMIT on the candidate set.
- I did not trace the translator output; the subquery is plain SQL with nothing MySQL-specific, so I ran it by hand as the claim did.
- MySQL has no primary key on the table, so InnoDB clusters on `UNIQUE msgid_2 (msgid, userid, type)`. PostgreSQL has a heap with `messages_likes_msgid (msgid, type)`, `messages_likes_msgid_2` and `messages_likes_userid`.

**Measured on iznik_perf, all 12,434 open posts**

| Case | Plan node | Buffers | Per post |
|---|---|---|---|
| Table as loaded (msgid correlation 1.0) | `Index Scan using messages_likes_msgid ... rows=14.41 loops=12434` | 51,548 | 4.1 |
| Scattered copy of newest 60k posts' likes (906,022 rows, 7,552 pages) | `Bitmap Heap Scan on ml_scatter ... Heap Blocks: exact=178811` | 216,944 | 17.4 |
| Scattered copy with `(msgid, type) INCLUDE (count)`, before VACUUM | Still `Bitmap Heap Scan`, `Heap Blocks: exact=178811` | 216,907 | 17.4 |
| Same, after VACUUM | `Index Only Scan using ml_s_cov ... Heap Fetches: 0` | 38,097 | 3.1 |

**Measured for a feed of the 800 newest candidates, JIT off, fully cached**
- Scattered: 14,344 buffers, 12.5 ms.
- Clustered: 3,337 buffers, 3.4 ms.
- So the worst case adds about 9 ms of CPU per uncached feed load.

**Why it is smaller than claimed**
- It is CPU only. The rows read belong to posts open in the last month or so, about 1.25M rows or roughly 80 MB of heap at production scale, which stays cached.
- "Four times the page touches" is against a clustered PostgreSQL heap, not against MySQL. MySQL reads fewer distinct pages, but whether it does fewer page accesses was not measured and cannot be on the empty MySQL tables.
- The same feed row also runs a `chat_messages` count subquery and joins `rippling_reach`; I did not measure those, so I cannot say what share of the load this column is.

**Adjacent finding, larger than the claim**
- The planner estimates `rows=350` per post against 14.41 actual on the real table, because `pg_stats` n_distinct for `msgid` is 127,959 when the true figure is in the millions.
- That prices each subplan at 628, so the 800-candidate query costs 502,581. That is above the default `jit_above_cost` (100,000) and `jit_inline_above_cost` (500,000).
- `jit` is on in the scratch database and I found no setting for it in the repo. On the 12,434-row run JIT cost 86.5 ms (`Inlining 55.833 ms, Optimization 17.860 ms, Emission 12.540 ms`) of a 164.9 ms execution; with JIT off the same query took 66.4 ms.
- I did not run the 800-candidate query with JIT on, so the per-feed JIT cost is inferred from the cost threshold, not measured.

**Fix**

I would not change anything for the clustering effect alone at about 9 ms per feed load.

**If it is wanted: a covering index, PostgreSQL only**
- Replace `messages_likes_msgid` with `(msgid, type) INCLUDE (count)` in the PostgreSQL schema, through `tools/pg-schema/convert.mjs` or its config. MySQL statements and schema stay exactly as they are.
- Tested on a scattered temp copy: after VACUUM it gives `Index Only Scan ... Heap Fetches: 0` and 3.1 buffers per post, better than the clustered heap's 4.1.
- It only helps on pages marked all-visible. Before VACUUM the plan and buffers were unchanged (17.4 per post).
- The default insert-triggered autovacuum threshold is 1000 + 20% of the table, about 30M rows at production size, so recent pages would rarely be all-visible. The table needs a low `autovacuum_vacuum_insert_scale_factor` or a fixed insert threshold.
- Cost, by reasoning only: every mark-seen runs `count = count + 1`, and with `count` in an index those updates can no longer be HOT. My trial showed 0 HOT updates with and without the index because the pages were full, so it does not measure this.

**More valuable and cheaper: stop the misestimate that triggers JIT**
- Either set `ALTER TABLE messages_likes ALTER COLUMN msgid SET (n_distinct = -0.07)` in the PostgreSQL schema, or turn JIT off for the Go API's connections.
- Neither touches MySQL. I tested neither as a schema change; I only compared `SET jit = off` in a session (164.9 ms down to 66.4 ms on the 12,434-row run).

**How the synthetic data could mislead**

- **The scattered copy is the worst case.** It orders rows by a hash across 60k posts, giving one heap page per view row. Real arrival is probably close to that, since thousands of posts are open at once and mark-seen writes one member's rows together, but it is an upper bound.
- **The loaded table is the best case.** It was loaded in msgid order (correlation 1.0), which production would only resemble on migration day.
- **Per-post view counts drive the result.** They are synthetic: mean 14.4, with 5% of posts at 40 to 119. If open posts in production carry more views than the all-time average of about 15, the cost scales linearly.
- **The n_distinct underestimate behind the JIT observation may differ on real data.** The synthetic table has exactly contiguous msgid runs; a time-ordered production heap could give ANALYZE a better or worse estimate.
- **Server settings are defaults.** Temp-table buffers are local, not shared; I raised `temp_buffers` to 200MB so the runs were all cache hits.

### 31. Collate renders `LOWER(bi.name)` against citext items.name: the join becomes a case-sensitive text comparison that cannot use items_name and silently stops matching capitalised item names

- **Severity:** minor (claimed slow-at-scale). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-batch/app/Services/StatsGenerationService.php:562 (also :594, :813, :844; rendering: app/Database/Expressions/Collate.php:52)
- **How often:** stats:generate-daily, nightly (routes/console.php:1792): two of these statements per date. regenerateWeightForRange (:813, :844) runs two per date over a backfill range, so a two-year backfill is about 1,460 full hashes of items.

**Evidence**

The mechanism is confirmed, but it is not slow-at-scale: the cost is one sequential scan of items per statement on a nightly job. The silent mismatch on capitalised item names is the real defect.

**Code**
- `iznik-batch/app/Database/Expressions/Collate.php:52` returns `'LOWER('.$operand.')'` for any `_ci` collation on PostgreSQL.
- `tests/Unit/Database/ExpressionsPostgresTest.php:187` pins that rendering: `LOWER("a")`.
- The four sites are real: `iznik-batch/app/Services/StatsGenerationService.php:562`, `:594`, `:813`, `:844`, each `$j->on('i.name', '=', new Collate('bi.name', 'utf8mb4_unicode_ci'))`.
- Frequency: `stats:generate-daily` runs once a day at 02:30 (`routes/console.php:1792`). `regenerateWeightForRange` is reached only from the manual command `app/Console/Commands/Stats/RegenerateWeightsCommand.php:49`.
- Types on iznik_perf: `items.name` is citext with `"items_name" UNIQUE, btree (name)`; `messages_bulk_items.name` is `character varying(255)`.
- MySQL today: both columns are utf8mb4_unicode_ci and items has `UNIQUE KEY name (name)`, so the join is a case-insensitive unique lookup per bulk row.

**Plans**
I hand-wrote the Laravel statement and ran it in BEGIN ... ROLLBACK with trial bulk rows named exactly as items rows are stored, then ANALYZE. The table was back to 0 rows afterwards.

| Trial | Join as rendered (`LOWER`) | Join with `CAST(... AS citext)` |
|---|---|---|
| 20 rows flipped today (1,220 in table) | Hash Right Join over Seq Scan on items, `shared hit=100 read=11341`, 138 ms | Nested Loop Left Join with Index Scan on items_name, `shared hit=159 read=24`, 0.77 ms |
| 2,000 rows flipped today (122,000 in table) | Parallel Hash Left Join, 16 batches, `temp read=6652 written=6788`, 243 ms | Index Scan on items_name, 2,000 loops, `shared hit=16284 read=1683`, 45 ms |

Key lines from the as-rendered plan at 20 rows:
- `Hash Cond: ((i.name)::text = lower((bi.name)::text))`
- `Seq Scan on items i ... rows=1100000 ... Buffers: shared hit=1 read=11340`

Key line from the cast plan: `Index Cond: (name = (bi.name)::citext)`.

**Why this is smaller than claimed**
- The cost is one scan of items per statement, whatever the number of bulk rows: 11,341 buffers here, about 38,000 (roughly 300 MB) at 3.7M production rows.
- That is well under a second each, twice a night.
- A two-year backfill adds about 1,460 such scans to a manual one-off command, which is minutes, not an outage.
- Both variants also seq scan messages_bulk_items, because there is no index on `updated_at` on either engine. That is the same on MySQL.

**Wrong answer, confirmed**
- `select 'Sofa'::citext = lower('Sofa'::varchar)` returns false; `'sofa'::citext = lower('Sofa'::varchar)` returns true.
- In the trial the as-rendered join matched 0 of 20 and 0 of 2,000 rows; the citext cast matched 20 of 20 and 2,000 of 2,000.
- So any item whose stored name has a capital silently gets the average weight on PostgreSQL, where MySQL uses its real weight.

**One correction to the claim's suggested fix**
Dropping the Collate does not restore the index. A bare `i.name = bi.name` plans as `Hash Cond: ((i.name)::text = (bi.name)::text)` over Seq Scan on items. It matched 2,000 of 2,000 only because the trial names were copied in their stored case. The explicit cast to citext is required.

**Fix**

**Smallest fix:** in the PostgreSQL branch of `iznik-batch/app/Database/Expressions/Collate.php:52`, render `_ci` as `CAST(<operand> AS citext)` instead of `LOWER(<operand>)`, and update the assertion at `tests/Unit/Database/ExpressionsPostgresTest.php:187`.

- This is tested on the scratch database: the join becomes `Index Scan using items_name ... Index Cond: (name = (bi.name)::citext)` and matches case-insensitively (2,000 of 2,000).
- MySQL statements are unchanged: the MySQL branch still emits `bi.name COLLATE utf8mb4_unicode_ci`.
- All four call sites compare against `items.name`, which is citext, so the cast is correct for every current use.

**Caveat for future callers:** `citext = text` resolves as `text = text`, so `CAST(x AS citext)` against a plain text or varchar column would be case-sensitive. The class comment should say that the other side must be citext, or that both sides need wrapping.

**Not a fix:** removing the Collate at these sites on PostgreSQL. A bare `citext = varchar` comparison also resolves to a text comparison, with a Seq Scan on items and case-sensitive matching.

**How the synthetic data could mislead**

- **Match rate is a worst case.** All 1,100,000 synthetic items names contain a capital, so the as-rendered join matched nothing. In production the miss rate is the share of bulk rows whose items row has any upper-case character, which I could not measure.
- **Bulk table is invented.** messages_bulk_items is empty in iznik_perf, so the trial rows (20 or 2,000 a day, 60 days of history) are my guess. Clearances are a new feature and real volume is probably nearer the small case. The items scan cost does not depend on it.
- **Items is 3.4x smaller than production.** At 3.7M rows the hash side spills further at work_mem 4MB.
- **Timings are from a shared host with 128MB shared_buffers.** Rely on the buffer counts and plan shape, not the milliseconds.
- **Only one of the four statements was run.** I tested the bulk-flip statement at `:562`. The `:594` variant drives from messages_bulk_items_interest, which is also empty, but has the identical join predicate.

### 32. Daily users_approxlocs refresh rewrites every row and adds a GiST entry for each, where MySQL writes nothing for unchanged members

- **Severity:** minor (claimed minor). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-batch/app/Services/UserApproxLocService.php:259
- **How often:** Once a day at 04:45 (users:update-approx-locs, routes/console.php:1509), in 500-row statements over every member active in the last ~181 days. The table's GiST index is the driving side of the routing service's reachable-groups query on every ripple tick.

**Evidence**

The mechanism is real, but the claim's headline numbers (0 HOT updates, GiST growing by half) only occur when every heap page is full.

**Code and schedule**
- The statement is real and reached on PostgreSQL: `iznik-batch/app/Services/UserApproxLocService.php` `upsert()` calls `DB::table('users_approxlocs')->upsert($values, ['userid'], ['lat','lng','position','timestamp'])` in 500-row chunks.
- It runs once a day: `routes/console.php:1509` has `Schedule::command('users:update-approx-locs')->dailyAt('04:45')`.
- The SQL PostgreSQL receives matches the claim. Laravel's `PostgresGrammar::compileUpsert` emits `on conflict ("userid") do update set "lat" = "excluded"."lat", ...` with no WHERE, and nothing in `app/Database` overrides it.
- `StSrid` and `Point` render `ST_SetSRID(ST_MakePoint(?, ?), 4326)`; `config/freegle.php:795` sets srid 4326 on pgsql.
- Every recently active member is sent every day, changed or not. `timestamp` is set to `users.lastaccess`, so it only changes for members who visited since the last run.

**Method**
- All runs were inside BEGIN ... ROLLBACK, with 377,163 rows (every 181-day-active member with a location and a membership).
- The clean numbers come from a transaction-local copy `perf.sk13` (LIKE users_approxlocs INCLUDING ALL, plus the row trigger). On the real table, a same-transaction load makes the foreign-key trigger fire for every row (`Trigger for constraint users_approxlocs_userid_foreign: calls=377164`), which would not happen against committed rows.

| Scenario | Updates / HOT | Size change | WAL |
|---|---|---|---|
| A: full pages, identical values (the claim's case) | 377,163 / 0 | heap 38.2 to 76.3 MB, GiST 15.8 to 31.7 MB, timestamp index 10.1 to 18.6 MB, userid index 10.3 to 18.8 MB | records=2669668 fpi=10105 bytes=296229483 |
| B: fillfactor 50 (pages with free space), identical values | 377,163 / 377,163 | heap and all indexes byte-for-byte unchanged | records=765756 fpi=9430 bytes=85135114 |
| B2: fillfactor 50, 15% of rows with a new timestamp | 377,163 / 317,553 | GiST 15.8 to 18.3 MB | bytes=142513384 |
| C: full pages, the claim's WHERE guard, identical values | 0 / 0 | none | records=388593 fpi=4657 bytes=59349613 |
| E: fillfactor 50, only the 15% changed rows sent | 59,609 / 0 | not measured | records=365171 bytes=102699638 |

- **A reproduces the claim.** Plan lines: `Conflicting Tuples: 377163`, `Buffers: shared hit=8670970 read=32068 dirtied=44452`.
- **B refutes "a GiST entry for each".** With room on the page, PostgreSQL sees no indexed column changed and every update is HOT. Buffers fall to `hit=3016049 dirtied=9431`. About 77 MB of the 85 MB of WAL is full-page images, forced by the CHECKPOINT I issued just before.
- **B2 is the realistic daily mix.** The 59,610 non-HOT rows are the ones whose indexed timestamp changed, and MySQL writes those too.
- **C shows the guard is not free.** The plan reports `Rows Removed by Conflict Filter: 377163` and `dirtied=4658`: ON CONFLICT DO UPDATE locks each conflicting row before evaluating WHERE, so "confirm zero rows are written" holds for tuples but not for WAL or dirty pages.
- **Sequence use:** every proposed row consumes an identity value (11,430 sequence WAL records per pass). The column is bigint, so this is harmless.

**Steady state**
- A table rewritten 100% daily trips autovacuum daily and settles at roughly twice its packed size, which is the fillfactor-50 case.
- The real excess over MySQL is therefore one HOT heap update per unchanged member per day. That is about 2 WAL records a row with no index writes, once a day at 04:45, on a table of tens of MB.
- The first pass after a bulk load or restore (pages 100% full) behaves like scenario A, once.

**Side effect of my testing**
- My first script ran on the real `users_approxlocs`, and the rolled-back rows left it at 312 MB of dead space with 0 live rows.
- I ran plain `VACUUM (INDEX_CLEANUP ON, TRUNCATE ON) users_approxlocs`, which returned the heap to 0 bytes.
- Its four indexes still hold empty reusable pages: GiST 110 MB, pkey 71 MB, timestamp 43 MB, userid 24 MB. The table is empty, as before, and no plan is affected, but `pg_relation_size` on those indexes is no longer a clean baseline. Only a REINDEX would shrink them, and I did not run one.
- Scripts: `/tmp/claude-1000/-home-edward-FreegleDockerWSL/e9755156-8275-40b1-9e24-50fa4d9c0708/scratchpad/sk13/t.sql` and `/tmp/claude-1000/-home-edward-FreegleDockerWSL/e9755156-8275-40b1-9e24-50fa4d9c0708/scratchpad/sk13/t2.sql`.

**Fix**

Doing nothing is defensible: in steady state the cost is one HOT update per active member per day, with no index churn.

If it is worth removing, the cheapest effective change is to drop unchanged members before the upsert:
- In `UserApproxLocService::updateLocations()`, read `userid, lat, lng, timestamp` from `users_approxlocs` for the chunk's ids and remove rows whose values already match from `$pending`.
- Scenario E shows only the changed rows are then touched, with no per-row lock records for the rest.
- Gated on the pgsql driver, this leaves MySQL's statements exactly as they are. Ungated, MySQL gains one SELECT per chunk and a shorter upsert, with identical resulting data.

The claim's `WHERE ... IS DISTINCT FROM` guard is a weaker alternative:
- It stops new tuple versions, bloat and trigger calls (scenario C: 0 updates).
- It still locks every conflicting row: 388,593 WAL records and 4,658 dirtied pages.
- It needs a raw PostgreSQL-only statement, because Laravel's `upsert()` cannot express it.

A schema-only option leaves all code and MySQL untouched: give the PostgreSQL table a fillfactor of about 70 through `tools/pg-schema/convert.mjs` and its config. The first pass after a load or restore is then HOT as well. Scenario B showed 100% HOT at fillfactor 50; I did not test 70.

**How the synthetic data could mislead**

- **Table size is a guess.** `users_approxlocs` is empty in the scratch database and its production row count is not in the brief. I loaded 377k rows from `users.lastaccess`, whose synthetic curve puts 40% of members inside 181 days; real production may differ a lot. Total WAL scales linearly with that count.
- **The 15% daily-changed share is my assumption.** The true share of six-month-active members who visit on a given day sets how many updates are non-HOT, on both engines.
- **Free space was simulated.** I used fillfactor 50 in place of real autovacuum behaviour, because VACUUM cannot run inside a rolled-back transaction. If autovacuum is blocked by a long-running transaction or falls behind, pages stay full and passes look like scenario A (non-HOT, indexes grow) until it catches up.
- **Full-page-image volume is inflated.** It reflects max_wal_size 1GB and a checkpoint issued immediately before each run.
- **Point distribution is unrealistic.** Points are uniform over a box rather than clustered in towns, so GiST page-split behaviour in the non-HOT case will differ somewhat.

### 33. Go translator turns MBRContains into exact ST_Contains, so the degraded reach filter runs the same exact test twice per candidate (Laravel maps it to the bbox operator instead)

- **Severity:** minor (claimed minor). **Verdict:** confirmed. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/database/pgtranslate_more.go:96 (rule); worst site iznik-server-go/rippling/reachbounds.go:27
- **How often:** Only on the degraded browse/search path, when the spatial server is unreachable (isochrone/reachbounds.go:61, message/search.go:424). Then it is per browse page view and per search.

**Evidence**

The claim holds: the duplicate exact test is real and costs about 4 extra TOAST buffers and 10 microseconds per bbox candidate, which is small next to the rest of the degraded query.

CODE
- The rule is real: iznik-server-go/database/pgtranslate_more.go:96 has `"MBRCONTAINS": "ST_Contains"` in the plain rename table, while MBRIntersects gets its own bbox rewrite at line 384.
- The pair is real: iznik-server-go/rippling/reachbounds.go:27 emits `AND MBRContains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) AND ST_Contains(rr.outer_bound, ST_SRID(POINT(?, ?), ?))`.
- PostgreSQL therefore receives `ST_Contains(rr.outer_bound, ST_SetSRID(ST_MakePoint($1,$2),$3)) AND ST_Contains(rr.outer_bound, ST_SetSRID(ST_MakePoint($4,$5),$6))`. I derived this from the rename table and the ST_SRID rule at line 342; I did not run the translator.
- It has one caller: iznik-server-go/isochrone/reachbounds.go:61, reached only when spatialReachIDs fails (spatial server down). That path serves the browse feed (fetchReachCandidates) and the badge count.
- One correction to the claim: iznik-server-go/message/search.go:424 is a degraded path but uses a single ST_Contains, so search has no duplicate.
- Laravel differs as claimed: iznik-batch/app/Database/Expressions/MbrContains.php is documented as the bounding-box operator on PostgreSQL.

PLANS (iznik_perf, plan_cache_mode = force_generic_plan, warm cache)
Adversarial viewer point (-4.625, 55.625), the densest on a 1/8-degree grid: 430 bbox candidates, 377 exact hits. Grid percentiles of bbox candidates are median 12, p90 41, p99 98.

Join of rippling_reach to messages_spatial only:

| Variant | Buffers on the rippling_reach scan | Total buffers | Execution time |
|---|---|---|---|
| Two ST_Contains (as translated today) | 4094 | 5191 | 11.0 ms |
| One ST_Contains | 2362 | 3459 | 6.5 ms |
| `outer_bound ~ point` AND ST_Contains (the fix) | 2362 | 3459 | 6.4-6.6 ms |
| `~` only | 435 | 1687 | 0.75 ms |

Key lines from the two-ST_Contains plan:
```
Index Scan using rippling_reach_rippling_reach_outer on rippling_reach rr (actual rows=375)
  Index Cond: ((outer_bound ~ st_setsrid(st_makepoint($1, $2), $3)) AND (outer_bound ~ st_setsrid(st_makepoint($4, $5), $6)))
  Filter: ((status <> 'held') AND st_contains(outer_bound, ...$1,$2,$3) AND st_contains(outer_bound, ...$4,$5,$6))
  Rows Removed by Filter: 55
  Buffers: shared hit=4094
```
The planner does not merge the two conjuncts. The 1,732 extra buffers are about 4 TOAST pages per candidate, so the 7 KB bound is detoasted a second time.

Fuller degraded feed query (joins to messages and users, the views, replies and visiblesince subqueries, ST_AsText(ST_Envelope(outer_bound)), polygon_cells, with SERIALIZE):

| Viewer point | Two ST_Contains | With the fix |
|---|---|---|
| Dense (430 candidates) | 13,622 buffers, 27.1 ms | 11,890 buffers, 22.4-22.6 ms |
| Median (-1.5, 52.4; 17 candidates) | 2.4 ms on a part-cold cache (106 buffers read) | 429 buffers, 1.1 ms warm |

- At the dense point the duplicate is about 13% of the buffers and 17% of the time. The median-point pair is not a like-for-like comparison because the first run was part-cold.
- Serialization ships 13.9 MB of polygon_cells for the Go-side probe, which is a larger cost of this path than the duplicate.

SIZE OF THE EFFECT
- The claim's "about half of it redundant" is slightly high: on the filter alone it is about 40% (4.5 of 11 ms).
- Linear extrapolation gives roughly 5 ms redundant at 472 hits and 45 ms at 4,095 hits with 7 KB bounds.
- A comment at iznik-server-go/isochrone/message.go:226 says production bounds are about 19 KB, which would put it nearer 10 to 120 ms. That matches the claim's "tens to low hundreds of milliseconds" only at the top of the range.

MYSQL TODAY
MBRContains is the R-tree access predicate and ST_Contains runs once per candidate. So PostgreSQL does one extra exact test per candidate relative to MySQL, on an emergency path only. Index use and results are the same, and there is no plan-shape risk.

**Fix**

In iznik-server-go/database/pgtranslate_more.go, remove "MBRCONTAINS" from the rename table and translate MBRContains(a, b) to (a ~ b), next to the existing MBRIntersects -> (a && b) rule.

- This is translator-only, so every MySQL statement is unchanged.
- It restores MySQL's bbox-only meaning and matches the Laravel MbrContains class.
- The newsfeed sites (iznik-server-go/newsfeed/newsfeed.go:437, 477, 497, 567) pass a rectangle as the container and a point as the contained value, so bbox and exact tests return the same rows there.
- I tested the resulting predicate by hand on iznik_perf: the scan drops from 4094 to 2362 buffers and 11.0 to about 6.5 ms, identical to a single ST_Contains, with the same 347 rows. The translator code itself was not changed or run (read-only review), and it would need a translator test case alongside the existing "mbr" one in pgtranslate_test.go.

**How the synthetic data could mislead**

- Synthetic outer bounds are 503-vertex circles of about 7 KB stored uncompressed. A code comment puts production bounds at about 19 KB, so the per-candidate detoast and point-in-polygon cost is probably 2 to 3 times higher there.
- Synthetic reach is uniform over a box, so only the single densest grid point reaches 430 candidates (median 12). Production comments record 472 to 4,095 true hits per viewer, so typical production viewers sit at or above my adversarial case.
- The scratch data has no POINT sentinels on completed posts and only 13,767 reach rows against 45k in production, so absolute candidate counts are not transferable.
- All figures are warm-cache with shared_buffers at 128 MB. With cold TOAST the second detoast is mostly a cache hit after the first, so the redundant share would be smaller in I/O terms, not larger.

### 34. tn:merge-crossposts on PostgreSQL moves rows one savepoint at a time, overflowing the subtransaction cache on any post with more than 64 referencing rows

- **Severity:** minor (claimed minor). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-batch/app/Console/Commands/Dedup/TnMergeCrosspostsCommand.php:58 (moveRowsPostgres, called from mergeCopy at line 208 inside DB::transaction)
- **How often:** A manually run, one-off command (not in routes/console.php), per duplicate TrashNothing post within --days=90: roughly 120 statements plus three per referencing row for each copy merged.

**Evidence**

The mechanism is real, but the command is run by hand, is expected to find nothing on a healthy database, and holds each overflowed transaction only briefly.

**Code** (iznik-batch/app/Console/Commands/Dedup/TnMergeCrosspostsCommand.php)
- `moveRowsPostgres` is at lines 175-193, not line 58 as the claim cites. It is called from `mergeCopy` at line 208 inside `DB::transaction`, on the pgsql driver only.
- It sends `SELECT ctid::text AS rowid FROM "<table>" WHERE "<col>" = ?`, then per row `UPDATE "<table>" SET "<col>" = ? WHERE ctid = ?::tid`.
- Each per-row update is wrapped in `Sql::attempt` (iznik-batch/app/Database/Sql.php:202), which is a nested `DB::transaction` when a transaction is open. Laravel renders that as SAVEPOINT / RELEASE SAVEPOINT, so the claim's three round trips per row is right.
- MySQL keeps one `UPDATE IGNORE` per table (line 211).
- `information_schema` on iznik_perf shows 58 msgid columns, plus `chat_messages.refmsgid`.

**How often it runs**
- It is not in `routes/console.php`. The only other mentions are comments in `GroupPostIngestionService.php:1025` and `ExpandService.php:2064`, and the docs.
- The class docblock says it "is expected to find nothing on a healthy database". Its own comment puts the 90-day backlog at about 11k sets.

**Probe on iznik_perf (PostgreSQL 18.6), all inside BEGIN ... ROLLBACK**
- Largest post: msgid 3049297 with 610 `rippling_reach_notified` rows.
- The lookup is fine: `Index Scan using rippling_reach_notified_pkey ... (rows=366) (actual rows=610.00)`, `Index Cond: (msgid = 3049297)`, `Buffers: shared hit=164 read=11`, 0.3 ms.
- Per-row path: a PL/pgSQL loop with one BEGIN/EXCEPTION block (one subtransaction) per row, updating by ctid, moved 610 rows. `pg_stat_get_backend_subxact` then returned `subxact_count 64 | subxact_overflowed t`.
- Set-based path: `SAVEPOINT s; UPDATE rippling_reach_notified SET msgid = msgid WHERE msgid = 3049297; RELEASE s` gave `Update ... Index Scan using rippling_reach_notified_pkey (actual rows=610.00)`, `Buffers: shared hit=6328 read=574`, 18.7 ms, and `subxact_count 1 | subxact_overflowed f`.

**Why it is smaller than it sounds**
- The overflow lasts only for one copy's transaction, which is on the order of a second: about 120 statements plus three per referencing row.
- Other sessions pay `pg_subtrans` lookups only while that transaction is open. The known cliff needs a long-running overflowed transaction alongside heavy concurrent load, and this is neither.
- Each written subtransaction also consumes a transaction id. That is about 600 per reach post and a few tens for an ordinary post, so a few hundred thousand over an 11k-set backlog. That is not a wraparound concern.
- The larger practical cost is round trips: about 1,800 for a 610-row table against 1 on MySQL. For a batched manual run that is slow, not dangerous.

**Fix**

Change only `moveRowsPostgres`, which is the PostgreSQL-only branch. The MySQL `UPDATE IGNORE` statement at line 211 stays byte-for-byte the same.

- First try the set-based statement in a single savepoint: `Sql::attempt(fn () => DB::statement("UPDATE {table} SET {col} = ? WHERE {col} = ?", [$canonicalId, $copyId]))`.
- Fall back to the existing per-row ctid loop only when that raises `UniqueConstraintViolationException`.
- Most tables have no colliding row, so nearly every table then costs one subtransaction and one round trip, and a typical copy stays under 64 subtransactions in total (about 59 tables).

On iznik_perf the set-based form, rolled back, moved 610 rows in one index-scan update and left `subxact_count 1, overflowed f`. I tested that statement shape only, not the PHP change or its collision fallback.

A table that does collide still runs the per-row loop. If that ever matters, the loop could be replaced by an anti-join update against the table's unique keys, but that is not worth building for a one-off command. Operationally, run it off-peak with `--limit`.

**How the synthetic data could mislead**

- iznik_perf has no duplicate `tnpostid` at all (435,321 TrashNothing posts, 435,321 distinct), so the command would find zero sets there. I exercised the mechanism directly on one post's rows, not through the command.
- `rippling_reach_notified` is synthetic at 61 x tick rows per post (maximum 610). Real reach posts at a high tick could have more or fewer.
- Per-post likes here are capped at about 119. A real popular post could have thousands of `messages_likes` rows, which would make the per-row loop proportionally slower.
- Nothing else was running on the scratch server, so the cost that overflow imposes on other sessions was not measured. That part of the argument is reasoning, not a measurement.
- The set-based trial used `SET msgid = msgid`, so it never hit a unique collision. The fallback path of the fix was not exercised.

### 35. Updates that change nothing are free on MySQL and write a full new row version on PostgreSQL: 'mark all chats read' rewrites every roster row of the member on each press

- **Severity:** minor (claimed slow-at-scale). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/chat/chatroom.go:1999-2002 (also :1976-1978 for moderators, :1711-1712 for a single chat)
- **How often:** POST of the chat 'all seen' action: per member tap, and by clients on opening the chat list; the roster upsert runs on every chat open and poll. Moderators and busy members hold hundreds to thousands of roster rows.

**Evidence**

The mechanism is real, but the frequency and row counts in the claim are overstated.

CODE
- The statement is real: handleAllSeen in iznik-server-go/chat/chatroom.go:1958-2010, reached by POST /chatrooms with action 'AllSeen' (chatroom.go:784).
- It is not sent "on opening the chat list". The only callers of api.chat.allSeen() are chatStore.markAllRead / markAllReadMT (iznik-nuxt3/stores/chat.js:366-371), and those are called only from click handlers on the "mark all read" buttons (pages/chats/[[id]].vue:666, pages/chitchat/[[id]].vue:449, modtools/pages/chats/[[id]].vue:26).
- The roster heartbeat (chatroom.go:1689-1705) sets date = NOW(), so it changes the row on MySQL too. It is not a "free on MySQL" site.
- UPDATE users SET lastlocation runs once per post, which is low volume.
- The RowsAffected difference at message/bulkItem.go:360-363 looks real: I found no clientFoundRows in the Go code, so MySQL reports rows changed and PostgreSQL rows matched. That is a behaviour difference, not a performance one. I did not check whether the DSN sets it from the environment.

REPRODUCTION ON iznik_perf (BEGIN ... ROLLBACK, userid 89800, 209 roster rows)
- First run: "WAL: records=1643 fpi=800 bytes=6204899", n_tup_upd 209, n_tup_hot_upd 1. The full-page images come from the first touch after a checkpoint.
- Second run, where nothing changes: "Buffers: shared hit=5457 read=3 dirtied=7", "WAL: records=1258 fpi=3 bytes=123440", n_tup_upd 418, n_tup_hot_upd 1, execution 12 ms.
- So a no-change press costs about 6 WAL records and 590 bytes of WAL per row, plus one dead tuple per row. This matches the claim.
- The read side is fine: Index Scan on chat_roster_userid (209 rows), BitmapOr of chat_rooms_user1 / chat_rooms_user2, and an Index Only Scan Backward on chat_messages_chatmax per row with Heap Fetches: 0. MySQL does the same reads.

IS THE NON-HOT RESULT AN ARTEFACT OF ONE TRANSACTION?
- I suspected it was, because versions made by the same transaction cannot be pruned. It mostly is not.
- On a session-temporary copy of chat_roster (945k rows, same four indexes, dropped at the end) I ran the update five times with a COMMIT between each. HOT updates per press of 209 rows were 0, 0, 3, 13, 32.
- lastmsgseen is in no index, so HOT is possible, but the pages are packed at fillfactor 100 and HOT only climbs slowly.

WHY THE SEVERITY IS LOWER
- Roster rows per member in the scratch data: p50 3, p90 7, p99 14, p99.9 216, max 910.
- The typical press is therefore 3 to 14 rows, roughly 20 to 90 WAL records.
- The 209-row case is a 1-in-1000 member, and even that costs 12 ms and 120 KB of WAL per manual button press.
- Autovacuum absorbs dead tuples at that rate without trouble. This is extra write work on a rare action, not a slow query at scale.

**Fix**

No fix is needed for performance at this frequency. Two options if one is wanted:

- PostgreSQL-only, MySQL statements unchanged: add `CREATE TRIGGER ... BEFORE UPDATE ON chat_roster FOR EACH ROW EXECUTE FUNCTION suppress_redundant_updates_trigger()` to the PostgreSQL schema (generated by tools/pg-schema/convert.mjs or its config). Tested on the temp copy: the no-change press went from 209 rows updated to 0, and buffers from 4478 to 1431. The cost is one row comparison on every roster update, including the heartbeat, which always changes `date`.
- In the Go code, which does change MySQL's statement text: append `AND (lastmsgseen IS NULL OR lastmsgseen < (SELECT COALESCE(MAX(id),0) ...))`, the forward-only guard already used on the single-chat path at chatroom.go:1714-1716. Tested on iznik_perf in a rolled-back transaction: the first run updated 25 rows with 145 WAL records, and the second updated 0 rows with no WAL line and 1067 buffers. The result is the same on MySQL unless lastmsgseen is above the current maximum, in which case it is no longer pulled back.

Separately, the RowsAffected difference at message/bulkItem.go:360-363 should be treated as a correctness item: test for the row's existence instead of rows affected.

**How the synthetic data could mislead**

- Roster distribution is synthetic. The largest member here has 910 rows; real moderators and very busy members may hold several thousand, and the cost is linear in that.
- Only 25 of the 209 rows for user 89800 actually differed at rest. In production, a member who presses the button probably has only a few unread chats too, so most rows are no-change there as well. The proportion is the generator's, not measured.
- The table was freshly loaded and vacuumed with every page full, which is the worst case for HOT. A production table with churn and free space per page would see more HOT updates and fewer index writes.
- WAL figures depend on checkpoint timing: the 800 full-page images on the first run are a first-touch effect. Server settings are defaults (128 MB shared_buffers).
- The steady-state test ran on a temp table, which writes no WAL, so the per-row WAL figure comes only from the rolled-back run on the real table.

### 36. SELECT ... FOR UPDATE on a row that does not exist no longer stops two creators: duplicate User2Mod chat rooms on PostgreSQL

- **Severity:** minor (claimed worth-measuring). **Verdict:** confirmed-smaller. **Fix tried on the scratch database:** yes.
- **Where:** iznik-server-go/chat/chatroom.go:586-615 and iznik-batch/app/Models/ChatRoom.php:213-241
- **How often:** Each time a member contacts moderators or a moderator mails a member for the first time; the race needs two requests for the same pair at once (double submit, or the API and a background task together). Rare per pair, but silent, and there is already a chat:merge-duplicate-rooms command for the damage.

**Evidence**

The mechanism reproduces on iznik_perf, but the claim overstates where it applies.

**Reproduction (two psql sessions, both rolled back, scratch database left unchanged)**
- Session A: `BEGIN; SELECT id FROM chat_rooms WHERE user1 = 777001 AND groupid = 3 AND chattype = 'User2Mod' FOR UPDATE;` returned 0 rows, then `INSERT ... VALUES (900000001, 777001, 3, 'User2Mod', now()) RETURNING id`, then held open with `pg_sleep(4)`.
- Session B, started 1 second later with `lock_timeout = '2s'`: the same SELECT returned 0 rows in 1.1 ms and its INSERT returned id 900000002 in 1.8 ms. It did not wait and did not error.
- A then counted 1 row for the pair, so neither saw the other. Had both committed there would be two rooms.
- Afterwards `count(*) WHERE id >= 900000001` is 0.

**Plan of the locking read (nothing to lock, so nothing is locked)**
```
LockRows (actual rows=0.00 loops=1)  Buffers: shared hit=3
  -> Index Scan using chat_rooms_rooms on chat_rooms (rows=1 est, 0 actual)
       Index Cond: ((user1 = 777001) AND (chattype = 'User2Mod'::text))
       Filter: (groupid = 3)
```
There is no performance problem here: 3 buffers. `default_transaction_isolation` is `read committed`.

**MySQL side (reasoned, not run; I was not allowed to touch the MySQL databases)**
- The MySQL keys are `rooms (user1, chattype, latestmessage)`, `user1`, and `UNIQUE user1_2 (user1, user2, chattype)`.
- At InnoDB's default REPEATABLE READ, an empty locking read on `rooms` takes a gap lock. Two creators both get it, each INSERT's insert-intention lock waits on the other's gap lock, and one is rolled back with a deadlock error.
- I found no isolation override anywhere in the repository, so this assumes production runs the default.
- The MySQL outcome is therefore one room and one failed caller; the PostgreSQL outcome is two rooms and no error.

**Where the claim is wrong or overstated**
- **The Go site is test-only.** `GetOrCreateUser2ModChat` at `iznik-server-go/chat/chatroom.go:583` has no callers outside `iznik-server-go/test/chat_test.go` (lines 109, 150, 324).
- **The live Go path already races on MySQL.** `PutChatRoom` (`chatroom.go:398-450`) does an unlocked `SELECT ... LIMIT 1` and then an INSERT with `ON DUPLICATE KEY UPDATE`. That clause never fires on MySQL because `user2` is NULL. On PostgreSQL the translator drops it (`pgtranslate_more.go`: "No unique index is fully named by the INSERT ... drop the clause"). Both engines behave the same there, so it is not a port difference.
- **The Laravel site is live.** `ChatRoom::getOrCreateUser2Mod` (`iznik-batch/app/Models/ChatRoom.php:211`) uses `lockForUpdate()` and is called from:
  - `ProcessBackgroundTasksCommand.php:823` and `:972` (mod mail to a member), scheduled as `queue:background-tasks`, `everyMinute()`, `runInBackground()` in `routes/console.php:1284`;
  - `IncomingMailService.php:3951` (a member emailing the volunteers address).
- **The race needs two of those for the same member and community in the same few milliseconds**, for a pair with no room yet. That means two mail workers, or mail and a background task. It is rare.
- **The "there is already a command for the damage" point is wrong.** `chat:merge-duplicates` (`MergeDuplicateChatRoomsCommand.php`) only merges User2User rooms with user1 and user2 swapped. Nothing repairs duplicate User2Mod rooms.

I did not check the two other sites the claim mentions (`lockdown/lockdown.go:268` and `AutoSplitService.php:150`).

**Fix**

Both options leave every MySQL statement unchanged. Both were tried on iznik_perf and rolled back.

**Option A: advisory lock in a PostgreSQL-only branch (smallest, safe on existing data)**
- At the top of the transaction in `ChatRoom::getOrCreateUser2Mod`, and in the Go twin if it is ever used, run `SELECT pg_advisory_xact_lock(hashtextextended('u2m:' || user1 || ':' || groupid, 0))` when the engine is PostgreSQL.
- Tested with two sessions: the second waited 1999 ms on the lock, until the first ended, instead of proceeding.
- Under READ COMMITTED its SELECT then takes a fresh snapshot and would find the committed room. I could not show that last step directly because the first session had to roll back.
- This does not cover `PutChatRoom`, which races on both engines today.

**Option B: partial unique index in the PostgreSQL schema (stronger, needs clean data)**
- Add `CREATE UNIQUE INDEX ... ON chat_rooms (user1, groupid) WHERE chattype = 'User2Mod'` through `tools/pg-schema` (convert.mjs or config.json).
- Trial build in a rolled-back transaction: 144 ms, 3.8 MB.
- The locking read switched to it (`Index Scan using chat_rooms_user2mod_trial`, Index Cond `user1 = 777001 AND groupid = 3`).
- A second plain INSERT for the same pair failed with "duplicate key value violates unique constraint". That matches MySQL's outcome of one room and one failed caller, and it also closes the `PutChatRoom` race, as an error rather than a silent duplicate.
- `INSERT ... ON CONFLICT (user1, groupid) WHERE chattype = 'User2Mod' DO UPDATE SET latestmessage = excluded.latestmessage RETURNING id` returned the existing id, if an error-free upsert is wanted.
- A User2User insert for the same user1 was unaffected.
- The translator's catalog ignores partial indexes (`pgconn.go:109`, `ix.indpred IS NULL`), so no existing upsert would change its conflict target.
- The catch: existing duplicate User2Mod rooms must be merged before the index can be built on migrated production data.

**How the synthetic data could mislead**

Data volume does not affect this result: it is lock semantics, and it reproduces on any table size.

Two ways the synthetic data could still mislead:
- **The trial index built cleanly only because the scratch data has no duplicates.** It has 0 duplicate (user1, groupid) User2Mod pairs. Production very probably has some already, because the Go `PutChatRoom` path is unlocked on MySQL today. A partial unique index would fail to build on migrated data until those are merged.
- **How often the race fires cannot be measured here.** It depends on real concurrency between the mail workers and the background-task loop, which the scratch database does not model.

The MySQL deadlock behaviour is reasoned from InnoDB gap-lock rules at the default isolation level, not observed.

### 37. Support Tools community list: the 31-day approval counts no longer use (timestamp, type, subtype), and the Autoapproved count could not be measured

- **Severity:** none (claimed worth-measuring). **Verdict:** refuted. **Fix tried on the scratch database:** no.
- **Where:** iznik-server-go/group/group.go:572 and :580
- **How often:** Each load of the Support Tools community list by an admin or support user (GET /api/group list with support flag); three of these run in parallel per request. A few times a day.

**Evidence**

The claim's plans reproduce, but neither is a problem, and the suggested fix does not help.

CODE. iznik-server-go/group/group.go:571-584 is real and reached on PostgreSQL. It runs only in ListGroups when support=true and auth.IsAdminOrSupport (lines 516-520, 557), so "a few times a day" is right. The translator strips the hint (pgtranslate_more.go:56; pgtranslate_test.go:85), so PostgreSQL receives `SELECT COUNT(*) AS count, groupid FROM logs WHERE timestamp >= $1 AND type = $2 AND subtype = $3 GROUP BY groupid`. PostgreSQL has the same indexes as MySQL: logs_timestamp, logs_timestamp_2 (timestamp, type, subtype), logs_type (type, subtype).

APPROVED (2.34M rows in scratch, 98,063 in the window).
- Chosen plan: `Parallel Index Scan using logs_timestamp ... Filter: type/subtype ... Rows Removed by Filter: 149311` per worker, 11,286 buffers, est 23,769 x 3 vs actual 32,688 x 3. Serial, the same plan is 9,940 buffers.
- It never walks all Approved rows through logs_type, which was the 72-second MySQL trap.
- Forcing the three-column index (DROP INDEX logs_timestamp in a rolled-back transaction): `Index Scan using logs_timestamp_2 ... Buffers: shared hit=5967 read=4532` = 10,499 buffers, more than the 9,940 of the chosen plan. The claim's "fetched a fifth of the rows" is true of rows but not pages: 18% of rows at about 70 rows a page touches nearly every heap page of the month anyway, and logs_timestamp_2 is twice the size (553 MB vs 279 MB).
- For Rejected (5,298 in the window) the same forced plan read 6,768 buffers against 11,395, a small gain on a query that is already tens of milliseconds.

GENERIC PLAN. pgx caches prepared statements, so I checked `plan_cache_mode=force_generic_plan`: still `Parallel Index Scan using logs_timestamp ... Index Cond: (timestamp >= $1)`, cost 169,007. A generic plan cannot fall into the logs_type trap either.

AUTOAPPROVED. With zero rows the plan is `Index Scan using logs_type` (est 1 row, 3 buffers). I then inserted Autoapproved rows spread over the ten years, inside BEGIN ... ROLLBACK:
- 2,380 rows, stale stats (est 1): `Index Scan using logs_type ... Rows Removed by Filter: 2289`, 41 buffers.
- 2,380 rows, after ANALYZE: same plan, 38 buffers.
- 23,500 rows, stats still saying 2,380: logs_type index scan, `Rows Removed by Filter: 22537`, 337 buffers, 6.5 ms.
- 23,500 rows, after ANALYZE: `BitmapAnd` of `Bitmap Index Scan on logs_type (rows=23500)` and `Bitmap Index Scan on logs_timestamp (rows=546958)`, `Heap Blocks: exact=15`, 1,531 buffers.

Existing subtypes with natural heap interleaving show the same progression:
- Hold (38k total): BitmapAnd of logs_type and logs_timestamp, 3,002 buffers.
- Rejected (129k), Edit (390k), Autoreposted (520k): all `Parallel Index Scan using logs_timestamp`, about 11,380 buffers.

So logs_type is used only when the planner expects few rows for the subtype. Past roughly 100k rows (scratch scale) it drops logs_type altogether for the date index. The cost never exceeds the date-range plan (about 11k buffers here, about 35k pages at production's 3.28x). Rolled back cleanly: 0 Autoapproved rows and 9 indexes on logs afterwards.

**Fix**

None needed; MySQL statements stay exactly as they are and the translator keeps stripping the hint.

Do not drop logs_timestamp on PostgreSQL as the claim suggests. Tried in a rolled-back transaction, it made the Approved count read slightly more buffers through logs_timestamp_2 (10,499 vs 9,940), and it would make the PostgreSQL schema diverge from MySQL for no gain.

The only follow-up worth having is a post-cutover check that logs is being auto-analyzed, so Autoapproved appears in pg_stats for subtype.

**How the synthetic data could mislead**

- **No real Autoapproved volume.** The scratch data has no Autoapproved rows and I had no production count. My trial rows were appended at the end of the heap, so they are physically clustered and the logs_type path looks cheaper in buffers than interleaved rows would. The Hold, Rejected, Edit and Autoreposted runs do not have that flaw and show the same plan choices.
- **Heap order.** The scratch heap is perfectly time-ordered (correlation 1.0). Production PostgreSQL would be close, since logs is append-only by id, but purge deletes followed by space reuse could scatter recent rows into old pages and raise heap reads for the date-range scan.
- **Stale statistics.** The one way to get a long logs_type walk is stats that say Autoapproved is rare when millions of rows exist. That needs auto-analyze not to have run across years of inserts, which is not plausible on a table that grows by about 1.8M rows a month at production scale.
- **Server settings.** shared_buffers is 128 MB here, so hit/read splits are not meaningful. I compared buffer totals only.

### 38. Email statistics by type and daily series: the sent_at range is used unprompted, but the join to users becomes a hash join over the whole users table

- **Severity:** none (claimed minor). **Verdict:** refuted. **Fix tried on the scratch database:** no.
- **Where:** iznik-server-go/emailtracking/emailtracking.go:188 (statsByTypeQuery) and :990 (time series)
- **How often:** Admin email statistics pages in ModTools/Support (GET /api/emailtracking/stats, by type and time series): occasional, by a handful of people.

**Evidence**

The plan the claim describes reproduces exactly, but it is not a performance problem: it costs about 7x fewer buffers than the nested loop MySQL runs today.

CODE. The statements are real and reached on PostgreSQL. iznik-server-go/emailtracking/emailtracking.go:181 (statsByTypeQuery, used by StatsByType at :1092) and :990 (TimeSeries) both use "email_tracking FORCE INDEX (sent_at)" plus "LEFT JOIN users ON email_tracking.userid = users.id" with "users.tnuserid IS NULL AND sent_at BETWEEN ? AND ?". The translator drops the hint (database/pgtranslate_test.go:85, "force index" case). Routes are GET /modtools/email/stats/bytype and /modtools/email/stats/timeseries (router/routes.go:1529, :1515); both handlers require auth.IsAdminOrSupport. The only frontend caller is iznik-nuxt3/api/EmailTrackingAPI.js. No scheduler entry. So this is occasional and Support/Admin only, as the claim says.

Indexes are the same on both engines: single-column sent_at, userid, email_type; no composite.

PLANS (iznik_perf: email_tracking 2.3M rows over one year, users 1.0M rows / 52,670 pages / 413 MB).

1. 30-day range (198,132 tracking rows), default settings:
"Parallel Hash Left Join ... Hash Cond: (email_tracking.userid = users.id)"
"-> Parallel Index Scan using email_tracking_sent_at ... rows=82097 (actual rows=66044.00 loops=3)"
"-> Parallel Hash ... Buckets: 262144 Batches: 8 Memory Usage: 7104kB / Buffers: shared hit=1 read=52879, temp written=3160"
"-> Parallel Seq Scan on users (rows=418299, actual 333333.33 loops=3)"
Total: shared hit=58741 read=61155 (about 120k buffers), temp read=4248 written=4324, 288 ms. Estimates are accurate (70,600 vs 56,823 per worker).

2. Same query forced into the MySQL shape (enable_hashjoin=off, enable_mergejoin=off):
"Nested Loop Left Join ... -> Index Scan using users_id on users (actual rows=0.98 loops=198132) Buffers: shared hit=652646 read=124032"
Total: shared hit=710469 read=132308 (about 843k buffers), 434 ms. That is 7x the buffers of the plan PostgreSQL chose, and more physical reads (132k vs 61k) even with a 128 MB buffer pool.

3. One-day range (6,307 rows): the planner switches by itself to "Nested Loop Left Join ... Index Scan using users_id on users (loops=6307)", 26k buffers, 27 ms. A 7-day range (40k rows) already uses the hash join (113k buffers, 261 ms). So the crossover sits between roughly 6k and 40k tracking rows and is chosen correctly on both sides.

4. work_mem=64MB, no parallelism: "Hash ... Buckets: 1048576 Batches: 1 Memory Usage: 48371kB", no temp files, 118k buffers.

5. No date range (whole table, the un-hinted form): Seq Scan on email_tracking + Hash Left Join on users, 128k buffers, 1.5 s. MySQL here would do 2.3M (7.4M in production) primary-key probes.

CONCLUSION. Every factual statement in the claim holds (sent_at index used unprompted; users read whole; 8 batches with temp spill at 4 MB work_mem). What does not hold is that it matters: the full users scan is a bounded cost (about 172k pages, roughly 1.3 GB sequential, at 3.26M users) that replaces about 640k random primary-key probes on a production 30-day range, and the planner falls back to probes when the range is small. The claim itself rated this minor with no fix; I rate it none.

**Fix**

None needed, and no change to any MySQL statement.

Optional tuning only: a production work_mem of about 64 MB keeps the users hash in one batch and removes the temp files (seen in plan 4: Batches: 1, 48 MB). That is a server setting, not a code or schema change.

**How the synthetic data could mislead**

Three things could shift the crossover, none of them enough to make this a problem.

- Users row width: synthetic users rows are about 413 bytes (413 MB per 1M). If production rows are much wider (large settings JSON kept inline), the full scan costs proportionally more and the planner will keep the nested loop for longer ranges. That is the planner adapting, not a regression.
- Recipient skew: synthetic email_tracking.userid is spread almost uniformly (correlation 0.01, about 357k distinct users). Real mail goes to a smaller active set, so a production nested loop would hit warmer pages than plan 2 suggests and the buffer advantage of the hash join would be smaller than 7x.
- Volume: email_tracking here covers exactly one year at an even rate. If production's 7.4M rows span a shorter retention window, a 30-day range holds more rows, which favours the hash join further.

Server settings are defaults (shared_buffers 128MB, work_mem 4MB), so the hit/read split and the 8-batch spill reflect that, not production.

### 39. groups:update-stats backup-owner/moderator counts: the IN (subquery) arm reads the whole recent approvals index once per group and role, 1,000 times a night

- **Severity:** none (claimed worth-measuring). **Verdict:** refuted. **Fix tried on the scratch database:** yes.
- **Where:** iznik-batch/app/Services/GroupStatsService.php:246 (and :260)
- **How often:** groups:update-stats, nightly (routes/console.php:1782): 2 statements x 500 groups.

**Evidence**

**Verdict:** the plan node the claim cites is real for the biggest groups, but it is not chosen for every group, and the nightly cost is about 4 s, not 100 s.

**Code.** The statement is real and reached on PostgreSQL: `iznik-batch/app/Services/GroupStatsService.php` lines 246-272, in `updateActiveOwnerModCounts()`. It is plain query-builder SQL with no expression class. It runs once for Owner and once for Moderator per group, from `groups:update-stats`, scheduled `dailyAt('02:00')` with `withoutOverlapping(360)` and `runInBackground()` (`routes/console.php:1782`). So 1,000 statements a night in a background job is correct. The window is `ACTIVE_DAYS = 30`.

**SQL run** (the builder's rendering, written out by hand):
`select count(distinct "userid") as aggregate from "memberships" where "memberships"."groupid" = $1 and "memberships"."role" in ($2) and "memberships"."userid" not in (select "approvedby" from "messages_groups" where "groupid" = $1 and "arrival" > $3 and "approvedby" is not null) and "memberships"."userid" in (select "approvedby" from "messages_groups" where "groupid" != $1 and "arrival" > $3 and "approvedby" is not null)`

**Group 1, Moderator (121 moderators, the second-largest count), 30 days.** This reproduces the claim's plan:
- `Merge Semi Join ... rows=91`
- inner: `Index Only Scan using messages_groups_lastapproved ... Index Cond: ((approvedby IS NOT NULL) AND (arrival > ...)) Filter: (groupid <> 1) rows=7424 Heap Fetches: 0 Buffers: shared hit=4 read=4585`
- That is the non-null part of the index: 4,589 of its 15,874 pages, about 36 MB, with no heap access.
- Cold it took 81 ms. Run again it took 29.6 ms (`shared hit=3972 read=617`), and with a 90-day window 32 ms with `shared hit=4589` on that scan.

**Small groups do not get that plan.** Most groups have about 11 moderators (5,428 Moderator rows over 500 groups), not 110.
- Group 400, Moderator: `Nested Loop Semi Join`, outer 8 rows, inner `Bitmap Heap Scan on messages_groups ... loops=8` via `messages_groups_approvedby`, total `Buffers: shared hit=746 read=1673`, 12 ms cold.
- Group 400, Owner: the same shape with `loops=1`, 338 buffers, 1.4 ms.
- So "reads the whole recent approvals index once per group and role, 1,000 times" is not what happens.

**Whole nightly run.** A read-only DO loop executed the statement for all 500 groups and both roles (1,000 executions, 30-day window): `Time: 4317.806 ms`. That is wall-clock on a shared host, but it is 25 times below the claimed 100 s. The claim multiplied a cold 99.9 ms from the largest group by 1,000.

**The MySQL-style plan is worse on PostgreSQL.** With `SET enable_mergejoin = off` for group 1 (90-day window): `Nested Loop Semi Join`, inner `Bitmap Heap Scan ... loops=93, Heap Blocks: exact=23795`, total `Buffers: shared hit=15199 read=21044`, 133 ms. The merge join the planner picked reads 15,131 buffers and no heap. The planner's choice is the cheaper one.

**MySQL today (inferred, not run).** It either probes `lastapproved (approvedby, groupid, arrival)` per moderator, a range over each moderator's whole approval history (about 270 entries each here), or materialises the subquery through the `arrival` index, which lacks `approvedby` and needs a row lookup for each recent row. Neither is clearly cheaper than a cached 36 MB index-only scan.

**Production scale.** The index would be about 3.3 times larger, so about 15k pages (120 MB) for the large-group plan, roughly 100 ms each, and only for the groups with enough moderators to tip the planner. Even if all 1,000 statements took it, that is under two minutes in a 02:00 job with a 360-minute overlap guard. The same method also runs two `users` join counts per group, and an earlier loop runs a `logs` max per group; this arm does not stand out among them.

**Fix**

None needed for the PostgreSQL port.

Two optional changes, if anyone wants to tidy it later:

- **Fetch the pairs once (the claim's suggestion).** `select approvedby, groupid from messages_groups where arrival > ? and approvedby is not null group by 1, 2` returned 7,704 rows from one index-only scan (4,589 buffers, 61 ms cold) on iznik_perf. Using it would change the MySQL statements, so it is a general refactor, not a port fix, and it saves about 4 s a night here.
- **PostgreSQL-only partial index.** `messages_groups (arrival, approvedby, groupid) WHERE approvedby IS NOT NULL` leaves the MySQL statements untouched. Tried in a rolled-back transaction: group 1 Moderator became a `Hash Right Semi Join` over an `Index Only Scan using sk_trial` with 217 buffers and 2.2 ms (from 5,230 buffers); group 400 became 113 buffers and 1.4 ms. I would not add it: it puts an extra index on a write-hot table to save seconds in a nightly job.

**How the synthetic data could mislead**

- **Approver share:** `approvedby` is non-null on 29% of synthetic `messages_groups` rows (930,777 of 3,202,500). The merge plan's cost is proportional to that share, and the real share is unknown.
- **Approver spread:** the synthetic data has 3,300 approvers, each with about 280 approvals spread evenly over ten years and across groups. Real approvals concentrate on a few very active moderators with tens of thousands of rows each. That would make the nested-loop plan (the one small groups get, a heap bitmap scan per moderator through `messages_groups_approvedby`) more expensive than seen here.
- **Moderator counts:** the largest groups here have 97 to 179 moderators. Real groups rarely have that many, so in production the merge plan would probably be chosen less often.
- **Cache:** `shared_buffers` is 128 MB. The 36 MB index portion stayed cached between statements here; a 120 MB portion in production needs realistic `shared_buffers` to behave the same.

## Reported but not put to a second reviewer

- (translator-rewrites, claimed worth-measuring) The mygroups feed's estimated cost crosses jit_above_cost, so each call compiles about 100 JIT functions (43 to 45 ms) and nothing is reused — iznik-server-go/message/groups.go:83-168 (the derived-table feed statement); no jit setting anywhere in docker-compose.yml or scripts/setup-pg-database.sh
- (laravel-dialect, claimed worth-measuring) tn:merge-crossposts moves each referencing row in its own savepoint inside one transaction: more than 64 subtransactions overflows the subxid cache — iznik-batch/app/Console/Commands/Dedup/TnMergeCrosspostsCommand.php:184 (Sql::attempt at app/Database/Sql.php:205; outer transaction at :200)
- (writes-mvcc-locking, claimed worth-measuring) tn:merge-crossposts moves rows one savepoint at a time on PostgreSQL, passing the 64-subtransaction limit for popular posts — iznik-batch/app/Console/Commands/Dedup/TnMergeCrosspostsCommand.php:175-193 (called inside the transaction at :200)

## Checked and found fine

**index-hints**

- Every hint site found by the grep was read: 14 Go sites in 7 files and 6 batch sites in 5 files; all equivalent indexes exist in the PostgreSQL schema under table-prefixed names (logs_timestamp_2, logs_user, logs_type, messages_groups_arrival, email_tracking_sent_at, newsfeed_position as GiST, newsfeed_timestamp, memberships_memberships_groupid_collection_emailfrequency, users_deleted).
- user/user.go:4115 logs FORCE INDEX (user), member's membership history: PostgreSQL used Index Scan on logs_user then a primary-key lookup into groups, 16 buffers; it did not drive from groups as MySQL did.
- changes/changes.go:142 messages_groups FORCE INDEX (arrival): bitmap scan on messages_groups_arrival for a 90-day window (76,637 rows, 1,554 buffers) and a plain index scan for a one-hour window; the single-column collection index was not chosen.
- iznik-batch MessageSpatialService.php:167 Sql::forceIndex('arrival') in representativeMembershipSource (every five minutes): bitmap scan on messages_groups_arrival, 26,199 rows, in-memory sort for the window function.
- emailtracking.go:1850 addLiveDigestPositions and iznik-batch DigestPositionsRollupService.php:60 forceIndex('sent_at'), one-day windows: Index Scan on email_tracking_sent_at, 225 buffers, with the JSON length test as a filter.
- newsfeed.go:470-529 FORCE INDEX (position) arms: the planner combined the GiST index with btree bitmaps (BitmapAnd), 216 buffers; the table is 162k rows in production so no plan here is expensive.
- newsfeed.go:585-621 FORCE INDEX (timestamp) arms: backward scan of newsfeed_pinned (pinned, timestamp) satisfies ORDER BY pinned DESC, timestamp DESC and stops at the limit, 422 buffers.
- iznik-batch NotificationExhortService.php:56 ignoreIndex('deleted'): PostgreSQL did not use users_deleted; it scanned users_added (added, lastaccess) with both conditions in the index, 3,789 buffers, which is better than the full table scan MySQL was steered to.
- iznik-batch MembershipOriginBackfillService.php:159 forceIndex('type'): PostgreSQL walked logs_pkey in id order filtering type and subtype (Group/Joined is 11.7% of logs), 977 buffers per 5,000-row chunk; one pass over logs for the whole backfill, which is a one-off command.
- iznik-batch MembershipOriginBackfillService.php:502 forceIndex('timestamp_2'): a two-hour window used logs_timestamp with type and subtype as a filter and hashed against the member list, 11 buffers.
- iznik-batch ConfirmReplyPostSource.php:150 StraightJoin (experiment sizing check, run when an experiment starts): messages_eee is empty in the scratch database, so I loaded 300,000 rows inside a transaction, analysed, explained and rolled back (table is empty again and autovacuum has reclaimed it). PostgreSQL reversed the forced order and drove from chat_messages_date for the intake week, 45k buffers; a good plan on this data.
- message/message_list.go:309 candidate query: listed under the first finding; no ordering, so no primary-key bet, and its cost is the per-candidate work the comment already measures on MySQL.

**functions-on-columns**

- json_extract(text|jsonb, text), json_unquote, datediff, timestampdiff, unix_timestamp, locate, year/month/day/hour and the iznik_json_* operator functions are LANGUAGE sql and do inline: EXPLAIN shows jsonb_path_query_first(NULLIF(settings,'')::jsonb, ...) and the CASE body in the Filter, not a function call.
- JSON_EXTRACT(g.settings, '$.widerchatreview') = 1 (session.go:1479, groupWork.go:523, chatmessage.go:1785): inlined, evaluated only on groups rows reached by primary key; the wider-review count ran in 16 ms with 4,688 buffers, driven by chat_messages_reviewrequired.
- DATE(messages.arrival) = CURDATE() in microvolunteering.go:341/385: not sargable on either engine; PostgreSQL drives from messages_groups_groupid (groupid, collection, deleted) and applies date(arrival) as a filter on 15 rows, 1.7 ms.
- JSON_LENGTH(e.metadata, '$.post_msgids') > 0 in emailtracking.go:1840: FORCE INDEX is dropped, but PostgreSQL still chose email_tracking_sent_at for a 7-day window; json_length applied as a filter, 90 ms.
- Implicit smallint to boolean cast and the boolean = integer operator do not steal ordinary comparisons: bouncing = 0, reviewrequired = 1 and a prepared reviewrequired = $1 all resolve to int2 operators and use the index.
- The custom jsonb = / <> / < integer, bigint, numeric and text operators have no RESTRICT, JOIN or COMMUTATOR, but at every site I found they are applied to groups or ux_* rows after an indexed lookup, so the default selectivity did not change a plan.
- DATEDIFF / TIMESTAMPDIFF on chat_messages.date, memberships_history.added and microactions.timestamp (chatroom.go:1604, message.go:725, user.go:445/1561/4007, microvolunteering.go:298): each is beside an indexed equality (chatid, refmsgid, expectee, userid), and they were not sargable on MySQL either.
- Laravel TimestampDiff, DateDiff, Year, StartOfHour, YearMonth, JsonExtract, JsonValid and JsonLength render native PostgreSQL expressions (EXTRACT, date_trunc, to_char, #>, IS JSON); they do not call the plpgsql compatibility functions.
- haversine() (plpgsql) is only applied to groups (500 rows): location.go:130/189, Location.php:167/181, PostSyncer.php:583, ReengageContentService.php:281. MySQL's version is a stored function too.
- damlevlim() in domain.go:41 runs over domains_common only, after an exact lookup misses.
- Declared volatility: crc32, date_format, haversine and json_valid are honestly IMMUTABLE; damlevlim is STABLE; unix_timestamp() is STABLE. date(timestamptz) is the built-in and is STABLE. I found no IMMUTABLE function that reads now() or a session setting.
- Parsing users.settings as text costs about 3 microseconds per json_extract (200k rows with two extracts: 1,330 ms against 115 ms for a plain length() filter). MySQL parses the same text column per call, so this is parity rather than a regression.
- LOCATE('"active"', memberships.settings) in chatroom.go:883/919/2096/2124 and chatmessage.go:1496: inlines to strpos and is applied to one member's memberships.
- Location::getByName (canon::text LIKE with no wildcard): the planner derives Index Cond (canon)::text = 'ca12aa' on locations_canon, 4 buffers.
- SpamCheckService::checkReferToSpammer (users_emails.email LIKE $email joined to spam_users): can drive from spam_users by userid, so no full scan, though it is now case-sensitive (see the LIKE finding).

**translator-rewrites**

- Savepoints and per-statement catalogue lookups: pgconn.go adds no savepoint; pg_catalog is read twice in total per connector, on first use (load()), then held in memory. A new unique index needs an API restart to be seen.
- Cached path per execution: one sync.Map lookup on the SQL text, fillNulls over the listed parameters, and nothing else unless the text contains concat/jsonb_build_/json_set.
- LAST_INSERT_ID: an INSERT into a table with an id column is sent once with RETURNING id through QueryContext (pgconn.go:240). Same single round trip, no extra query.
- INSERT IGNORE becomes ON CONFLICT DO NOTHING with no target, so no catalogue lookup and no extra read (24 sites, including chat_roster and users_active).
- REPLACE INTO becomes INSERT ... ON CONFLICT (key) DO UPDATE SET col = EXCLUDED.col, not delete-then-insert. Sites are few and cold (address/address.go:44, spammers/spammers.go:202, message/message.go:6451, chat/chatmessage.go:2322).
- ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id) becomes DO UPDATE SET id = t.id, which writes a row version where MySQL writes nothing. The sites are get-or-create for chat_rooms and items behind an existence check or at post-submit volume (chat/chatroom.go:506 and :647, message/message.go:4717, :5873 and :7008): low volume.
- UPDATE with ORDER BY and LIMIT becoming a ctid subquery: one real site (message/message_submit.go:213). Plan is Tid Scan over an index-driven LIMIT 1 subquery, 6 buffers.
- UPDATE IGNORE becoming a NOT EXISTS guard: only in account merge (user/merge.go:64 and :103 and the reference tables at user/user.go:3646); the guard probes the unique index.
- GROUP BY rewrite on the chat list (chat/chatroom.go:1104: 15 columns wrapped in ANY_VALUE, ORDER BY ANY_VALUE(t.latestmessage)): index scans on chat_rooms_rooms and chat_rooms_rooms2, a sort of a handful of rows, 57 buffers for a member with 3 chats. The largest synthetic room has 264 messages, so busy rooms are not represented.
- DISTINCT with ORDER BY becoming GROUP BY with ORDER BY ANY_VALUE(...): checked the rule's output. The real sites are small or filtered sets (message/message_list.go:225 Edit queue, story, communityevent, volunteering lists); none EXPLAINed.
- logs FORCE INDEX (timestamp_2) (group/group.go:572 and :580): without the hint PostgreSQL used logs_timestamp for the 31-day window, 11.4k buffers; the generic plan is the same shape.
- logs FORCE INDEX (user) (user/user.go:4115): without the hint PostgreSQL drove from logs_user, 16 buffers.
- messages_groups FORCE INDEX (arrival) (changes/changes.go:142): a custom plan for a 90-day window used a bitmap scan on the arrival index, 1,554 buffers; stayed custom over six one-day executions.
- IN list of 10,000 ids (message/bounds.go:213, whenVisibleChunk): bitmap scan on messages_pkey plus 10,000 probes of messages_groups_msgid, 42.9k buffers, planning 25 ms. Under PostgreSQL's 65,535-parameter limit.
- fillNulls and insertNullCols: computed once per text; per execution it is a loop over the listed parameters with one reflect check each. Single-row INSERTs only.
- Index-hint stripping when a column is named `force`, `use` or `ignore`: the rule needs INDEX or KEY and a parenthesis after the word, so there is no accidental removal.

**schema-conversion**

- Foreign keys without a supporting index: none. All 308 foreign keys on iznik_perf have an index led by the referencing column (mysqldump emits MySQL's implicit FK indexes as explicit KEYs and the converter carries them over).
- ON UPDATE CURRENT_TIMESTAMP triggers (62 tables, one plpgsql row trigger plus two statement triggers each): measured, not a problem. 100,384-row UPDATE of messages_likes: row trigger 181.8 ms total (1.8 microseconds a row) of a 2,104 ms statement, the two statement triggers 0.6 ms together; with triggers disabled in a rolled-back transaction the statement took 2,070 ms. locations (19 columns including two geometries in the ROW comparison): 527 ms per 100k rows. Mark-seen upsert of 29 rows: 0.14 ms row trigger plus 0.14 ms statement triggers.
- Trigger tables do not include the widest rows: rippling_reach, messages, users, chat_messages and chat_rooms have no ON UPDATE column, so the whole-row IS DISTINCT FROM comparison never detoasts the 160 KB reach blobs.
- test-mode.sql: adds column defaults only (no triggers, no indexes, no functions); nothing in it would run in production and it has no planner effect.
- FULLTEXT indexes: none in the MySQL schema. Functional or descending indexes: none in the MySQL schema.
- SPATIAL KEY to GiST: 11 spatial keys in MySQL, 11 GiST indexes in the baseline, all valid.
- Index count parity: 1,098 KEY/PRIMARY KEY lines in the MySQL dump against 1,110 indexes on PostgreSQL (the difference is unique constraints); no MySQL index is missing.
- Generated columns: six, all STORED on PostgreSQL (mail_suppressions.active_value, messages.needsprocessing-style flag, messages_outcomes.feedback, rippling_reach.has_overflow / has_max_reach, users_emails.md5hash). All are cheap scalar expressions; none is recomputed on read.
- Prefix-length keys: only messages_history.subject and .prunedsubject (768) lose their prefix. Columns are varchar(1024); a subject over about 2,700 bytes would be refused by the btree row limit, but the table holds 56k rows and is not on a hot path. Noted, not reported.
- Parameter typing: pgx runs in its default cached-prepared-statement mode, so parameter types are inferred from the column. numeric(10,6) lat/lng columns, smallint flags and citext equality all keep their indexes with bound values (citext equality on locations.name: Index Scan, 7 buffers).
- ENUM to text plus CHECK, and char_length CHECKs on citext: evaluated per insert as a short ANY-array comparison; no measurable cost in the upsert probes. Every index on a former enum column is a normal btree on short text.
- Skewed enum-like columns: single-column MCV lists give correct estimates for messages_groups.collection ('Pending' 190 rows) and the chat_messages flags; no pair of correlated columns was found that needs CREATE STATISTICS. No extended statistics exist (count 0).
- JSON in text columns (users.settings, groups.settings via JSON_EXTRACT): the Go sites apply it per row to small result sets or to the 500-row groups table; not a scan predicate on a large table.
- Exact duplicate indexes carried over from MySQL (same table, same columns): chat_messages refchatid/refchatid_2, users pkey/id, messages_history, mod_stdmsgs, jobs job_reference, groups, spam_countries, spam_whitelist_ips, spam_whitelist_subjects, mod_configs, supporters, locations_dodgy (two pairs), users_stories_requested, logs_sql, mod_bulkops, os_county_electoral_division_region, sessions. Folded into finding 2.
- timestamp to timestamptz and datetime to timestamp: comparisons between the two are cross-type operators in the same btree family and still use indexes; no site found where a cast lands on the indexed side.

**laravel-dialect**

- Reach fingerprint (the relayed MySQL check): config.reach_partition_fp = 1259147727407222857 is below 2^63, so ReachService::fingerprintForDb passes it through unchanged and liveLabelsSql compares reach_labels_next_fp with a plain bigint literal; no per-row cast and no wrap-around on the current value. A future fingerprint above 2^63 goes through the bcsub path, which I read but did not exercise.
- UnifiedDigestService.php:1188 reach recipient query (per expanding post): rebuilt and run for a 6,944-member group; 203 ms, 61k buffers, driven by memberships (groupid, collection, emailfrequency), ST_Contains on outer_bound as a join filter at about 30 microseconds a row. MySQL comment says about 0.7 s a post.
- Daily digest phase 1 and push:daily-posts keyset chunks (order by users.id limit N): incremental users_id index walk, 114 to 150 ms a chunk; the crc cost there is once per row per run.
- MessageSpatialService.php:167 FORCE INDEX (arrival), dropped on PostgreSQL: planner picks messages_groups_arrival unaided (Bitmap Index Scan, 27,565 rows, WindowAgg run condition); whole five-minute reconcile 509 ms against 4.69 s quoted for MySQL. My latest-outcome join was an approximation of joinLatestOutcome.
- ChatNotificationService.php:180 getUnmailedMessages (runs continuously): BitmapOr of chat_messages_date and pkey, 10.9 ms, 10k buffers.
- IncomingMailService.php:2046 trackEmailReply (whereJsonContains on metadata->chat_id with order by and limit, rendered as ctid IN (subquery)): email_tracking_userid index, 0.8 ms; metadata is jsonb so the cast is free.
- DetectRelatedAccountsCommand.php:104 Regexp (~*) with chunkById over chat_messages: planner uses chat_messages_date and sorts, 34 ms for 7 days; it did not fall into the pkey-walk-with-LIMIT trap. Synthetic text never matches the regex, so the regex CPU cost is not representative.
- DigestPositionsRollupService.php:60 forceIndex('sent_at') and Regexp on link_position: read only; one-day sent_at range, email_tracking_sent_at exists.
- AuthorityStatsService.php:510 temporary table `pc`: created per report (not per row), GiST locations_spatial_geometry used for the fill, join back through messages_locationid. The synthetic authority I tried contained no locations, so the later joins ran on 0 rows.
- TableSwap (WhatJobsService.php:1621 and 1772, nightly): two ALTER TABLE RENAME in one transaction; ACCESS EXCLUSIVE queueing is comparable to MySQL's metadata lock. No foreign key references jobs. The jobs table is empty in the scratch database so the load was not measured.
- ReplaceInto (StatsGenerationService.php:894, 935, 956): BEGIN, DELETE by (date, groupid, type), INSERT, COMMIT per row; about 4 round trips instead of 1 for roughly 500 groups x 17 types nightly. Extra chatter and dead tuples only.
- UpsertReturningId (ItemService.php:59): ON CONFLICT (name) DO UPDATE SET name = ? writes a new tuple version and burns an identity value on every existing item, where MySQL's no-op update writes nothing. Per ingested post; minor.
- Sql::attempt at ReachBoundsService.php:91: one savepoint per call, not in a loop.
- JsonArrayHasValue (ExpandService.php:2169) and JsonSettingEnabled (CommunityNewsEmailService.php:320): evaluated against groups (500 rows) and a single rippling_reach row; rejected_groups is jsonb.
- users.settings JSON predicates (whereJsonDoesntContainKey and settings->simplemail in UnifiedDigestService.php:1773, ReengageService.php:114, Models/User.php:874): cast to jsonb per row costs about 3.4 microseconds a row on 1M users; MySQL parses the same TEXT per row, so no relative regression.
- TimestampDiff sites (ChatChaseupExpectedService.php:60, UserManagementService.php:669 and 678): column-to-column and non-sargable on both engines; each is bounded by a date range on the other table.
- DateSub and Sql::nowMinus: render as statement_timestamp() - (CAST(n AS numeric) * INTERVAL '1 unit'), a stable expression usable as an index bound (seen as an Index Cond on messages_groups arrival in the expiry plan).
- PurgeService chunked deletes (for example :749, DELETE ... LIMIT 1000 rendered as ctid IN (subquery limit 1000)): first chunk 336 buffers via logs_type; after 60 chunks inside one transaction, 20,131 buffers because dead index entries are re-walked. That is the worst case (nothing can be pruned); with autocommit and no old snapshot the index kill bits bound it. MySQL has the analogous purge-lag behaviour, so not reported as a finding.
- rippling_reach reads in ExpandService (:1650, :2864): narrow column lists and `reach_labels IS NOT NULL` do not detoast the 96 KB blobs; there is no ON UPDATE trigger on rippling_reach.
- Date binding format with microseconds (JsonTextPostgresGrammar::getDateFormat): bound as untyped text and resolved to the column type; index conditions on timestamptz columns appear normally in every plan above.
- PostgresBinaryConnection: mb_check_encoding per string binding and a per-column resource check per fetched row; PHP-side only, no SQL effect.

**go-hot-paths**

- Chat list, first statement (chat/chatroom.go:1104, UNION of per-type branches then GROUP BY t.id): each branch uses chat_rooms (user1|user2, chattype, latestmessage). The ModTools User2Mod branch (user1 = ? OR EXISTS memberships) becomes a hashed SubPlan over a skip scan of chat_rooms_room3, 2,161 buffers; MySQL has the same dependent-subquery shape.
- Chat list detail (chat/chatroom.go:1196-1225 with ChatRoomListFrom :2247): 14 rooms, all keyed nested loops, 862 buffers. The latest-message subquery (ORDER BY id DESC LIMIT 1) uses chat_messages_chatmax backwards, not the primary key. The users_images subquery uses the userid index plus a sort.
- ModTools chat review count (session/session.go:1424-1455): driven from chat_messages_reviewrequired (399 rows), memberships probed by index-only scans, 5,517 buffers. The estimate for reviewrequired = 1 was 4 rows against 399 actual, still the right plan.
- Chat review queue (chat/chatmessage.go:1652, GROUP BY cm.id ORDER BY cm.id ASC LIMIT): driven from the reviewrequired index and sorted, not a forward walk of the primary key, 5,779 buffers.
- Nearby browse feed with 800 spatial ids (isochrone/message.go:190-217 and :362-407): keyed nested loops, 24,453 buffers for 794 posts. The JSON_EXTRACT wrappers in AuthorReachCapWhere are inlined SQL functions (functions.sql:227-238) and run as a join filter; the row estimate collapses to 1 but that does not change the plan.
- Badge count by id list (isochrone/reachspatial.go fromIDsWhere, OR of two IN lists with EXISTS): messages_spatial is only 12k rows (58k in production), so any plan is bounded. Read, not run.
- ModTools pending and spam counts (session/session.go:1190-1255): custom plan 139 buffers. A forced generic plan estimates collection = $n at 3,168 rows but still uses the (groupid, collection, deleted, arrival) index, and its cost (12,696) is far above the custom cost (46), so PostgreSQL will not switch to it. Pending members count is an index-only scan either way.
- Community message list (group/groupMessages.go:22): right index in both custom and forced-generic plans, about 12k buffers for 857 rows in the largest community. The only oddity is the parallel worker, reported under the settings finding.
- Changes endpoint approved arm (changes/changes.go:142, FORCE INDEX (arrival) dropped): PostgreSQL chooses messages_groups_arrival unaided for a 1-hour and a 90-day window (index scan, then bitmap).
- Member search with FORCE INDEX dropped (membership/membership.go:697): communities up to about 4.5k members use the community index (40k buffers). The largest community walks memberships_pkey backwards (411k buffers), but 380k of that is the per-member user and email probes that any plan needs.
- Plain ModTools member list (membership.go baseTx, ORDER BY m.id DESC LIMIT 30): backward primary-key walk, 24,447 rows skipped, 628 buffers for a 1.4k-member community. Acceptable; it would degrade for a community with no recent joiners.
- Notification count (notification/notification.go:41): index scan on (touser, id, seen), 17 buffers.
- User info counts (user/userInfo.go:49, four statements): all keyed nested loops, under 100 buffers each, sub-millisecond.
- Messages by chat room (chat/chatmessage.go:1631, ORDER BY chat_messages.id DESC LIMIT): read only. chat_messages_chatmax (chatid, id, ...) exists explicitly, so the order is index-backed.
- Message fetch by id (message/message.go:560-800): about ten single-key statements per message (messages_groups, attachments, outcomes, promises, postings). Read only; nothing where the planners could differ.
- Support user search (user/user.go:1310): the email '%x%' and CONCAT_WS arms force scans of users_emails and users on MySQL too, so the extra seq scans on PostgreSQL (yahooid, users_logins) add little.
- Generic versus custom plans in general: pgx prepares every statement, so PostgreSQL may switch a statement to a generic plan after five runs on a connection. For the skewed-parameter statements tried (collection, groupid) the generic cost was higher than the custom cost, so the switch would not happen. Not shown harmful, but only three statements were tried.

**spatial**

- Wrapped indexed columns: no site puts a GiST-indexed column inside ST_SRID(), ST_GeomFromText() or a geography cast in a WHERE or JOIN. Constructors are always on the constant side.
- Function wrappers hiding the index: ST_Contains, ST_Intersects and ST_Within are called directly by both stacks. iznik_geometrytype is LANGUAGE sql and shows inlined in plans. haversine and GetMaxDimension are PL/pgSQL but only run over groups (about 500 rows) or single rows.
- authority/message.go:25 (messages_spatial JOIN authorities ON ST_Contains): Index Scan on messages_spatial_point with point @ polygon. District with a 7,985-vertex polygon: 48 rows, 441 buffers. England: 4,936 rows, about 13k buffers. Row estimate is 1 in both cases, but the nested loops that follow are what MySQL does too.
- authority/stats.go:86 and AuthorityStatsService.php:520 (the pc temp table): Bitmap Index Scan on locations_spatial_geometry, 6,843 rows in 553 buffers for a district. The three follow-on joins from the un-analysed temp table (offers, weight, searches) estimated pc at 7,006 rows against 6,843 actual and ran in 3k to 13k buffers each.
- message/bounds.go:266 (browse map box on messages_spatial): under a forced generic plan it still uses messages_spatial_point; city viewport 23 buffers, whole country 12,434 rows in 32k buffers.
- newsfeed/newsfeed.go:470-503 with FORCE INDEX (position) dropped: generic plan still picks newsfeed_position for the ORDER BY timestamp LIMIT 100 arm; a 1-degree box cost 1,599 buffers.
- location/location.go:133 and :191 (ClosestGroups): GiST on groups_polyindex is used; haversine runs only on the survivors.
- location/location.go:492 (ModTools area editor box): Bitmap Index Scan on locations_spatial_geometry, then locations_areaid; 720 buffers.
- UnifiedDigestService.php:1187 (mailNewlyReachedForPost, per post per sweep): run as rendered for a post on the largest group (45k members, 6,944 on immediate mail): 73k buffers, 240 ms. rippling_reach lands on the inner side of a nested loop and is re-read 2,020 times (22k buffer hits), which is cheap. The code comment puts MySQL at about 0.7 s a post.
- UnifiedDigestService.php:811 (drainMemberQueue) and message/search.go:424: Index Scan on rippling_reach_rippling_reach_outer, 213 buffers for 17 bbox candidates.
- iznik-routing-go/dbsql.go:316 (reachable groups, STRAIGHT_JOIN dropped): with 125k simulated approxlocs rows and fresh statistics, both the custom and the generic plan lead from users_approxlocs_position, the order the MySQL hint forces. 32k to 42k buffers for a 1.0 x 0.6 degree box.
- CommunityNewsAreaService.php:334 and :351 (nationAt): Index Scan on authorities_polygon, 9 buffers.
- authority/authority.go:224 and AuthorityStatsService.php:332 (groups x authorities with '= OR ST_Intersects'): the OR stops index use on both engines; PostgreSQL seq-scans 490 groups and bbox-rejects 486 in 173 buffers.
- story/story.go:128: the authority polygon becomes an InitPlan and the point-in-polygon test runs only on rows that reach it. users_stories is empty in the scratch database, so only the plan shape was checked.
- DoogalService.php:310-356 (ST_SRID(geometry) <> srid, and geometry <> geometry across locations and locations_spatial): full scans on both engines (2M rows: 53k and 190k buffers). PostGIS has a <> operator for geometry. Runs only on a postcode import.
- PostcodeRemapService.php:74 with the 'OR locations.areaid = ?' arm: PostgreSQL runs a full parallel hash join of locations and locations_spatial per 1,000-row chunk (66k buffers plus a temp spill at 2M rows). Without the OR it is an index scan of 70 buffers. MySQL cannot use the spatial index through that OR either, so this is not a regression; it is an admin-triggered remap.
- KNN: the hot nearest lookups (ClosestPostcode, findNearestArea, jobs) go to the spatial server, not SQL. The SQL ones order small sets: town/town.go:138 over places with population >= 3000, message/message.go:5555 over one member's groups, chat/chatmessage.go:1109 over one post's groups.
- Spatial index coverage: every MySQL SPATIAL KEY in the migrations has a GiST index in the scratch schema (11 indexes), and all 20 geometry columns are SRID 4326.

**writes-mvcc-locking**

- ON UPDATE CURRENT_TIMESTAMP triggers (62 tables, one row trigger and two statement triggers each): measured 4 to 15 microseconds a row (isochrones with 4 KB polygons 5,000 rows = 19 to 76 ms; email_tracking 20,000 rows = 111 ms; messages_likes 100 rows = 0.6 ms) and about 0.1 ms a statement for the two statement triggers. Not a cost that matters next to the index writes.
- Go deadlock detection by text: database.IsDeadlockOrLockTimeout matches the word 'deadlock', which pgx's 'ERROR: deadlock detected (SQLSTATE 40P01)' contains, so RetryOnDeadlock and the mark-seen per-row fallback still fire on PostgreSQL.
- DELETE ... LIMIT purge loops (PurgeService.php:749-1081, chunk 1000): Laravel's PostgreSQL grammar sends DELETE ... WHERE ctid IN (SELECT ctid ... LIMIT 1000); the plan is a Tid Scan fed by an index scan on logs_type. Each pass, including the last empty one, reads every live Login/Logout log through the index (168,477 buffers in my simulated steady state), which is the same shape of work MySQL does; I found no PostgreSQL-only cliff beyond dead index entries waiting for vacuum.
- Go translator form of UPDATE/DELETE with ORDER BY or LIMIT (ctid IN subselect) and of UPDATE IGNORE (single statement with NOT EXISTS on the unique key): one statement each, no savepoints.
- PutUser's address insert (user/user.go:2419) and chat message hold (chat/chatmessage.go:982): one nested GORM transaction, so one savepoint a request; nowhere near the 64 limit.
- Sql::attempt in ReachBoundsService.php:91: one savepoint per post per pass, only when already inside a transaction.
- Sequences: ON CONFLICT and rolled-back inserts burn identity values, but every id is bigint, so exhaustion is not a concern; messages_likes has no identity column at all.
- ReplaceInto on stats (StatsGenerationService.php:894): on PostgreSQL one DELETE per row plus one INSERT inside a transaction, up to about 500 round trips per date and type where MySQL sends one statement. Slower per night but bounded; minor.
- WhatJobs table swap (TableSwap.php, WhatJobsService.php:1621-1797): two ALTER TABLE RENAME in one transaction is atomic; it needs ACCESS EXCLUSIVE and so queues behind any open reader of jobs, as MySQL's RENAME waits on its metadata lock.
- The read/write split: transactions and writes go to the source on pgx (the spike), and the session check falls back to the source on a replica miss (authMiddleware.go:70-73).
- authority/stats.go temp table: created and read inside one pinned transaction, so it survives a transaction pooler; it runs on a rarely used report.
- sessions.lastactive and chat_roster.lastmsgseen are not in any index, so they can be HOT once pages have free space (they were not on the freshly loaded, full pages of the scratch database).

**this-session-changes**

- chat/chatmessage.go:1830 wider review queue (ROW_NUMBER over the UNION): ran the PostgreSQL form with 200 groups set to widerchatreview inside BEGIN/ROLLBACK. Both halves are computed in full, but each is driven by chat_messages_reviewrequired (538 rows, estimated 429); 546 rows reach the window, about 16.7k buffers, no scan of chat_messages. MySQL also materialises the whole UNION before GROUP BY, so the shape is equivalent. Cost scales with the number of reviewrequired = 1 rows, not table size.
- user/user.go:1141 GetSearchesForUser ROW_NUMBER form: Index Scan on users_searches_userid_2, top-N sort, window over at most 100 rows; 131 buffers for the heaviest user (276 searches); same plan for a user with none. The planner did not pick a backward primary-key walk.
- ON UPDATE trigger design (one row trigger plus two statement triggers on 62 tables): measured with EXPLAIN ANALYZE on messages_likes. Warm, the three triggers add about 0.05 ms to a single-row mark-seen upsert (row 0.018, done 0.013, named 0.021 ms); the first call on a connection costs 0.3 to 0.8 ms to compile. Row trigger over 19,928 rows: 52.7 ms (2.6 microseconds a row). On isochrones (4 KB polygons) 14 microseconds a row. The note setting is empty again after the statement. Not a concern at thousands of statements a minute. rippling_reach has no ON UPDATE column, so its 160 KB rows never go through the row comparison.
- Sql::nowMinus rendering statement_timestamp(): both now() and statement_timestamp() are STABLE (provolatile 's'); EXPLAIN gave identical row estimates and index range conditions for users.lastaccess > now() - 90 days and the statement_timestamp() form (123,271 rows each). Six call sites, all in FirstReply/MatchMailService.php.
- database.StatementNow(): one site (user/user.go:3638, a single-row update by primary key in the account merge). No plan effect.
- CAST(x AS CHAR) translator rule: one Go site (merge/merge.go:31), in a select list and not in a predicate. No plan effect.
- donations/donations.go BulkUploadDonations: the two extra reads per row use the unique index users_donations_TransactionID (Index Scan, one row). Admin upload only.
- user/user.go PutUser savepoint: one extra SAVEPOINT/RELEASE per signup, one subtransaction per transaction. Negligible.
- session/session.go handleForget: the only change is '' in place of NULL for messages.message; the same UPDATE ... WHERE fromuser = ? on both engines.
- ux/surveys.go ILIKE '%...%' on ux_problems.title and description: an unindexable substring search on both engines, on a small summary table read by an admin screen.
- UpdateAIImageUsageCountsCommand correlated-subquery update: the temp table is not analysed, but it has a primary key on externaluid, so the planner uses tmp_ai_usage_counts_pkey for the subquery regardless of statistics (checked with an unanalysed 50,000-row temp table). Both sides are varchar, so there is no citext mismatch. One update per ai_images row, as on MySQL.
- SendAIImageReviewDigestCommand HAVING on aggregates: one scan of microactions filtered on actiontype (no index on actiontype on either engine), 8.3k buffers here; the HAVING rewrite adds nothing.
- ReachService fingerprint helpers: the live value in the relayed request (1259147727407222857) is below 2^63, so fingerprintForDb returns it unchanged and liveLabelsSql compares reach_labels_next_fp with a plain bigint literal. No cast on the column and no index involved. The signed mapping only matters for a future fingerprint above 2^63.
- ExpandService: only the clock spelling changed in the INSERT ... ON CONFLICT (msgid) and the UPDATE ... WHERE msgid = ?; both are keyed single-row writes.
- TnMergeCrossposts index coverage: all 59 tables with a msgid column, and chat_messages.refmsgid, have an index leading on that column; no foreign key in the schema lacks a leading index (checked across all 308).
- citext equality with a bound parameter: the Go connection uses pgx's default prepared mode with no parameter type override (pgconn.go), so canon = $1 is typed citext and uses users_emails_canon; EXPLAIN with a literal confirms index use. PAF lookup tables are joined by id only (address/address.go, location/location.go, UserAddress.php), so making their name columns citext changes no join plan.

## Not covered

- (index-hints) The exact text the Go translator emits was derived by reading pgtranslate_more.go and its tests and then hand-writing equivalents with reduced select lists; I did not run the translator itself (no go test or go run), so the measured plans are for those equivalents with literal values, not for prepared statements with bound parameters. Generic plans for prepared statements could choose differently.
- (index-hints) Membership search variants not run: with a pagination cursor (m.id < ?), with the filter options (comments, moderation team, bouncing), and with a term that matches many members.
- (index-hints) The Autoapproved logs count and anything depending on real text distributions: the scratch database has no Autoapproved rows and low-cardinality names, so LIKE selectivity estimates (which drive the membership finding) may differ on production data.
- (index-hints) iznik-routing-go/reachable_groups.go uses STRAIGHT_JOIN (mentioned in UserApproxLocService.php:22) but is outside the two directories this lens covers; not examined.
- (index-hints) Hinted statements were tested with one or two parameter values each; group.go's third query (messages_groups counts, unhinted) and the unhinted listing branch of the memberships endpoint, which has the same ORDER BY m.id LIMIT shape, were not examined because they carry no hint.
- (index-hints) No production PostgreSQL settings were used: shared_buffers, work_mem and parallel settings are defaults, which affects the hash-versus-nested-loop choices reported.
- (functions-on-columns) The browse feed and search statements that carry utils.AuthorReachCapWhere (isochrone/message.go:393, message/search.go:273/401/425): I measured the per-row JSON cost only, not the full statements' plans.
- (functions-on-columns) authority/stats.go (LOCATE(' ', locations.name) > 0 joined to messages over a date range) and the dashboard DATE(timestamp) GROUP BY statements: read but not run.
- (functions-on-columns) Laravel where('settings->key') and whereJsonDoesntContainKey scans of users outside the digest (LapseDonationOptOutsCommand, ReengageService, SendAdminCommand, BirthdayService): rendered as settings::jsonb ->> with no NULLIF. Not run. A row holding '' or invalid JSON would abort the statement; MySQL also errors on invalid JSON, but only for rows its plan visits, and I did not check production data for such rows.
- (functions-on-columns) Statements over tables that are empty in iznik_perf: ux_prompt_responses and ux_* (JSON_EXTRACT(answer, ...) filters), shortlink_clicks (DATE(timestamp) GROUP BY), bounces_emails, jobs (DATEDIFF in ORDER BY, ST_Distance_Sphere), messages_bulk_items (the Collate finding rests on plan shape only), rippling_reply_attribution.
- (functions-on-columns) The other Laravel 'like' sites on citext columns were not checked one by one for the case-sensitivity change or for scan size (TN address suffix tests, nameshort NOT LIKE '%playground%', and others).
- (functions-on-columns) Raw DB::select / DB::statement SQL in iznik-batch that calls MySQL functions without the expression classes: I grepped by function name but did not read every hit.
- (functions-on-columns) GetMaxDimension, ST_SRID, st_distance_sphere and the spatial constructors: left to the spatial lens.
- (functions-on-columns) json_merge_patch (plpgsql) and json_set in UPDATE statements: write paths not examined.
- (functions-on-columns) I ran PostgreSQL statements by hand from reading the translator and the grammar; I did not run the Go translator or Laravel themselves, so a rewrite rule I missed could change the text of a statement.
- (translator-rewrites) No end-to-end run of the Go API against iznik_perf: round trips, client-side pgx cache memory and Go heap growth are derived from source and from server-side measurements, not observed in a running process.
- (translator-rewrites) The translator-cache growth rate (about a gigabyte per day of uptime) is an estimate from entry size and assumed daily members, not a measurement.
- (translator-rewrites) newsfeed FORCE INDEX (position/timestamp) sites (newsfeed/newsfeed.go:470-621) and the email_tracking FORCE INDEX (sent_at) sites (emailtracking/emailtracking.go:188, :990, :1850) were not EXPLAINed without their hints.
- (translator-rewrites) The candidate query at message/message_list.go:309 (FORCE INDEX plus DISTINCT ... LIMIT 1000) and the per-group UNION ALL built by buildMTUnionAllMsgIDQuery were not run on PostgreSQL.
- (translator-rewrites) Of roughly 40 GROUP BY sites and 60 DISTINCT sites, only the chat list, the member search and the mygroups feed were translated and planned; chat/chatmessage.go:1817 and :1859 (chat review) and the dashboard and emailtracking aggregates were not.
- (translator-rewrites) The roughly 315 IN-list sites were not audited for lists that could exceed 65,535 parameters; only the chunked ones were read.
- (translator-rewrites) Multi-table UPDATE/DELETE rewrites (UPDATE ... FROM, DELETE ... USING) were read but no real site was planned.
- (translator-rewrites) database.InsertSelect and multi-row INSERTs that now return every id through RETURNING id were not measured.
- (translator-rewrites) JSON rewrites (JSON_EXTRACT comparisons, jsonb casts) and spatial constructor rewrites were not checked for index use.
- (translator-rewrites) Laravel's expression classes and the batch code are outside this lens and were not looked at.
- (translator-rewrites) Side effect for the next reviewer: my rolled-back upsert tests left a few hundred dead tuples in messages_likes (it already had about 283k).
- (schema-conversion) Laravel-side prefix LIKE and citext/varchar join sites in iznik-batch were not searched exhaustively; the LIKE finding lists Go sites only.
- (schema-conversion) The 36 index hints (FORCE/USE/IGNORE INDEX, STRAIGHT_JOIN) that the translator strips: I confirmed they are removed but did not EXPLAIN the hinted statements; that belongs to the optimizer lens.
- (schema-conversion) Prepared-statement generic plans: pgx caches prepared statements per connection, so a skewed parameter (for example collection = $1) can be planned generically after five executions. Not tested.
- (schema-conversion) Foreign-key insert cost: each child insert takes FOR KEY SHARE on the parent row, which dirties users and messages heap pages on the messages_likes and chat_messages insert paths. Not measured.
- (schema-conversion) The per-member viewed lookup (bounds.go:48) at realistic depth: scratch members have about 10 views each, so the heap-fetch cost for a heavy member is argued from the plan shape, not measured.
- (schema-conversion) Bulk UPDATE sites on trigger tables in iznik-batch (users_approxlocs refresh, messages_spatial maintenance, email_tracking) were not run individually; users_approxlocs and sessions are empty in the scratch database. The trigger cost per row was measured on messages_likes and locations only.
- (schema-conversion) Autovacuum and bloat behaviour over time for the hot-update tables: measured per-statement WAL and buffers only, not a soak.
- (schema-conversion) Sort order and ORDER BY on citext/varchar under en_US.utf8 against MySQL's utf8mb4_unicode_ci (index-ordered scans, postcode ordering).
- (schema-conversion) TOAST behaviour of messages.message and rippling_reach blobs (the scratch data is unrepresentative for both).
- (schema-conversion) The baseline was compared with a live mysqldump of the dev MySQL container, not with production's actual schema; any index added by hand in production and absent from the migrations would not show.
- (laravel-dialect) MySQL-side plans: I had no production MySQL to EXPLAIN against, so every 'MySQL does X' is from code comments or optimizer knowledge, not measured.
- (laravel-dialect) Server settings are defaults (shared_buffers 128MB, work_mem 4MB); the hash spill in the Collate finding and the read/hit splits reflect that.
- (laravel-dialect) IncomingMailService beyond trackEmailReply and the spam-check LIKEs: its 4,652 lines are mostly single-row Eloquent lookups, which I did not walk statement by statement.
- (laravel-dialect) ReachService and ExpandService write paths under load: the 96 KB bytea updates of reach_labels, TOAST churn, and rippling_reach_leaves delete-and-reinsert per post (ReachService.php:768) were read but not measured.
- (laravel-dialect) MatchMailService (FirstReply) member-point queries (:258, :494, :696-760) and CommunityNewsEmailService::eligibleMembers: read only; they share the JSON re-parse mechanism but were not run.
- (laravel-dialect) StatsGenerationService statements other than the Collate joins and ReplaceInto (the per-type counts over messages, chat and search_history) were not run.
- (laravel-dialect) HousekeeperService, SpamCleanupService, ChatProcessService, AutoRepostService and ModNotifService queries were not examined.
- (laravel-dialect) Membership and Group JSON scopes (Models/Membership.php:330-346, Models/Group.php:176-206) with orWhereJsonContains on text settings: rendering not verified or run.
- (laravel-dialect) JsonTable and Sql::jsonRows (AuthorityStatsService.php:822): read only.
- (laravel-dialect) GroupConcat/STRING_AGG (DiscourseNotSignedUpService.php:210), JsonLength and JsonHasPath (ReachQueryService.php:309): read only.
- (laravel-dialect) One-off backfill commands that use forceIndex or OFFSET paging (MembershipOriginBackfillService, EeeBackfillCommand, AiImageMatcherService).
- (laravel-dialect) Tables empty in the scratch database (jobs, bounces, shortlink_clicks, messages_bulk_*, ux_*, helper_*): anything reading them could only be checked for plan shape with trial rows.
- (laravel-dialect) Catalogue bloat from temporary tables and lock behaviour of the jobs swap under concurrent readers: reasoned about, not reproduced.
- (laravel-dialect) Laravel-rendered statements were reconstructed by reading the grammar and expression classes, not captured from a running application (artisan and the test suites were off limits), so identifier quoting and placeholder details may differ slightly from what is actually sent.
- (go-hot-paths) Newsfeed feed and count (newsfeed/newsfeed.go:445-640, FORCE INDEX (position) and (timestamp) dropped): read but not run. The table is small (162k rows in production), which bounds the damage.
- (go-hot-paths) Message search (message/search.go, vectorsearch.go) and the map and bounds queries (message/bounds.go, authority/message.go): SQL listed, no plans run.
- (go-hot-paths) Degraded reach path (rippling.ReachOuterOnlyWhere, a GiST test on rippling_reach.outer_bound plus reading reach cells from TOAST): only the spatial-server path was run.
- (go-hot-paths) Mygroups feed (message/groups.go:60-110) and the viewer's-own-posts arm (isochrone/message.go:540): read only. They share the EXISTS predicate measured in the settings finding but the full UNION was not run.
- (go-hot-paths) Session and whoami beyond the work counts: the memberships, emails, sessions and aboutme lookups are single-key and were read only. The related-members, happiness, stories and volunteering counts (session.go:1296-1610) were not run.
- (go-hot-paths) ChatRoomListFrom for a moderator with hundreds of rooms, and countUnseenMT and getModeratorChatIDs (chat/chatroom.go:2026-2168): the scratch data has at most 13 rooms per user and no busy Mod2Mod rooms, so not representative.
- (go-hot-paths) Group list and group fetch (group/group.go), including the logs FORCE INDEX (timestamp_2) counts at :564-580 and user/user.go:4107 (logs FORCE INDEX (user)).
- (go-hot-paths) Email tracking statistics (emailtracking/*.go, four FORCE INDEX (sent_at) sites): admin pages, outside the member hot paths.
- (go-hot-paths) Empty scratch tables that appear inside hot statements were planned as empty: rippling_held_replies, chat_prompts, chat_messages_held, groups_images, shortlinks, sessions. Plans touching them (NOT EXISTS rhr, questionAskedOfMeSQL, ReplyHeldByQuestionSQL) could change with rows in them.
- (go-hot-paths) No statement was run through the real translator (no Go toolchain on the host, and go test was off limits). Every PostgreSQL form was hand-translated from the rules in pgtranslate*.go; the GROUP BY to ANY_VALUE rewrites in particular were approximated.
- (go-hot-paths) Lock and write behaviour of hot writes (mark-seen inserts into messages_likes, roster updates, users_active INSERT IGNORE) and visibility-map freshness for index-only counts under production write load: every table here was freshly vacuumed.
- (go-hot-paths) Read-replica routing (dbresolver) and replication lag on PostgreSQL.
- (spatial) Group catchment complexity: the scratch groups.polyindex are 5-point rectangles. Every per-row test against a group polygon (chat/chatmessage.go:590 and :1109, rippling/membership.go:103, CommunityNewsEmailService.php:324, ReengageContentService.php:258, PostSyncer.php:582, the routing reachable-groups query) was checked for plan shape only. Per-row cost with real multi-thousand-vertex catchments, including TOAST decompression when the group is on the inner side of a join, is unmeasured.
- (spatial) Reach density: the scratch data gives about 17 reach bounding boxes per viewer point against the 472 to 4,095 hits the code comments record for production, so absolute costs on the degraded reach path are extrapolated, not measured.
- (spatial) jobs (job/job.go:121-146) and places (town/town.go, CommunityNewsAreaService.php:83): both tables are empty in the scratch database. I read the statements but did not run them.
- (spatial) ExpandService spatial statements that take a reach polygon as WKT (lines 568, 1268, 1659, 2157, the ST_Union/ST_Intersection/ST_Buffer repair at 2745-2810 and 3193) and ReachBoundsService ST_Buffer(ST_Simplify()): read, not run. No 37k-vertex reach ring exists in the scratch data, and GEOS against MySQL cost on such inputs is unmeasured.
- (spatial) GiST write cost and bloat on rippling_reach.outer_bound under per-tick updates, and on messages_spatial and locations_spatial: not measured. Only users_approxlocs was.
- (spatial) iznik-spatial-go loaders (full-table ST_AsBinary reads of locations, messages_spatial, newsfeed): read only.
- (spatial) MatchMailService.php:706, NewsfeedModNotifService.php:122 and CommunityNewsEmailService.php:324: read, not run on the scratch database.
- (spatial) Behaviour with missing or stale statistics on geometry columns (for example straight after the migration load, before ANALYZE): all plans here were taken after VACUUM ANALYZE.
- (spatial) The relayed request (config.reach_partition_fp = 1259147727407222857, below PHP's integer maximum) concerns the partition fingerprint, not a spatial query. I did not examine fingerprint handling under this lens.
- (writes-mvcc-locking) Long transactions and long statements holding back vacuum: I did not trace the digest runs, UserManagementService merge (UserManagementService.php:128), SharedAccountSplitter or the ReachService transactions (ReachService.php:768, 840) for how long they stay open, nor measure rippling_reach TOAST churn when label blobs are rewritten each tick.
- (writes-mvcc-locking) Replica behaviour specific to PostgreSQL hot standby: query cancellation on recovery conflict (max_standby_streaming_delay), and whether any raw statement that writes or locks is routed to the read host, where Galera accepted it and a standby refuses it. Read from the spike notes only, no site audit.
- (writes-mvcc-locking) Generic versus custom plans for pgx's cached prepared statements on skewed parameters (group sizes, collection values): a planner question, not examined here.
- (writes-mvcc-locking) iznik_reset_identities, TRUNCATE sites (jobs_keywords) and the pg-migrate load path.
- (writes-mvcc-locking) Autovacuum settings per table at production size, beyond noting that the 20% default is 30M dead rows on messages_likes; no vacuum timing was measured.
- (writes-mvcc-locking) The Laravel worker and scheduler process count, and so the total connection demand from batch; the number of API processes in production is not stated in the repository, so the 400-per-process figure is not multiplied out.
- (writes-mvcc-locking) Upserts other than mark-seen (users_active INSERT IGNORE, messages_by, chat_rooms User2User, donations) were read but not measured.
- (writes-mvcc-locking) The Go account merge (user/user.go:3405-3830) was read for error handling only; its UPDATEs on logs and chat_messages by user were not run against the scratch data.
- (writes-mvcc-locking) iznik-routing-go and iznik-spatial-go write paths (labels-apply, rippling_reach_leaves) were not examined.
- (this-session-changes) Could not run the Go translator (no go test or build allowed), so the Go PostgreSQL statements were hand-translated from the translator's rules and tests. JSON_EXTRACT(g.settings, '$.widerchatreview') = 1 was run as written, relying on the json_extract function and the jsonb = integer operator in functions.sql.
- (this-session-changes) Review queue at production data shape: the scratch data has 538 reviewrequired rows and empty chat_messages_held and chat_messages_byemail. If production keeps many more rows at reviewrequired = 1, both halves grow in proportion; not measured.
- (this-session-changes) ai_images is empty in iznik_perf, so the job.go:168 join was confirmed by type resolution and by the equivalent items join, not on ai_images rows.
- (this-session-changes) Write-path cost of the mark-seen upsert beyond the triggers: a bulk UPDATE of 19,928 messages_likes rows touched about 306k buffers (non-HOT updates into full pages, three indexes). This is general PostgreSQL versus InnoDB behaviour, not something this commit introduced, and I did not pursue it.
- (this-session-changes) No-op updates: MySQL writes nothing when an UPDATE sets a row to its current values; PostgreSQL writes a new row version even though the new row trigger has already established that nothing changed. I did not find or measure a hot site that depends on this.
- (this-session-changes) The PAF importer's own lookups by name against the new citext columns (paf:import) were not read.
- (this-session-changes) GroupBoundaryService, MailSuppressionService and AutoSplitService changes in the commit were read only as diffs (identifier quoting, clock spelling, exception type); no plans were run for them.
- (this-session-changes) Concurrency was not tested: every measurement is a single session on a default-configured server (shared_buffers 128MB).
