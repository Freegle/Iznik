# Performance backlog: what the 2026 measurement rounds found and have not yet fixed

One list, replacing eight plans written between June and September 2026. Everything below was
checked against master on 2026-09-29; items already shipped are listed at the end so nobody
redoes them.

The old plans hold the full measurements. They are in git history:
`git show $(git log --diff-filter=A --format=%h -- plans/2026-09-29-perf-backlog.md)^:plans/<name>`, for
`2026-06-23-prod-slow-query-improvements.md`, `2026-07-17-db3-cpu-reach-sql-prefilter.md`,
`2026-09-02-db2-cpu-reduction.md`, `2026-09-17-db2-db3-cpu.md`,
`2026-09-18-db-perf-candidates.md`, `2026-09-19-batch-load-on-db3-priority-research.md`,
`2026-09-19-docker-host-cpu-reduction.md` and `2026-09-20-docker-host-cpu-radical-reduction.md`.
The measurement scripts are in `analysis/2026-09-17-db-cpu/` (database) and
`analysis/2026-09-20-docker-host-cpu/` (Docker host).

Shares of node CPU are as measured on the date given. They move; re-measure before sizing work
against them.

## Decisions needed first

These are blocked on a choice, not on effort.

1. **Two indexes, run by hand on prod.** Carried since 09-02.
   - `users (deleted, lastaccess)`: the active-user and daily-digest scans. 8-22% of db2 on
     09-02, 1.9% on 09-17 once the digest fix displaced it. Re-measure first. PR #1558 already
     got the active-user scan from 6.59 s to 1.55 s without it.
   - `jobs (cpc)`: a 3.9 s query scanning 1,072,047 rows, about 49,000 s on each of db2 and db3
     over the measured period.
2. **`purge:chats` spam purge** (`PurgeService::purgeSpamChatMessages`, daily 02:00). Scans
   20.5 M `chat_messages` rows on the write node to delete a handful, holding a transaction open
   for about 4.5 minutes, which under Galera is a certification risk. `reviewrejected` has no
   index. Options:
   - Index `chat_messages (reviewrejected, date)`. A full rebuild of a 40.9 M-row, 5.9 GB
     compressed table on a Galera node (no INSTANT ADD).
   - A watermark that persists across runs, like `ripple_leave_check_last_log_id`. The real fix,
     but a message rejected after the watermark has passed its id would never be purged. Safe
     only if review always happens well inside the seven-day cutoff.
   - Needs no decision and can be done now: stop the `while ($deleted > 0)` loop when a chunk
     comes back short. Halves the cost on any day that deletes something.

## Database: code changes, no decision needed

| # | What | Where | Measured |
|---|---|---|---|
| D1 | ModTools feedback count asked twice; fold into one query. The two also disagree: `groupWork` has `AND mo.comments != ''`, `session` does not, though the comment says they must match | `iznik-server-go/session/session.go` (~1500), `group/groupWork.go` (~357) | 5.3% of db3; 2.63 M executions at 122 ms |
| D2 | Admin group-list stats: three parallel 31-day aggregates on every load. Cache, or roll into the nightly stats | `iznik-server-go/group/group.go` (~584-605) | 25 s page load; the `logs` one scans 2.2 M rows |
| D3 | Embedding search group lookup: the `IN` list is large enough that the `msgid` index stops paying. Batch it, or push the collection/deleted filter into the embedding store | `iznik-server-go/embedding/store.go` (~156) | ~1% of each node; 195,390 rows per call, 1.6-1.9 s |
| D4 | Reach-mail recipient query: joins every Immediate member of the group, then `ST_Contains` per member plus two `NOT EXISTS` ledger checks. Profile the clauses before rewriting; do not guess | `iznik-batch/app/Services/UnifiedDigestService.php` (~1119) | 12 s max, ~4.8% of db2 (09-18) |
| D5 | Newsfeed located arm examines 93% of the table to return 52 rows. It has no age bound like the timestamp arm's `OPEN_AGE_CHITCHAT` | newsfeed query, `FORCE INDEX (position)` arm | 13.5% of db3 with the timestamp arm; 1.2 M calls at 202 ms |
| D6 | Chat list polling: `GET /chat` every 30 s per tab, including hidden tabs. Copy the ModTools pattern (cheap count endpoint, refetch the list only on change) and skip the poll when `document.hidden`. The `ROW_NUMBER` derived table in the list query duplicates the adjacent `LIMIT 1` join | `iznik-server-go/chat/chatroom.go` (~2102); the chat store's poll | 204 DB-hours over 10 days on db3 (July) |
| D7 | Per-user enrichment loop in the user/`messages_by` paths: thousands of calls, batch them | `iznik-server-go/user/userInfo.go`, `user/user.go` | 4,147 + 2,115 calls (June; re-measure) |

Worth a design look, not a fix:

- **Browse spatial queries**, 24.2% of db3. The largest shape runs 569,427 times at 638 ms,
  examining 217,594 rows to return 1,400. No defect; the cost is volume and ratio.
- **Email open tracking**, 6.6% of db3, all writes. `emailtracking.go` (~361) inserts an
  image-load row on every pixel fetch, 50.6 M in 22 days. Whether 2.3 M rows a day is worth it
  is a product question.

## Batch on db3 at lower priority (research, nothing started)

db2 and db3 are not isolated from each other: Galera flow control couples them, so moving the
batch load means managing it on db3. The recommended order:

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

The host is 12 cores, 1-2.5 in use overnight and about 8 in the 06:00-08:00 digest block
(09-20). The biggest levers:

| # | What | Measured |
|---|---|---|
| H1 | **Chat mailers** (`mail:chat:user2user`, `user2mod`, `mod2mod`) re-evaluate everything unmailed every second. Remember the ids already decided within a run and query only newer ones; mark messages that need no mail so they leave the candidate set; iterate every 5-10 s when the last pass sent nothing | 0.25-0.5 cores |
| H2 | **Process birth**: about 90 PHP bootstraps a minute with no opcode cache. First `opcache.enable_cli=1`, `opcache.file_cache=/tmp/opcache` (tmpfs), `file_cache_only=1`, timestamps validated (never with pcov on: 2.4 s bootstraps in testing). Then turn the every-minute loops (`ripple:expand`, the queue and mail senders, the twelve digest shards) into long-lived workers, with the backup drain check in their loop | 0.32 → 0.18 s per bootstrap, ~0.2 cores; the workers ~0.2 more |
| H3 | **Twelve digest shards poll and find nothing 99.8% of the time.** One cheap "anything approved or advanced since my last run?" query before bootstrapping; better, event-driven immediate and reach digests | 0.4 cores, ~17,000 db2 queries a minute |
| H4 | **The routing container does per-member work that should be per-post.** Catchment is O(graph), not O(reached); metrics run a Dijkstra per member instead of reading the post's stored label; `ripple-schedule` still calls the flat `Isochrone` (`iznik-routing-go/ripple.go:99`) where `engineOrFlatIsochrone` gives the same contract | ~0.5 cores all day |
| H5 | **The daily digest and the push job compute the same selection for the same members.** Let the push job reuse the email's; longer term, build the digest per post, not per member (caching the Blade render and routing calls, not MJML, which is under 0.2% of samples) | 06:00-08:00 is 9 core-hours; the push job runs 3h20m |
| H6 | **Every HTTP call from PHP opens a new connection after a fresh DNS lookup.** A shared keep-alive client. Also set `FREEGLE_GEOCODER_URL=http://spatial-knn:8194` for `batch-prod`, so the WhatJobs sync stops making 30 TLS connections a second to this host's own public address | ~70 connections a second at peak; 11,000 lookups to 8.8.8.8 every 3 minutes |
| H7 | **`community-news:research` keeps spawning the Claude CLI into a quota error.** When the JSON result has `is_error` with a 429 or a weekly limit, stop the run and skip runs until the reset time. Also asked for on 8 August | 0.06 cores average, an hourly 1.6-core spike, ~10,000 Node start-ups a day |
| H8 | **`embeddings:generate` loads an ONNX model from cold every five minutes.** Bind `EmbedderContract` to the sidecar when `EMBEDDING_SIDECAR_URL` is set (the batch already has `fetchEmbeddings`); check the vectors match | 0.1 cores |
| H9 | **WhatJobs parsing in PHP**: 1.5 M jobs per run. A Go port | most of a 26-minute run |
| H10 | **Go GC**: routing `GOGC=off` and let `GOMEMLIMIT` drive; give KNN a limit and `GOGC=200-400` (`docker-compose.yml`, `SPATIAL_GOGC`) | a few percent of each |

Smaller:

- `newsfeed:generate-link-previews` (every minute): select only items without a preview and not
  previously failed; every five minutes.
- `donations:update-ads-target`: every ten minutes, not every minute.
- Drop the per-email "Email spooled" INFO lines to DEBUG (the counts are in the run summary):
  ~200k lines a day, and the 305 MB daily log becomes readable.
- Digest render: check whether the HTML and AMP passes still build the per-card view-data twice.
  The AMP pass maps over the already-prepared posts, which suggests this is already shared; confirm
  before doing anything.

Moving work into the 21:00-05:00 trough does not lower average CPU, but it takes a core or two off
the morning peak:

| Job | Now | Could be | Check first |
|---|---|---|---|
| Rippling active hours start (`RIPPLE_ACTIVE_START_HOUR`) | 06:00 | 04:00-05:00, so the overnight catch-up finishes before the digest burst | |
| `users:cleanup` | 06:00 | 03:xx | the backup drain window, 03:50-04:35 |
| `users:fix-tn-names` | 06:30 | 03:xx | |
| `community-news:research` | hourly | nightly | whether anything interactive uses it |
| `groups:update-counts`, `chats:update-counts`, `users:update-support-roles` | hourly | 2-hourly by day | what ModTools expects |

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
  ranked with the index forced; active-user scan unhinted (PR #1558).
- Message expiry candidate scan (PR #1549).
- Digest skips the AMP render for recipients who cannot use it.
- pcov off in the production batch container (PR #1583).

Tested and rejected, so nobody repeats them: the sargable digest shard predicate (`EXPLAIN`
unchanged); pointing db1's apiv2 reads at db3 (~0.56 of db2's cores, not a lever); driving the
browse feed from the member's groups (worse for 3 of 5 members); `FORCE INDEX (added)` on the
active-user scan (beaten by ignoring `deleted`); pruning `rippling_reach` rows for completed posts
(unsafe both ways, see the rippling doc).
