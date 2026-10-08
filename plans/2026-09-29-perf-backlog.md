# Performance backlog: what the 2026 measurement rounds found and have not yet fixed

One list, replacing eight plans written between June and September 2026. Everything below was
checked against master on 2026-09-29 and re-measured over the 24-hour production watch of
2026-10-02 14:28 to 2026-10-03 14:28 UTC ("10-02/03" below; the whole-run synthesis is the final
section of `analysis/2026-10-02-perf-watch/findings.md`). Items already shipped are listed at the
end so nobody redoes them.

The old plans hold the full measurements. They are in git history:
`git show $(git log --diff-filter=A --format=%h -- plans/2026-09-29-perf-backlog.md)^:plans/<name>`, for
`2026-06-23-prod-slow-query-improvements.md`, `2026-07-17-db3-cpu-reach-sql-prefilter.md`,
`2026-09-02-db2-cpu-reduction.md`, `2026-09-17-db2-db3-cpu.md`,
`2026-09-18-db-perf-candidates.md`, `2026-09-19-batch-load-on-db3-priority-research.md`,
`2026-09-19-docker-host-cpu-reduction.md` and `2026-09-20-docker-host-cpu-radical-reduction.md`.
The measurement scripts are in `analysis/2026-09-17-db-cpu/` (database),
`analysis/2026-09-20-docker-host-cpu/` (Docker host) and `analysis/2026-10-02-perf-watch/` (the
24-hour watch: both nodes and the host at once, with hourly digest snapshots).

Shares are of the node's performance_schema statement time over the window given (complete on
db3; partial on db2, whose 112 M Laravel prepared executes a day carry no digest text, so db2
items also carry their processlist thread share), or of the Docker host's 12 cores. They move;
re-measure before sizing work against them.

## Where the time goes (10-02/03)

- **db3** (12 cores): 4.82 cores busy over 24 h (mysqld 3.29, routing 0.72, spatial 0.44);
  378,230 statement-s = 4.6 cores-equivalent. Email open tracking 40.7% (lock wait, not CPU),
  newsfeed 10.7%, browse spatial 10.3%, chat list poll 10.1%, per-user enrichment 6.0%, ModTools
  feedback count 2.9%. Mean active threads 4.59; 16.2 in the 07:00 hour and 5.7-9.8 for
  08:00-14:28, load1 12-13 all morning on 6.8-7.4 cores: the node is wait-bound, not CPU-bound.
  The load balancer serves clients from db3 alone; db2's apiv2 only sees traffic by stickiness
  after a db3 restart.
- **db2** (8 cores): 2.14 cores busy (mysqld 1.75); threads 68% batch, 25% apiv2-on-db2 (all
  before 04:10, when the stickiness expired), 7% local. Never above 3.5 cores outside the 03:00
  spatial rebuild pin (7.4-7.8 of 8 for 03:01-03:05).
- **Docker host** (12 cores): 2.89 cores busy; batch-prod 1.58 (artisan exits 1.40, of which pure
  bootstrap 0.50), routing container 0.37, spatial-knn 0.33, system.slice 0.37, delivery 0.13,
  wiki 0.13. Peak 10.35 of 12 at 06:02; 06:00-08:00 is 14.7 core-hours.

## Decisions needed first

These are blocked on a choice, not on effort.

1. **Two indexes, run by hand on prod.** Carried since 09-02.
   - `users (deleted, lastaccess)`: the active-user and daily-digest scans. 8-22% of db2 on
     09-02, 1.9% on 09-17 once the digest fix displaced it. 10-02/03: the active-user scan 2.0%
     of db2 threads over 24 h (0.04 thr, max 6 s, 1.6-2.5% by window). The daily-digest scan
     (`streamDailyOverdueFirst`, `users` LEFT JOIN `users_digests` with the six-month `lastaccess`
     range, two phases) 5.6% of db2 threads over 24 h and 28.1% for 07:00-11:00 (0.59 thr, ~9,100
     thread-s, 99% executing, max 7 s), because every post-send tick re-runs it (D8). Fix D8
     before sizing the index. PR #1558 already got the active-user scan from 6.59 s to 1.55 s
     without it.
   - `jobs (cpc)`: a 3.9 s query scanning 1,072,047 rows, about 49,000 s on each of db2 and db3
     over the measured period. 10-02/03: db2 361 calls, 3,143 s (8.7 s/call); db3 537 calls,
     3,496 s (6.5 s/call); 1,541,094 rows examined per call, 100% no index; 5.3-6.1 s/call
     overnight, 7-22 s by day; ~6,900 s/day across the two nodes. The issuer is the spatial
     server's five-minute jobs delta check (`iznik-spatial-go/dataset_jobs.go:252`, one per
     process: db2 local; db3 local plus the FD host's spatial-knn).
2. **`purge:chats` spam purge** (`PurgeService::purgeSpamChatMessages`, daily 02:00). Scans
   20.5 M `chat_messages` rows to delete a handful, holding a transaction open, which under Galera
   is a certification risk. `reviewrejected` has no index. 10-02/03: it ran on db3, the node
   serving 90% of apiv2 threads, not db2: first chunk 02:00:33-02:05:30, 297 s; the loop's second
   pass 02:05:34-02:09:30, 249 s, deleting nothing; 546 s, 98% "updating". The empty-rooms select
   (`chat_rooms LEFT JOIN chat_messages … IS NULL`) ran 46 s on db2 at 02:09. Options:
   - Index `chat_messages (reviewrejected, date)`. A full rebuild of a 40.9 M-row, 5.9 GB
     compressed table on a Galera node (no INSTANT ADD).
   - A watermark that persists across runs, like `ripple_leave_check_last_log_id`. The real fix,
     but a message rejected after the watermark has passed its id would never be purged. Safe
     only if review always happens well inside the seven-day cutoff.
   - Needs no decision and can be done now: stop the `while ($deleted > 0)` loop when a chunk
     comes back short. Removes the 249 s second pass on any day the first chunk is not exactly full.

## Database: code changes, no decision needed

| # | What | Where | Measured | 10-02/03 |
|---|---|---|---|---|
| D1 | ModTools feedback count asked twice; fold into one query. The two also disagree: `groupWork` has `AND mo.comments != ''`, `session` does not, though the comment says they must match. The same two sites run the `users_related` count pair at identical call counts; fold those in | `iznik-server-go/session/session.go` (~1500), `group/groupWork.go` (~357) | 5.3% of db3; 2.63 M executions at 122 ms | db3 63,235 calls, 10,978 s = 2.9% (179 and 168 ms; 47,523 and 44,880 rows/call; 226 ms and 6.3% in the 11:28-13:28 window under the lock-wait load); `users_related` pair 1,985 s (0.5%); db2 3,030 + 1,193 s of visible time |
| D2 | Admin group-list stats: three parallel 31-day aggregates on every load. Cache, or roll into the nightly stats | `iznik-server-go/group/group.go` (~584-605) | 25 s page load; the `logs` one scans 2.2 M rows | 7 page loads; the `logs` COUNT GROUP BY groupid 22 s per call at 00:10 (2,606,057 rows), 43-53 s at 07:14-09:47, two or three in parallel per load; 11 statements of 10 s or more, 452 s, all on db3. 45-55 s per page by day |
| D3 | Embedding search group lookup: the `IN` list is large enough that the `msgid` index stops paying. Batch it, or push the collection/deleted filter into the embedding store | `iznik-server-go/embedding/store.go` (~156) | ~1% of each node; 195,390 rows per call, 1.6-1.9 s | db2 1,170 calls, 2,284 s (1,952 ms; 202,977 rows examined and 198,546 sent per call; 4.6% of db2's visible time, 1.4% of threads); db3 919 calls, 1,486 s (1,617 ms; 0.4%). The list grows through the day, 185 k rows at 14:28 to 242 k at 06:28. Companion candidate select (`messages_embeddings` x `messages_spatial`, no index) db2 860 calls, 406 s |
| D4 | Reach-mail recipient query: joins every Immediate member of the group, then `ST_Contains` per member plus two `NOT EXISTS` ledger checks. Profile the clauses before rewriting; do not guess | `iznik-batch/app/Services/UnifiedDigestService.php` (~1119) | 12 s max, ~4.8% of db2 (09-18) | 3.3% of db2 threads over 24 h (0.06 thr), max 12 s, 17 sightings of 10-14 s; 20.1% (0.32 thr, ~1,155 thread-s) in the 05:00 reach catch-up hour, 0.4% overnight |
| D5 | Newsfeed located arm examines 93% of the table to return 52 rows. It has no age bound like the timestamp arm's `OPEN_AGE_CHITCHAT` | newsfeed query, `FORCE INDEX (position)` arm | 13.5% of db3 with the timestamp arm; 1.2 M calls at 202 ms | 40,433 s = 10.7% of db3 statement time and 10.7% of threads. Timestamp arm (`FORCE INDEX (timestamp)`, no-location path) 283,589 calls, 103.8 ms, 10,009 rows examined, 100 sent = 7.8 points; located arm 49,577 calls, 221.7 ms, 170,741 rows examined, 55 sent = 2.9 points. The age bound addresses the 2.9, not the 7.8 |
| D6 | Chat list polling: `GET /chat` every 30 s per tab, including hidden tabs. Copy the ModTools pattern (cheap count endpoint, refetch the list only on change) and skip the poll when `document.hidden`. The `ROW_NUMBER` derived table in the list query duplicates the adjacent `LIMIT 1` join. The moderator room list (`SELECT DISTINCT id FROM (SELECT chat_rooms.id … INNER JOIN memberships`) is the same poll's companion | `iznik-server-go/chat/chatroom.go` (~2102); the chat store's poll | 204 DB-hours over 10 days on db3 (July) | db3 697,196 polls (8.4/s; 14.3/s in the morning), 31,168 s + `MAX(t.search)` 3,537 s + supporter CASE 3,395 s = 38,100 s, 10.1% of statement time (44.7 ms, 3,088 rows examined, 67% no index), 10.6 DB-hours/day; #1 by thread share in the 11:26-14:28 steady state (0.94 thr, 14.8%, 61.8 ms, 4,116 rows/call). Moderator room list 24,470 calls, 4,641 s (190 ms, 29,484 rows/call), 1.2%. db2 197,887 polls, 13,845 s (27.9% of visible) until 04:10 |
| D7 | Per-user enrichment loop in the user/`messages_by` paths: thousands of calls, batch them | `iznik-server-go/user/userInfo.go`, `user/user.go` | 4,147 + 2,115 calls (June; re-measure) | db3 ten shapes, 17.7 M calls, 22,809 s = 6.0%: `messages_by` collected 889,126 calls (5.2 ms), `users_expected` x2 1.73 M calls (4.4 and 4.0 ms), outcome and refmsgid counts 1.78 M, `chat_messages` by refmsgid 1.50 M, `systemrole` 6.40 M calls (0.3 ms), arrival/reposts/attachments by msgid 4.49 M. ~890,000 user enrichments and ~1.5 M message enrichments a day |
| D8 | **Daily digest post-send ticks re-scan and re-fetch for 3.4 hours.** The 8 shards run every minute 07:00-12:00 London with the comment that a post-send tick is "a cheap no-op" (`routes/console.php:765`); `lastsent` is stamped only when posts were sent (`streamDailyOverdueFirst`), so a member with nothing to send is eligible again on the next tick. Stamp a per-run watermark, or stop the ticks once the main pass has finished | `iznik-batch/app/Services/UnifiedDigestService.php` (~1808), `routes/console.php` (~765) | | 07:35-11:00 UTC: 2,392 ticks of 18-23 s wall and 1.14-1.40 s CPU (2,631 s host CPU); db2 `ud_ord` scans 0.59 thr = 28.1% of db2 threads and `getPostsForUser` 0.10-0.21 thr for 3.4 h (~11,000 thread-s); spatial-knn 0.61-0.76 cores for 08:00-10:59 against 0.08 at 11:00 (~7,500 CPU-s); prepared executes 85 k/min. Every day until 11:00 UTC |
| D9 | **`browse:backfill-max-distance` makes one public-API routing call per member a night.** 156,121 `GET /apiv2/town/near` from this host's own address 02:40-08:11, each running an isochrone on db3's routing server, to correct 1,018 (136,697 already consistent, 18,331 no location). Skip members whose inputs are unchanged, call the routing server directly, or batch | `routes/console.php` (`dailyAt 02:40`), `iznik-batch` browse backfill command | | db3 routing process 0.92-1.02 cores for 03:00-05:59 (0.12-0.17 at 00:00-01:59) and 1.27-1.61 in the digest hours, ~4.7 core-hours on db3; ~6,500 requests/h 03:00-09:00 against ~1,000/h by day; the member-facing slider endpoint 3-8 s through the morning (241 responses over 3 s in the 08:00 hour); 706 s host CPU |
| D10 | **03:00 UTC `rebuildAll` fires on all three spatial instances at once** (`iznik-spatial-go/server.go`, `startScheduler`), in the same minute as `messages:process-expired`, `locations:update-postcodes`, `users:update-engagement`, `cleanup:sessions`, `eee:classify-new` (:00) and the searches backfill (:01). Stagger the instances and the jobs | `iznik-spatial-go/server.go`, `routes/console.php` (`dailyAt('03:00')`) | | db2 pinned 7.4-7.8 of 8 for 03:01-03:05 (spatial 4.2-5.4 cores, load1 16.1); db3 11.44 of 12 at 03:03 (spatial 8.92). On MySQL: overflow_cells loads 303 + 556 s (db2) and 230 s (db3), locations centroid 2 x 36 s + 153 s (4,587,986 rows each), locations area 58 + 153 s, jobs WKB 61 s; `messages:process-expired --spatial` 174 s, `locations:update-postcodes` 144 s CPU and two 27 s scans, `eee:classify-new` 43 s, searches backfill 80 s in the same minutes |
| D11 | **Support dashboard "moderators active"** (`getModeratorsActive`): the dependent subquery reads `messages_groups` by `groupid` (7,766 rows estimated per membership), not by the `lastapproved (approvedby, groupid, arrival)` index the comment relies on (cardinality 21 on `approvedby`); the 30 s context ceiling does not stop the server-side statement. Rewrite as a join with a group-bounded arrival range, and bound it server-side | `iznik-server-go/dashboard/dashboard.go` (~788) | | one call at 21:24 on db2, 205.7 s, 48,682,645 rows examined, 1,041 sent, 498 groups in the IN list; 3 calls over the run, 213 s |
| D12 | **Held-replies scan in the chat mailers** (`ChatNotificationService.php:136`, once per iteration of all three mailers): `SELECT chatmsgid FROM rippling_held_replies WHERE status = 'released' AND releasedat >= <24 h>` on the `(status)` index, 12,224 rows estimated for a 251-row answer. Index `(status, releasedat)` | `iznik-batch/app/Services/ChatNotificationService.php` (~136) | | 3.0% of db2 threads over 24 h (0.06 thr, ~5,200 thread-s/day), ~270,000 calls/day at 20 ms, 99% executing; table 24,416 rows, 21,126 released, 251 released in the last 24 h |
| D13 | **`microvolunteering:notify` member scan** (`MicrovolunteeringNotifyService.php:289`, every 5 min): `SELECT DISTINCT memberships.userid … INNER JOIN users … role = 'Member' AND users.lastaccess >= 31 days`, one per group. Select the groups with something to notify first, or cache the active-member set | `iznik-batch/app/Services/MicrovolunteeringNotifyService.php` (~289) | | 4.5% of db2 threads over 24 h (0.08 thr, #3 shape), max 2 s, prepared (no digest row); 280 runs, 184 s host CPU |
| D14 | **`ripple:expand` orphaned-copy retraction** (`ExpandService.php:751`, `retractCopiesOrphanedByOriginRemoval`, once per run every minute, unscoped unless `--msgid`): `SELECT DISTINCT mr.msgid FROM rippling_reach mr JOIN messages_groups mg … WHERE NOT EXISTS (…)`. Scope it to origins removed since the last run | `iznik-batch/app/Services/ExpandService.php` (~751) | | 2.9% of db2 threads over 24 h (0.05 thr, ~5,000 thread-s/day), 3.3 s per run, max 6 s, 99% executing, through the inactive hours |
| D15 | **Volunteering list examines 16,562 rows to return 0 on every call** (`SELECT DISTINCT volunteering.id … LEFT JOIN volunteering_groups … volunteering_dates … users`). Filter by group and date before the joins, or cache the empty answer | apiv2 volunteering list | | db3 252,593 calls, 3,815 s (15.1 ms; 1.0%); db2 78,680 calls, 1,245 s (2.5% of visible); ~331,000 calls/day, 0 rows sent |
| D16 | **Message list with `spatialid`** (`message/message.go`): the per-message `EXISTS(messages_outcomes …)` select | `iznik-server-go/message/message.go` | | db3 265,324 calls, 2,772 s (10.4 ms); db2 78,969 calls, 2,032 s (25.7 ms, 4.1% of visible) |
| D17 | **Hourly and nightly batch statements of 10 s or more on db2** (the write node): the `users_searches` x `users_searches_embeddings` anti-join backfill (`GenerateSearchEmbeddingsCommand`, hourly :01, `ORDER BY s.id DESC LIMIT 100`); the `messages_eee` anti-join candidate select (`eee:classify-new`, hourly :00, `GROUP BY … ORDER BY MIN(COALESCE(approvedat, arrival)) LIMIT 1000`); the `rippling_reach` tuple lookup `WHERE schedule IS NOT NULL AND (lat, lng) IN (…)` (ReachService / ExpandService / RetractOutOfReach); the autorepost and chase-up selects; the `messages:update-spatial-index` `ROW_NUMBER() OVER (PARTITION BY msgid …)` derived table (done list, PR #1558, still 10-14 s). Nightly: `purge:logs` (03:30) `SELECT DISTINCT msgid FROM messages_likes WHERE timestamp < <365 d>` (unindexed, its own comment says so) and the orphan-logs LEFT JOIN; `messages:process-expired --spatial` candidate scan (done list, PR #1549); `users:update-lastaccess --full` two-arm `DISTINCT(userid)` union with `TIMESTAMPDIFF`; `electricals:stats` two full-year `messages` scans. Keyset watermarks and indexes, one at a time | `iznik-batch` commands named | | per day: searches backfill 23 sightings, 21-80 s, 1,079 s; `eee:classify-new` 23, 20-43 s, 694 s; `rippling_reach` tuple lookup 49, 10-27 s, 729 s; autorepost/chase-up 11, 10-30 s, 174 s; spatial-index derived table 10, 10-14 s, 107 s; `purge:logs` 158 + 139 s; `process-expired` 174 s; `update-lastaccess` 86 s; `electricals:stats` 64 + 65 s |

Worth a design look, not a fix:

- **Email open tracking** is db3's largest wait family: 10-02/03 153,867 s = 40.7% of db3
  statement time and 39.1% of db3 threads, plus COMMIT 14,899 s (3.9%; 92% "replicating and
  certifying write set"). `UPDATE email_tracking SET scroll_depth_percent` 1,297,186 calls at
  65 ms (84,292 s), `INSERT email_tracking_images` 2,214,097 calls at 26 ms (57,725 s), `UPDATE …
  opened_at` 484,832 calls at 22 ms (10,449 s; 91% update nothing and still wait). Lock, not CPU:
  `SUM_LOCK_TIME` is 99% of the UPDATE, 96% of the INSERT, 97% of the opened-at, and the family's
  lock time equals db3's whole `Innodb_row_lock_time` (2.42 threads continuously in row-lock wait,
  49 ms each, 227 waiters at 11:34). Image loads of one email serialise on its tracking row:
  the per-image parent-row UPDATE is the lock holder, and the family is 67.7% of db3 statement
  time in 05:28-07:28 and 11.8 of the node's 16.2 active threads in the 07:00 hour. Volume
  2.31 M image rows a day (as before). `emailtracking.go` (~361). Whether 2.3 M rows a day is
  worth it is a product question; whether the parent row needs an UPDATE per image is not.
  **Done in the email-tracking-journal PR (2026-10-08):** image loads and pixel opens append to
  `email_tracking_journal` in batches (no lookup, no FK, no parent UPDATE) and `mail:tracking:fold`
  applies them at 01:35. `scroll_depth_percent` has no reader at all. The 2.3 M rows a day are
  still written (the user data dump reads them); dropping them is the remaining product question.
  Reader inventory and the delivery-health / mark-seen adjustments:
  `docs/developers/reference/email-tracking-journal.md`.
- **Browse spatial queries**: 10-02/03 seven shapes 38,929 s = 10.3% of db3 statement time, 6.9%
  of threads (was 24.2%). Largest 30,812 calls at 347 ms, examining 263,005 rows to return 1,330;
  the `COALESCE(MIN(mgv.arrival))` variant 15,432 calls at 312 ms, 438,201 rows; the
  group-membership feed 5,810 calls at 872 ms, 99,568 rows. No defect; the cost is volume and
  ratio.
- **Load-balancer topology** (applb, `backend api_server_backend`): db3 is the only active server,
  db2 and db1 `check backup`, `stick on src` for 30 min. 10-02/03: db2's apiv2 carried 29-50% of
  db2's threads for 14:00-20:59 on 10-02 by stickiness after the 17:36 restart and 2% from 04:10
  on 10-03, while db2 sat at 1.2-2.5 of 8 cores and db3 ran at load 12-13 with 6-16 active
  threads. Making db2 an active weighted server moves read load off the wait-bound node, subject
  to the write-funnel convention (both apiv2 instances already write to db3 and read db2).
- **Off-schedule full dataset loads by db2's local spatial process**: six outside the 03:00
  schedule in 24 h (82, 447, 63, 327, 12-13 and 103 s of overflow_cells "Sending to client", with
  the jobs WKB, locations centroid and area scans alongside), wall not CPU. The code paths are
  startup, the 24 h rebuild and `POST /v1/:dataset/rebuild`; the two-minute reconcile never calls
  `Load`. Needs a cause before a fix.

## Batch on db3 at lower priority (research, nothing started)

db2 and db3 are not isolated from each other: Galera flow control couples them, so moving the
batch load means managing it on db3. 10-02/03: batch is 3.2% of db3 threads over 24 h (0.15 thr)
and 68% of db2's; db3's morning saturation is apiv2's own tracking writes, not batch; db2
`wsrep_flow_control_sent` 55 in 28.6 h, db3 0. Batch DML already lands on db3: the 02:00 purge
delete (546 s), `users:remove-spammers` `DELETE FROM users_notifications … spam_users` every
5 min (max 8 s, 0.4% of db3 threads), the microvolunteering `UPDATE users_notifications`. The
recommended order:

1. A dedicated `batch` MySQL account (`DB_USERNAME` in `.env.background`). Today every client is
   root, so batch is invisible in the process list.
2. Session setup on every batch connection through a `ConnectionEstablished` listener:
   `SET RESOURCE GROUP batch`, `READ-COMMITTED`, a generous `max_execution_time` for reads, a
   short `innodb_lock_wait_timeout` with retry.
3. Resource group `batch` with `THREAD_PRIORITY=19` on both data nodes. Needs the
   `CAP_SYS_NICE` systemd drop-in and a restart first.
4. A shared throttle helper in `iznik-batch` (chunked, with a floor rate so interrupted work
   still finishes), used by every chunk loop.
5. Raise db3's buffer pool to about 24 GB; watch `Innodb_buffer_pool_reads` and apiv2 p95 for a
   week.
6. Move in one reversible step: `DB_HOST_READ_IP` on the batch host to db3, then db1's apiv2
   reads. Success check: `wsrep_flow_control_sent` on db2 stops moving.
7. `pt-kill` on db3 as the backstop, batch account only, query kill not connection kill.

Avoid thread-pool priority queues (they starve). MySQL has no I/O or buffer-pool isolation.

## Docker host CPU

The host is 12 cores: 10-02/03 2.89 cores busy over 24 h, 0.95-2.5 overnight, 8.38 and 6.34 in the
06:00 and 07:00 hours (14.7 core-hours for the block), peak minute 10.35 of 12 at 06:02. The
biggest levers:

| # | What | Measured | 10-02/03 |
|---|---|---|---|
| H1 | **Chat mailers** (`mail:chat:user2user`, `user2mod`, `mod2mod`) re-evaluate everything unmailed every second. Remember the ids already decided within a run and query only newer ones; mark messages that need no mail so they leave the candidate set; iterate every 5-10 s when the last pass sent nothing | 0.25-0.5 cores | 16,433 s = 0.19 cores: user2user 1,394 runs at 8.3 s (10.7-13.7 s by day, 1.6-4.3 s at night, so the cost scales with the unmailed candidates), user2mod 3.1 s/run, mod2mod 0.42 s/run |
| H2 | **Process birth**: 123,069 artisan bootstraps a day (85.6/min) with no opcode cache. First `opcache.enable_cli=1`, `opcache.file_cache=/tmp/opcache` (tmpfs), `file_cache_only=1`, timestamps validated (never with pcov on: 2.4 s bootstraps in testing); set `COLUMNS` and `LINES` so Symfony Console stops forking `stty -a` twice per bootstrap. Then turn the every-minute loops (`ripple:expand`, the queue and mail senders, the twelve digest shards) into long-lived workers, with the backup drain check in their loop | 0.32 → 0.18 s per bootstrap, ~0.2 cores; the workers ~0.2 more | floor 0.352 s per bootstrap-and-exit; floor x exits = 43,320 s = 0.50 cores of pure bootstrap, 0.46-0.57 in every window including the backup drain; artisan exits 120,696 CPU-s = 1.40 of batch-prod's 1.58 cores. The 25 every-minute commands 34,881 exits, 30,349 s (0.35 cores, floor 12,278 s). `stty -a` 237,082 exits |
| H3 | **Twelve digest shards poll and find nothing 99.8% of the time.** One cheap "anything approved or advanced since my last run?" query before bootstrapping; better, event-driven immediate and reach digests | 0.4 cores, ~17,000 db2 queries a minute | immediate 11,152 runs, 21,328 s (1.91 s/run in every hour) + reach 5,579 runs, 12,207 s (3,578 s of it the 05:24-06:18 catch-up; 0.3-2.3 s/run otherwise) = 0.39 cores. On db2 the shard candidate select (`messages` x `messages_groups` NOT EXISTS) is the #1-2 shape by thread share in every window, 6.8% over 24 h (0.13 thr), with its `mg.arrival` companions 3.2 + 1.6% and the `email_tracking` count 1.8%: 13.4% of db2 thread time, cheap statements issued often (32% "statistics", 24% "Opening tables") |
| H4 | **The routing container does per-member work that should be per-post.** Catchment is O(graph), not O(reached); metrics run a Dijkstra per member instead of reading the post's stored label; `ripple-schedule` still calls the flat `Isochrone` (`iznik-routing-go/ripple.go:99`) where `engineOrFlatIsochrone` gives the same contract | ~0.5 cores all day | routing container 0.37 cores over 24 h (0.43-0.53 by day, 1.43 in the 06:00 hour, 0.003-0.02 for 23:00-05:59). Not in the item: the on-node routing process on db3, 0.72 cores over 24 h (0.52-0.69 by afternoon, 0.92-1.02 for 03:00-05:59 under D9, 1.27-1.61 in the digest hours), and the on-node spatial 0.44; they issue the `rippling_reach` label reads (1.5% of db3 threads, 1.6% of db2) |
| H5 | **The daily digest and the push job compute the same selection for the same members.** Let the push job reuse the email's; longer term, build the digest per post, not per member (caching the Blade render and routing calls, not MJML, which is under 0.2% of samples) | 06:00-08:00 is 9 core-hours; the push job runs 3h20m | 06:00-08:00 is 14.7 core-hours (batch-prod 8.5, spatial-knn 2.6, routing container 2.1); the 8 daily shards 05:59:50-07:35, 2,276-2,374 s CPU each (18,725 s, 5.2 core-hours, 0.41 cores each); `push:daily-posts` 06:30-09:55, 12,303 s wall, 2,054 s CPU. On db2 the shards' post fetch (`getPostsForUser`) is 28.8% of threads (0.59 thr) in 06:00-07:08; on db3 the block is 6.7 then 16.2 mean active threads, 70% of them tracking-row waits |
| H6 | **Every HTTP call from PHP opens a new connection after a fresh DNS lookup.** A shared keep-alive client. Also set `FREEGLE_GEOCODER_URL=http://spatial-knn:8194` for `batch-prod`, so the WhatJobs sync stops making 30 TLS connections a second to this host's own public address | ~70 connections a second at peak; 11,000 lookups to 8.8.8.8 every 3 minutes | `locations:remap-postcodes` (01:00) made 1,994,738 calls to spatial-knn's `/v1/locations/knn` in 99 min (336/s): 3,772 s CPU of which 2,524 s system, 1,994,739 threads created (libcurl's resolver, one per call), plus spatial-knn 0.44-0.57 cores for the same 99 min (~2,900 s); 0 of 1,994,738 remapped. ~2,500 s of system time a night from connection setup alone |
| H8 | **`embeddings:generate` loads an ONNX model from cold every five minutes.** Bind `EmbedderContract` to the sidecar when `EMBEDDING_SIDECAR_URL` is set (the batch already has `fetchEmbeddings`); check the vectors match | 0.1 cores | 311 runs, 5,117 s, 16.5 s/run (7-22 s), 0.059 cores (0.015 overnight, 0.104 in the morning) |
| H9 | **WhatJobs parsing in PHP**: 1.5 M jobs per run. A Go port | most of a 26-minute run | two full runs (21:00, 09:00), 27.5-28.7 min wall, 664-702 s CPU each = 0.4 cores while running; its `geocodePostcode` (`locations WHERE type = 'Postcode' AND name LIKE 'XX %'`) ~0.23 thr on db2 for the run |
| H10 | **Go GC**: routing `GOGC=off` and let `GOMEMLIMIT` drive; give KNN a limit and `GOGC=200-400` (`docker-compose.yml`, `SPATIAL_GOGC`) | a few percent of each | not measurable from the watch; the ceiling is the containers themselves, routing 0.37 and spatial-knn 0.33 cores over 24 h |
| H11 | **`schedule:finish` is the host's third-largest exited command.** Laravel runs it after every `runInBackground` task (`routes/console.php` has 158); it is a full bootstrap that does nothing but mark the task done. Run background tasks in-process, or batch the finish calls | | 57,766 exits, 20,308 s, 0.235 cores, 0.352 s each, bootstrap only; 5,739 of them (2,094 s) inside the 06:00-08:00 block |
| H12 | **`locations:remap-postcodes` (01:00) calls the KNN once per postcode, 1.99 M a night, for 0 remaps.** Skip postcodes whose inputs are unchanged, or a batch KNN endpoint; the connection cost is H6 | `PostcodeRemapService` | | 5,932 s wall, 3,772 s CPU + ~2,900 s spatial-knn = 1.9 core-hours, the host's largest single job overnight and most of the 01:00-02:40 rise from 1.3 to 2.5 cores |
| H13 | **`queue:work` re-bootstraps every 3 s during the backup drain**: supervisor's two `laravel-worker` processes (`--max-time=3600`, `autorestart=true`, `docker/supervisor.conf:28`) exit at the drain check and restart, while the scheduled commands exit at 6 ms. Sleep in the loop instead of exiting | `docker/supervisor.conf` (~28), the worker's drain check | | 1,265 exits in 03:50-04:35 (28/min, 3.3 s wall, 0.33 s CPU each), 414 s; 1,303 exits, 520 s over 24 h |
| H14 | **Wiki pages are rendered, not cached, for crawlers.** ClaudeBot 11,738 requests in 20:00-22:00 (98/min; 10,920 answered 200, 95% article pages), plus MJ12bot and DotBot | wiki-media (MediaWiki) | | wiki-media + wiki-mysql 0.32-0.36 cores for the two hours (0.07-0.11 otherwise), ~916 s apache CPU at ~70 ms per request; 0.133 cores over 24 h for the two containers |

Smaller:

- Drop the per-email "Email spooled" INFO lines to DEBUG (the counts are in the run summary):
  ~200k lines a day, and the 305 MB daily log becomes readable. Not measured by the watch.
- Digest render: check whether the HTML and AMP passes still build the per-card view-data twice.
  The AMP pass maps over the already-prepared posts, which suggests this is already shared; confirm
  before doing anything. Not measured by the watch.
- Dropped: `newsfeed:generate-link-previews` and `donations:update-ads-target` as separate items.
  10-02/03: 1,394 exits each, 558 and 544 s, 0.40 and 0.39 s per exit, within 0.05 s of the
  bootstrap floor; the whole cost is H2.

Moving work into the 21:00-05:00 trough does not lower average CPU, but it takes a core or two off
the morning peak:

| Job | Now | Could be | Check first | 10-02/03 |
|---|---|---|---|---|
| Rippling active hours start (`RIPPLE_ACTIVE_START_HOUR`) | 06:00 | 04:00-05:00, so the overnight catch-up finishes before the digest burst | | the reach catch-up ran 05:24-06:18 and overlapped the daily digest's first 18 minutes: 3,578 CPU-s on the host (four shards at 718-837 s each, then 24 runs of 71-167 s) and ~1,500 db2 thread-s of D4 (20.1% of db2 threads in the 05:00 hour) |
| `chats:update-counts` | hourly | 2-hourly by day | what ModTools expects | 23 runs, 1,530 s, 66.5 s each (0.018 cores); two runs, 144.5 s, inside the 06:00-08:00 block |

Dropped from the table, nothing measurable to move (10-02/03): `users:cleanup` 2.0 s CPU, 52 s
wall, one 10 s db2 statement; `users:fix-tn-names` 11.1 s CPU, 64 s wall, one 13 s statement;
`community-news:research` already runs hourly through the night (22 runs, 56.5 s in 24 h);
`groups:update-counts` 23 runs, 9.8 s; `users:update-support-roles` 23 runs, 9.7 s.

Do not move: the daily digest, immediate digests and chat mails, the spoolers, `tn:sync`, the
backups.

## Already done, do not redo

- Reach cost redesign, stage 2: stored labels are the membership record, grids retired.
- Batch host cgroup slices (`interactive.slice`, `batch.slice`,
  `docker-compose.override.batchprod.yml`).
- Sandwich bounds for reach containment (PR #1098); design facts in
  `docs/developers/reference/rippling-algorithm.md`, section 11.
- `users.lastaccess` throttled in SQL (PR #1099) and its duplicate call removed.
- `ST_SRID(point, ?)` wrapper removed from the browse count.
- `memberships (emailfrequency, groupid)` index for `groups_digests` (PR #1364).
- Reach mail on change feeds; keyset watermarks on the purge loops, illustrations cleanup and
  leave check (PR #1488).
- `users_notifications (url)` index, applied.
- Daily digest carryover restored to its index (PR #1542); ripple leave opt-out as one indexed
  read (PR #1543).
- Chat room list `DISTINCT` removed; browse membership passed as constants; spatial reconcile
  ranked with the index forced; active-user scan unhinted (PR #1558). The spatial reconcile's
  `ROW_NUMBER` derived table still runs 10-14 s ten times a day on db2 (D17).
- Message expiry candidate scan (PR #1549). The `--spatial` candidate scan still runs 174 s at
  03:00 on db2 (D17).
- Digest skips the AMP render for recipients who cannot use it.
- pcov off in the production batch container (PR #1583).
- `community-news:research` spawning the Claude CLI into a quota error (the old H7): gone.
  10-02/03: `claude -p` 138 exits, 451 s, 0.005 cores; `community-news:research` 22 runs, 56.5 s;
  no hourly spike in any of the 24 hours.

Tested and rejected, so nobody repeats them: the sargable digest shard predicate (`EXPLAIN`
unchanged); pointing db1's apiv2 reads at db3 (~0.56 of db2's cores, not a lever); driving the
browse feed from the member's groups (worse for 3 of 5 members); `FORCE INDEX (added)` on the
active-user scan (beaten by ignoring `deleted`); pruning `rippling_reach` rows for completed posts
(unsafe both ways, see the rippling doc).
