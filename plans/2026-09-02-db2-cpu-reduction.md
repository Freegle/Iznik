# db2 CPU reduction — measured findings and proposed changes

Investigation only; **nothing here has been applied**. Measured 2026-09-02, 07:15-09:30 UTC.

Goal: halve db2's CPU.

## Summary — every proposal, ranked

| | change | worth to db2 | type | evidence |
|---|---|---|---|---|
| **A** | reach mail window 60 → 5 min | **46-68% by day** | one config value | window/throughput measured across 190× range; crossover observed live |
| **G** | db1's **and db2's** apiv2 reads → db3 | **23-49% by day**, ~32% overnight | env line ×2 + monit restart | share measured 7×; db3 verified 80-95% idle |
| **K** | keyset watermark on the two anti-join purge loops | **47% of db2 while `purge:logs` runs** | code | 2.9 M rows read per row deleted; 640 s chunk observed |
| **D** | index `users (deleted, lastaccess)` | 8-22%, two callers | **operator DDL** | 1,845,193 → 184,832 rows (10.0×) |
| **B** | illustrations watermark | 5% by day, **22% overnight** | code | 39,668,257 → 13,644 rows; 15.93 s → 0.072 s |
| **H** | index `jobs (cpc)` | 6-10% overnight | **operator DDL** | full scan of 1.3 M rows; ~5× fewer |
| **I** | leave-check lookback 2 days → ~30 min | ~6% | code | 1,195 rows scanned vs ~1/hour genuinely new |
| **J** | mod2mod chaseup window/watermark | ~5% | code | 153 rooms and 16,730 messages re-fetched every minute |
| **L** | microvolunteering: `LIKE`→`=` + index on `url` | ~5% | code + **operator DDL** | 3,461-row scan per candidate message |
| **F** | `sort_buffer_size`, `table_open_cache` | hygiene only | my.cnf, both nodes | swap ruled out as a latency cause |
| **E** | digest shard counts | re-derive after the above | config | tuned against the batch host's CPU, not db2's |
| ~~C~~ | ~~sargable shard predicate~~ | **rejected** | — | `EXPLAIN` unchanged; ~1.6× even when forced |

**A and G alone exceed the halving target and need no schema change.** K is the biggest single
overnight win. B, D and H are the cheapest and carry no product tradeoff.

**The one open decision** is in A: shortening the window means members who become eligible *after* a
post's reach last changed are caught within 5 minutes rather than 60. The dual change-feed
alternative was investigated and does not exist in the schema — see proposal A.

**What this does NOT cover:** the 00:30-05:00 nightly block, sampled only opportunistically. It
contains 15+ unexamined jobs, and the two that have been examined (`purge:logs`, `purge:chats`)
both turned out to be defective. See "The nightly window".

## Baseline

| node | cores | mysqld lifetime CPU-min | load during window | role |
|---|---|---|---|---|
| db1 | 8 | 1,601 | 0.23 - 0.80 | **deliberately idle — leave alone** |
| db2 | 8 | **61,601** | 3.85 - 6.75, mysqld 244-800% | all batch reads |
| db3 | 12 | 20,130 | 5.51 - 12.04 | write node + all API traffic |

db2 is **99.8% batch traffic** (171,358 processlist samples over 5 min; apiv2 contributed 0.1%).

### db1 is not a lever

An earlier draft of this plan proposed adding db1 to the batch read pool as the single biggest
win. **That is ruled out — db1 is kept idle deliberately.** Everything below therefore has to come
out of reducing the work itself, not spreading it.

(The timings in this document were taken on db1 because it was quiet enough to measure against.
That was for measurement only; no traffic was moved. Future measurement should avoid it.)

### Measuring caveat

`events_statements_summary_by_digest` sees only ~18% of db2's work:
`statement/sql/select` = 917,300 s vs `statement/com/Execute` = **4,209,872 s**. Laravel's prepared
statements are not aggregated into the digest table. **All rankings below come from processlist
sampling, not from the digest table** — ranking from the digest table gives the wrong answer.

Also noted: `Com_stmt_prepare` ≈ `Com_stmt_execute` ≈ `Com_stmt_close` ≈ 1.374 billion — every
statement is prepared, executed once and closed. 139,513 s of that is pure parse/plan (~3%).

## Load accounting

Categorised sample, 61,080 active-thread observations over 90 s (08:58-09:00 UTC):

| item | share | proposal |
|---|---|---|
| reach recipient query | 47.0% | A |
| daily digest scan | 17.9% | D (C was tested and rejected) |
| illustrations cleanup | 6.3% | B |
| active-user scan | 4.1% | D |
| reach labels/cells | 1.9% | — |
| long tail (nothing above 3.9%) | 22.8% | — |

Concurrency: **20 digest workers** in 18 of 40 samples (mean ≈ 14, peak 20) against **8 cores**.

**Off-peak the reach query is even more dominant.** Second sample at 13:27 UTC, after the
07:00-12:00 BST daily-digest window closed (46,881 observations; db2 load 3.88, mysqld 200%,
8 workers):

| item | peak (08:58) | off-peak (13:27) |
|---|---|---|
| reach recipient query | 47.0% | **68.0%** |
| daily digest scan | 17.9% | — (window closed) |
| illustrations cleanup | 6.3% | 4.9% |
| active-user scan | 4.1% | 2.2% |

The reach recipient query is the only item that tops both regimes. **Proposal A alone clears the
halving target off-peak**, and is the largest single item at peak too.

## Findings

### 1. Reach recipient query — 47-51% of db2

`UnifiedDigestService.php:973`, driven by `mail:digest:unified --mode=reach`.

`sendReachDigests` (`UnifiedDigestService.php:637`) selects every `rippling_reach` row updated in
the last **60 minutes**; the cron fires **every minute** (`routes/console.php:824-828`).

| window | rows |
|---|---|
| 1 min | 10 |
| 3 min | 14 |
| 5 min | 27 |
| 15 min | 100 |
| 60 min | **435** |

Each pass re-processes 435 posts when ~10 changed. 8,224 posts sit in `expanding`, with
`next_expansion_at` spread ~3-9 per minute.

**Measured execution rate and duration.** Four samples of `information_schema.processlist`, polled
~106 times/s, so concurrency is measured rather than estimated:

| | 13:42 | 13:57 | 18:12 | 19:42 |
|---|---|---|---|---|
| distinct executions | 370 | 313 | 278 | 328 |
| throughput λ | 187/min | 254/min | 225/min | **266/min** |
| mean concurrency L | — | 2.99 | 2.64 | **2.73** |
| mean duration L/λ | — | 0.71 s | 0.70 s | **0.62 s** |

Four measurements across six hours converge tightly: **~250/min, ~2.8 threads in flight, ~0.65 s
each**. The query's cost on db2 is a stable property, not something that swings with the hour —
which is what makes the 10× gap against the 65 ms idle-node timing a contention effect rather than
a measurement artefact.

`processlist.time` corroborates the duration: 13,198 observations at 0 s, 9,643 at 1 s, 525 at 2 s,
99 at 3 s.

**The same query measured 65 ms on an idle node** (msgid 121725017, 4 KB polygon, 976 candidates).
Under load on db2 it averages 0.71 s — **~11× slower purely from contention**, so cutting the count
pays back more than proportionally: fewer queries means the survivors get faster too.

**Repeat rate measured directly**, not inferred. The samples above were shorter than one sweep, so
no msgid could repeat inside them. A 10-minute sample (599 s, 188,038 observations, executions
separated by a >5 s gap in the same msgid):

| | |
|---|---|
| distinct msgids | 757 |
| total executions | 2,238 |
| throughput | **224/min** |
| mean executions per msgid | **2.96 per 10 min** |

| repeats in 10 min | msgids |
|---|---|
| 1× | 78 |
| 2× | 75 |
| 3× | **406** |
| 4× | 198 |

Most posts are re-processed 3-4 times per 10 minutes — a sweep every ~2.5-3 min, so **~18
executions per post per hour** in the window. (An earlier draft inferred 26× from the window size
and throughput; the measured figure is 18×.)

**The cost is spread evenly across posts — there is no fat tail to trim.** From the same 10-minute
sample (observations ≈ DB time):

| slice | share of the query's DB time |
|---|---|
| top 1% of msgids (7) | 3.8% |
| top 10% (75) | 24.6% |
| top 25% (189) | 48.5% |
| top 50% (378) | 75.9% |

The heaviest single post is 0.62%; median 209 observations against a mean of 248. That is close to
uniform, and it rules out a whole class of fixes — there is no "cap the worst offenders",
no giant-group or giant-polygon outlier to special-case. Combined with the per-query rewrites
already tested and found ineffective, the *only* lever on this query is how often it runs.

Against ~10 posts genuinely changing per minute, that means **roughly 95% of executions re-do
unchanged work**. Proposal A takes ~224/min down to ~12/min — an **~18× reduction**, or in DB-time
terms **2.99 concurrent threads → ~0.2**.

**The waste is not empty results.** 20 sampled window msgids all returned candidates (1-37 each).
The SQL returns the **outer-bound superset**; PHP then refines against routing labels
(`UnifiedDigestService.php:1003-1010`). The anti-join excludes only users in
`rippling_reach_notified` — those actually *mailed*. Candidates inside the outer bound but outside
the true reach are never notified, never recorded, and so are re-fetched and re-refined every pass,
with a routing call per pass evaluating every candidate point. Posts 17-53 minutes into the window
still carry 18-37 such candidates.

Two hypotheses tested and **rejected**:

- Collapsing the 31 per-row `JSON_EXTRACT` calls to 7 via nested `NO_MERGE` derived tables:
  2× faster on one msgid (0.10 s → 0.05 s), **no difference** on another (0.07 s vs 0.07 s).
  Output verified identical both times. Not the general fix.
- `MBRContains` short-circuit before `ST_Contains`: **no effect** (0.066 s vs 0.068 s, identical
  rows). MySQL already does the MBR pre-check internally.

Individual queries are not pathological — live msgid 121725017 (4 KB polygon, 217 vertices,
976 candidates) runs in **65 ms**. The volume is the problem.

### 2. Daily digest recipient scan — 17.9% of db2

```sql
select users.* from users
left join users_digests as ud_ord on ud_ord.userid = users.id and ud_ord.mode = 'daily'
where deleted is null and lastaccess is not null and lastaccess > ?
  and tnuserid is null and bouncing = 0
  and CRC32(users.id) % 8 = ?          -- function on the PK: non-sargable
  and exists (select 1 from memberships ...)
  and (JSON_EXTRACT(users.settings,'$.simplemail') IS NULL or ... != 'None')
  and not exists (select 1 from users_digests ...)
  and ud_ord.lastsent is null and users.id > ? order by users.id asc limit 500
```

- `EXPLAIN`: driving table `users` via **`tnuserid`**, **1,422,677 rows examined**, filtered 0.17%
- Eligible pool is only **103,931 users** — ~14× the whole pool examined per call
- Measured: **3.00 s cold, 1.63 s warm**, per call, × 8 shards, `->everyMinute()` 07:00-12:00 BST
- `users` has no usable index: no `bouncing`, no standalone `lastaccess` (only `added,lastaccess`,
  wrong leading column)

`routes/console.php:644-651` explains the 8 shards:

> *"each shard runs at only ~42% of a core — … a worker spends ~58% of its time waiting on the DB
> … extra shards mostly overlap DB latency for near-free, and the box sits ~60% idle with 4."*

That tuning measured the **batch host's** CPU, not the database's. "58% waiting on the DB" was the
signal that db2 was already the constraint. The same file records the symptom: *"The daily
population (~79k, ~tripled by rippling) can't fully clear in 4h at current throughput."*

### 3. Illustrations cleanup — 6.3% of db2 by day, **20.1% overnight**, returns nothing

`MessageIllustrationsService.php:34-44`, scheduled `->everyMinute()` (`routes/console.php:1227`).

- `EXPLAIN`: `ma_ai` → **type ALL, 33,224,660 rows, Using temporary**
- Measured: **15.93 s, 0 rows returned**
- ~960 s of DB time per hour to find nothing
- **Its share rises as everything else quietens, because the cost is fixed**: 4.9% at 13:27, 6.3%
  at the morning peak, 7.4% at 23:00, **13.1%** in the overnight floor, **20.1%** at 01:27 — by the
  small hours it is the single largest consumer on db2. Not "5-7% of a busy node": ~16 s of
  pointless full-table scanning every minute, for ever, on a table that keeps growing
- `messages_attachments` indexes are `PRIMARY(id)`, `incomingid(msgid)`, `hash`, `externaluid` —
  nothing serves `JSON_EXTRACT(externalmods,'$.ai')`

### 4. Active-user scan — 4.1% of db2

```sql
select id from users where deleted is null and lastaccess >= ? and added <= ?
```

- `EXPLAIN`: falls back to the **`deleted`** index (cardinality **15**), **1,422,686 rows
  examined**, filtered 16.66%
- Measured: **7.75 s / 8.29 s**, returning ~2,704 rows

Same root cause as finding 2 — no index on `lastaccess`. Both queries report the identical
1,422,686-row estimate.

**Caller identified: `NotificationExhortService.php:44-48`, run by `notifications:exhort`
`->everyMinute()`** (`routes/console.php:1143`). Its own comment sets out the design: *"The 90-day
per-user cooldown means running every minute over a 5-minute active window simply dedupes; matches
V1."* So the intent is deliberate — but the cost of deduping is a **1,422,686-row scan every
minute** to return 59 users at 01:45, or ~2,704 in daytime.

**The per-user `EXISTS` loop that follows is NOT a problem** — checked rather than assumed:
`users_notifications` has a `touser` index and the check plans as `type=ref, rows=1`. At 59-2,704
cheap indexed lookups a minute it is not worth touching.

So this finding and finding 2 share one fix: **proposal D's index**. Nothing about the exhort job's
logic needs changing; it just has no usable access path.

### 5. apiv2 read routing sends db1's API load to db2 — ~28% of db2

Found 2026-09-02 afternoon, when a production deploy restarted services on all three nodes and
haproxy failed over to its backup backends.

`MYSQL_HOST_READ` per node:

| node | reads from |
|---|---|
| db1 | **db2** (10.220.0.150) |
| db2 | **db2** (itself) |
| db3 | db3 (itself) |

So anything db1's apiv2 serves lands on db2 — the node with the least headroom — while db3, which
has 12 cores and is the only *active* haproxy backend, reads from itself.

Measured repeatedly through the evening — db1's share of db2's active queries:

| 16:57 | 17:27 | 18:57 | 19:27 | 19:57 | 21:11 | 22:41 |
|---|---|---|---|---|---|---|
| 27.8% | 27.8% | 22.6% | 31.6% | **48.8%** | 20.2% | **0.9%** |

**G is a daytime win only.** By 22:41 db1's contribution had collapsed to 3 samples in 321 — db2 is
nearly pure batch again overnight, as it was before the deploy exposed this. So G removes 23-49%
during waking hours and essentially nothing at night, whereas A helps in every hour reach is
running. If only one is done, A is the broader fix; G is the cheaper one and targets the daytime
peak, which is when db2 actually saturates.

Before the deploy db2 was **99.8% batch**, so all of this is new load. It persisted well past the
failover that first exposed it (haproxy's last state transition was 16:32:22).

**The share rises as batch falls, because db1's absolute contribution is roughly steady.** At 19:57
db1 held only **7 of 55** API connections (13%) yet produced **48.8%** of db2's active queries —
db3's reads go to db3, so db2 only ever sees db1's slice, and the queries in that slice are the
expensive ones. So proposal G removes a broadly constant absolute load that is worth **~23% of db2
when batch is heavy and ~49% when batch is light**, rather than a flat 28%.

The queries db1 sends are the expensive ones — `chat_rooms` unread counts (the single largest entry
in the digest table: 168,959 s cumulative, 85.7 ms average, 68% of executions using no index) and
spatial browse.

**See proposal G.** Also note the failure mode this exposed: a db3 outage puts the **whole** API
read load onto a db2 already saturated with batch — the fallback concentrates load on the weakest
node.

### 6. Jobs count — full scan of 1.3 M rows, 3.0% of db2

```sql
SELECT COUNT(*) FROM jobs WHERE visible = ? AND cpc >= ? AND geometry IS NOT NULL
```

Spotted in the digest table on the first pass (18,165 s cumulative, 832,709 rows examined per
execution) but absent from processlist sampling all day; it surfaced live at **3.0%** at 23:27.

| | |
|---|---|
| `EXPLAIN` | **type=ALL, key=NULL, 1,293,822 rows** |
| table | 1,316,545 rows — **up from 1,021,296 the same morning** (+295 k in 16 h) |
| rows matching | 260,514 (19.8%) |
| `visible = 1` | true for **every row** — the predicate contributes nothing |
| indexes | `bodyhash`, `canonical_title`, `city`, `geometry` (SPATIAL), `job_reference`, `PRIMARY`, `seenat` — **none on `cpc`** |

**Source: `iznik-spatial-go/dataset_jobs.go:252` — the spatial KNN server's index-freshness probe**,
running on db2 against `localhost`, beside a `SELECT MAX(seenat) FROM jobs` at line 242.
`jobsLiveFilter` (line 23) is `visible = 1 AND cpc >= %.2f AND geometry IS NOT NULL`.

So it is **neither batch nor apiv2**, and **no form of proposal G moves it** — G changes apiv2 read
targets; the spatial server reads its own node. Proposal H is the fix.

*(I classified this three times before getting it right: batch, then db1-apiv2, then db2's own
apiv2 — each on partial evidence. Client attribution said `localhost`; db2's local connections are
held by BOTH `iznik-server-go` and `iznik-spatial-go`, and I picked the wrong one. Only grepping the
literal SQL settled it. Lesson: "which service issues this" needs the query text found in that
service's source, not an inference from which processes hold connections.)*

Worth passing to whoever owns spatial: line 242 already probes `MAX(seenat)` for change detection,
so a full `COUNT(*)` over 1.3 M rows on the same tick may be redundant.

Its share **rises** as the night deepens — 6.5% at 01:57, 9.1% at 02:42 — while its absolute count
holds steady (917 then 941 samples as the total fell from 14,104 to 10,342). So it runs at a fixed
rate rather than in proportion to user traffic, which is worth knowing for whoever owns the jobs
feature.

**Proposal H (operator DDL): `ALTER TABLE jobs ADD INDEX cpc (cpc);`** — turns the full scan into a
range scan over the 19.8% that match `cpc >= ?`, roughly **5× fewer rows examined**, with
`geometry IS NOT NULL` applied as a filter afterwards. It **is** a db2 fix and G does not cover it — the spatial server reads its own node. Worth doing: the table grows ~300 k rows a day, so the scan degrades continuously
while an index does not.

### 7. Three high-frequency queries sampling under-weights — ~2% combined, but one is pure waste

The digest table flagged these on the first pass; processlist sampling never showed them, so they
were dropped. Re-checked at 23:42 — all three are **live**, `LAST_SEEN` to the second, with counts
grown during the day:

| query | executions | total s | rows examined/exec | rows sent/exec |
|---|---|---|---|---|
| `MAX(t.search)` chat search | 2,368,419 | 17,017 | 83 | 40.25 |
| **`volunteering` DISTINCTROW** | 826,573 | 14,402 | **15,898** | **0.00** |
| `messages_outcomes ⋈ messages_groups` | 184,769 | 23,420 | 34,782 | 1.00 |

**Magnitude, honestly: ~2% of db2's query time combined** (0.016 + 0.014 + 0.023 ≈ 0.05 threads
against the reach query's ~2.8). Not priorities. But two things are worth recording:

**Why sampling missed them, and when that matters.** Processlist sampling ranks by *time in flight*,
which is the correct metric for CPU — and by that measure these are correctly ranked as negligible.
But it systematically under-weights high-frequency short queries: `volunteering` at 17.4 ms is
almost never caught mid-flight, yet runs 826,573 times. **Use the digest table to catch
work-per-execution and total row examinations; use sampling to rank CPU.** Neither instrument alone
sees the whole picture — proposal H (the jobs count) was lost for a full day by trusting sampling
alone.

**`volunteering` returns nothing, ever — and the reason is that the feature is empty.** The query is
an anti-join for *ungrouped* ("nationwide") opportunities:

```sql
LEFT JOIN volunteering_groups g ON volunteering.id = g.volunteeringid
WHERE g.groupid IS NULL AND (applyby IS NULL OR applyby >= ?) AND (end IS NULL OR end >= ?)
  AND volunteering.deleted = ? AND expired = ? AND (pending = ? OR volunteering.userid = ?)
```

Peeling the predicates back shows exactly where it collapses:

| | rows |
|---|---|
| all volunteering opportunities | 15,599 |
| **ungrouped** | 245 |
| …and not deleted | **5** |
| …and not expired | **0** |
| …and not pending (the final answer) | **0** |

All five surviving ungrouped opportunities are expired, so the result is empty and has been for as
long as the counters cover: **826,573 executions × 15,898 rows = ~13 billion row examinations for
an average of 0.00 rows returned.**

Nothing is broken — the query is correct and the data set it looks for is simply empty. But it must
scan `volunteering` in full to discover that, every time, because no index supports
`deleted/expired/pending` and the anti-join forces the join to materialise. An index on
`volunteering (deleted, expired, pending)` would let it reject almost everything before the join.
At ~1.4% of db2 that is not a CPU priority; the more interesting question is a product one — whether
"nationwide volunteering opportunities" is meant to be an empty feature.

### 8. Rippling leave-check — the same over-generous lookback as finding 1, 4.8% of the floor

`ExpandService.php:2221`, run per `ripple:expand` tick (every minute). Already optimised once — the
code comment records "~3s vs the unbounded original's 80s+, which hung every tick" — but it kept a
**2-day** lookback for what the comment itself describes as "leaves since the last successful run
(seconds ago)… a generous safety margin".

| lookback | Group/Left records |
|---|---|
| **2 days (current)** | **1,195** |
| 6 hours | 62 |
| 1 hour | **1** |
| 10 minutes | **0** |

So the driving set is ~1,195 rows where a 30-minute window would carry 0-1. Each of those then
drives two nested `EXISTS` subqueries against `logs` (42.6 M rows) plus joins to `messages_groups`
and `messages`.

`EXPLAIN` also shows the access path is loose: `type=range key=timestamp_2 rows=202,860` — the index
is on `timestamp` alone, so it walks ~200 k entries of *all* log types across the 2 days to find the
1,195 that are Group/Left.

**Proposal I, two independent parts:**
1. **Narrow the lookback** to ~30 minutes (config or constant). Driving set 1,195 → ~0-1. Exactly
   the fix proposal A applies to the reach window, and the same reasoning: a per-minute job does not
   need a multi-day backstop.
2. **Operator DDL:** `ALTER TABLE logs ADD INDEX type_subtype_timestamp (type, subtype, timestamp);`
   — seek straight to Group/Left instead of scanning 202,860 timestamp entries. `logs` is 42.6 M
   rows, so this is a large index to add; part 1 alone may make it unnecessary.

Modest at 4.8% of the overnight floor, but it is the second instance of the same anti-pattern, which
suggests looking for others: a per-tick job with a lookback measured in days is worth checking
wherever it appears.

### 9. Audit: over-generous lookbacks on per-minute jobs

Having hit the pattern twice (findings 1 and 8), all **25** `everyMinute()` commands in
`routes/console.php` were audited rather than waiting to trip over a third.

**Most multi-day windows are legitimate and must not be touched** — they are business semantics, not
scan bounds: the 90-day activity threshold in `UnifiedDigestService:997` and
`MatchMailService:730`, the 7-day spam window in `ChatSpamService:41`, `COOLDOWN_DAYS` in
`NotificationExhortService:42`, `SUBJECT_REPEAT_WINDOW` in `ContentCheckService:1571`. Narrowing any
of these would change behaviour.

**The anti-pattern is specifically:** a per-minute job using a multi-day lookback to *find work that
arrives continuously*, with no watermark — so it re-processes the same backlog every tick.

**The codebase already contains the correct pattern.** `SendPendingWelcomeMailsCommand:102-107` uses
`id > $lastProcessedId` as the primary filter with `added >= $cutoffDate` as an explicit backstop —
its own comment says "backstop to avoid processing very old users". That is exactly what proposal A
recommends, already in production. Copy it.

**Three instances found:**

| job | schedule | lookback | re-fetched per tick | genuinely new |
|---|---|---|---|---|
| reach mail (finding 1) | everyMinute | 60 min | 435-754 posts | ~8/min |
| leave-check (finding 8) | everyMinute | 2 days | 1,195 log rows | ~1/hour |
| **mod2mod chaseup** | everyMinute | 2 days | **153 rooms** | **0 in the last hour** |

**Proposal J: `ChatChaseupModsService:33`** — `chat_rooms` joined to `chat_messages` on
`date >= now()->subDays(2)`, then a per-room loop. 153 rooms re-fetched every minute; 6 hours would
be 14, one hour 0.

**The loop body is the expensive part.** Per room, per minute it runs three queries, and the first
fetches **every message the room has ever had** — the code comment says so explicitly: *"Get ALL
messages for this room (not filtered by date)"* (`ChatChaseupModsService:52-57`), then a
`memberships` role lookup and a `groups` lookup.

Measured: those 153 rooms hold **16,730 messages**, all re-fetched every minute — ~24 million
message rows a day, plus ~306 extra per-room lookups a minute, to chase up conversations that
mostly have not changed. Narrow the window or add a watermark, per the welcome-mail precedent; the
"all messages ever" fetch could also stop at the first two distinct senders, since the loop only
needs to know whether exactly one person has posted.

*(Measuring this cost a mistake worth recording: a triple join across `chat_messages` (41 M rows)
ran 135 s on production before being killed. A client-side `timeout` kills the ssh, NOT the server
query — check `information_schema.processlist` and `KILL QUERY` explicitly.)*

### 10. Server configuration

- `sort_buffer_size = 256000000` (244 MB; default 256 KB), per-connection. **8.5 G of 10 G swap in
  use, 3.37 G of it mysqld's own pages.** Oversized sort buffers are also slower, not faster.
- `table_open_cache = 3177` — `Table_open_cache_overflows` **1,768,426** of 1,773,715 misses
  (99.7% evictions). Shows as 3-5% of samples in `Opening tables`.
- `innodb_flush_method = fsync` — double buffering; 14.7 G in page cache against a 6 G buffer pool
  on a 24 G box.
- `innodb_io_capacity = 200`, `innodb_adaptive_hash_index = OFF`.

## Coverage check (01:57, 14,104 samples)

Every item above 1% of db2's profile maps to a documented finding — there is no unexplained
consumer left to find:

| item | share | disposition |
|---|---|---|
| illustrations cleanup | 18.7% | proposal **B** |
| users lastaccess scan | 10.2% | proposal **D** |
| ripple expand scan | 10.1% | checked healthy |
| `chat_rooms` (db1 API) | 7.6% | proposal **G** |
| per-group newest-post probe | 7.4% | checked healthy |
| jobs count | 6.5% | proposal **H** |
| leave-check | 6.0% | proposal **I** |
| spatial browse (db1 API) | 4.0% | proposal **G** |
| reach recipient | 3.6% | proposal **A** (overnight; 46-68% by day) |
| `messages_outcomes` pair | 3.9% | apiv2/ModTools — covered by **G**; see note below |
| held-replies, users_emails, volunteering | 6.1% | documented |
| unmapped long tail | 19.7% | nothing above 3.89% |

**The `messages_outcomes` pair is apiv2, so proposal G removes it from db2.** The two queries are
`session.go:1498` (`SELECT COUNT(DISTINCT mo.id)`) and `groupWork.go:356`
(`SELECT mg.groupid, COUNT(DISTINCT mo.id) as count`) — identical `WHERE` clauses, the same 16 group
ids, the second being the first grouped by `groupid`. Both are Go, so they reach db2 only via db1's
apiv2 (db2's own apiv2 is a haproxy backup with negligible traffic; db3 reads itself). *Inferred
from the code location — I could not catch them in a client-attribution sample, as they fire on
ModTools page loads rather than continuously.*

Separately worth noting for whoever owns ModTools: **the total is derivable from the breakdown.**
`session.go`'s count is the sum of `groupWork.go`'s per-group counts, so one of the two scans is
redundant. That is a cross-cutting client+endpoint change rather than a db2 fix, and G moves the
cost off db2 either way.

*(Two cautions for anyone repeating this. Classifier patterns must match the literal SQL — Laravel
emits backticks, so `lastaccess >=` misses what `` `lastaccess` >= `` matches; a first pass here
reported 40% "unmapped" purely from that. And percentages are of the *current* profile: reach is
3.6% at 01:57 and 46-68% by day, so a single sample ranks the hour, not the system.)*

### 10. Orphan-log purge — 47% of db2 overnight, one chunk observed at 640 s

Appeared at 03:42 and immediately dominated the profile. `PurgeService.php:977-999`
(`purgeOrphanedUserLogs`), run by `purge:logs`.

```php
do {
    $logs = DB::select("SELECT logs.id FROM logs LEFT JOIN users ON users.id = logs.user
                        WHERE `timestamp` < ? AND logs.user IS NOT NULL AND users.id IS NULL
                        LIMIT {$this->chunkSize}", [$cutoff]);
    foreach ($logs as $log) { DB::delete("DELETE FROM logs WHERE id = ?", [$log->id]); }
} while (count($logs) > 0);
```

| | |
|---|---|
| `EXPLAIN` | `logs` type=range, key=**`user`**, **rows=10,902,401** |
| one chunk observed | **640 s** (`id=1262839`, still running) |
| total run time observed | **41 minutes and counting** |
| share of db2 at 03:42 | **47%** of 22,178 samples |
| **rows deleted in 60 s** | **7** |
| **rows read in 60 s** | **20,300,749** (338,345/s) |
| **read:delete ratio** | **~2,900,000 : 1** |

The last three lines are the whole finding: measured from `Innodb_rows_deleted` /
`Innodb_rows_read` deltas while the purge was the dominant consumer on an otherwise quiet node, it
reads **2.9 million rows for every row it deletes**.

*(`information_schema.tables.table_rows` for `logs` appeared to fall 42,637,297 → 21,773,086 over
the same period. That is InnoDB estimate noise, not deletions — at 7 deletes/minute it cannot have
removed 20 M rows. Do not use `table_rows` to measure purge progress.)*

**The per-row `DELETE` is correct and deliberate** — one row at a time is the Galera-safe pattern
and should stay.

**The re-scan is the defect.** There is no watermark, so every chunk re-runs the whole anti-join
from the start: 10.9 M index entries scanned to yield 1,000 ids, repeated until the table is clean.
The optimiser drives from `logs.user IS NOT NULL` on the `user` index, so the `timestamp` cutoff is
only a filter, never a seek.

**And it gets worse as it nears completion, not better.** `LIMIT 1000` bounds the *result*, not the
*scan* — MySQL keeps reading until it has filled the limit. Early on, orphans are dense and the
limit fills quickly; as they are deleted the scan must reach further for each one, and the final
chunk reads the entire remaining table to find zero rows and terminate. That is why a chunk was
observed at **640 s** and why the read:delete ratio reached 2.9 M:1 late in the run. (Confirmed
incidentally: after the purge finished, a plain `LIMIT 1000` probe of the same anti-join timed out
three times — there are too few orphans left for the limit to fill.)

The keyset watermark fixes exactly this: with `AND logs.id > ? ORDER BY logs.id`, each chunk starts
where the last ended, so total work is one pass regardless of how sparse the matches become.

**Proposal K: add a keyset watermark**, exactly the pattern
`SendPendingWelcomeMailsCommand:102-107` already uses:

```php
... AND logs.id > ?   ORDER BY logs.id   LIMIT {$this->chunkSize}
```
carrying the last id forward between iterations. Each chunk then resumes instead of rescanning,
turning an O(n²) sweep into a single pass. A composite index on `logs (user, timestamp)` would help
further but is a large index on a 42.6 M-row table — try the watermark first.

*(A caution for anyone measuring this: counting the outstanding orphans with the same anti-join is
itself a 10.9 M-row scan. I timed one out twice. The `EXPLAIN` gives you the shape without paying
for it.)*

**Audited the rest of `PurgeService` — 2 of its 12 chunked loops share the defect, the other 10 are
fine.** None of the twelve carries a watermark (`grep -c "id > ?"` = 0), but rescanning only hurts
where the driving scan is expensive:

| loop | plan per chunk | verdict |
|---|---|---|
| orphan logs (`:988`) | `logs` range on `user`, **10,902,401 rows** | **defect** |
| **empty chat rooms (`~:68`)** | `chat_rooms` range on `typelatest`, **3,811,850 rows** | **defect** |
| orphaned chat images (`~:99`) | `type=ref key=incomingid`, **rows=1** | fine |
| `reviewrejected = 1`, `arrival < cutoff`, … | indexed filters | fine — rows vanish as they are deleted, so the rescan is cheap |

So **proposal K applies to both anti-join loops**, not just the logs one. The empty-chat-rooms purge
runs under `purge:chats` (`dailyAt('02:00')`); orphan logs under `purge:logs` (`dailyAt('03:30')`).

### 11. Microvolunteering notify — an anti-join correlated by `LIKE CONCAT(...)`

`MicrovolunteeringNotifyService.php:69-89`, scheduled **`everyFiveMinutes()`**
(`routes/console.php:1133`). Caught only by the timestamped nightly capture; absent from every
daytime sample.

```sql
LEFT JOIN users_notifications
    ON users_notifications.timestamp >= DATE_SUB(NOW(), INTERVAL 1 DAY)
   AND users_notifications.url LIKE CONCAT('/microvolunteering/message/', messages.id)
   AND users_notifications.type = ?
WHERE ... AND users_notifications.id IS NULL
```

The only thing correlating `users_notifications` to the message is a **computed string match on
`url`**. The `LIKE` has no wildcard, so it is really an equality — but the pattern is built per row
and **`users_notifications` has no index on `url`** (`fromuser`, `newsfeedid`, `PRIMARY`, `touser`,
`touser_2(timestamp,seen,mailed)`, `userid(touser,id,seen)`).

`EXPLAIN`:

| table | type | key | rows |
|---|---|---|---|
| `groups` | ALL | NULL | 442 |
| `messages_groups` | ref | `groupid` | 8,014 |
| `messages` | eq_ref | PRIMARY | 1 |
| **`users_notifications`** | **range** | `touser_2` | **3,461** — scanned and string-matched per candidate message |

**Proposal L**, cheapest first:
1. `LIKE` with no wildcard is `=` — change it, then add a prefix index on `users_notifications (url)`
   so the correlation becomes a ref lookup instead of a 3,461-row scan per message.
2. Better structurally: the url is carrying a foreign key as text. A `msgid` column on
   `users_notifications` would make this an ordinary indexed anti-join — larger change, worth
   considering if this recurs elsewhere.

Magnitude is modest — ~5% of a quiet node — but it is a clear structural defect on a five-minutely
job, and it demonstrates the value of the timestamped capture: a burst-shaped job that no
90-second spot sample caught in 22 hours.

## The fixed-cost class — B and H are a third of db2 at its quietest

Two items do **identical work whether or not there is anything to do**, so their share rises as
everything else falls away. At 04:57, with the node otherwise idle (load 1.40):

| item | 13:27 | 23:00 | 01:27 | **04:57** |
|---|---|---|---|---|
| illustrations cleanup (B) | 4.9% | 7.4% | 20.1% | **22.0%** |
| jobs count (H) | — | — | 6.5% | **10.3%** |
| **combined** | | | | **32.3%** |

Neither responds to load: B scans 33.2 M rows every minute and returns nothing; H counts 1.3 M rows
on a fixed KNN-freshness tick. Together they are a third of db2 at the hour when it should be doing
least.

That is an argument for doing them early. They are also the two cheapest fixes in the plan — a
watermark on B (verified: 39,668,257 rows → 13,644, 15.93 s → 0.072 s) and one index for H — and
neither has any product tradeoff to weigh.

## The nightly window — what daytime monitoring misses

`purge:logs` reached 47% of db2 and was invisible in every sample taken between 07:00 and 03:30.
There are **28 jobs scheduled between 00:30 and 06:30**, none of which a daytime profile sees:

```
00:30 logs:rotate            02:30 purge:messages           03:30 purge:logs        ← finding 10
01:00 locations:remap-postcodes  02:30 stats:generate-daily  03:45 users:update-lastaccess
01:00 messages:deindex       02:40 browse:backfill-max-distance  04:00 mail:spool:process
02:00 groups:update-stats    02:45 ai:usage-counts:update    04:30 chats:update-expected
02:00 purge:chats  ← K       02:50 mail:reengage-outcomes    04:30 emails:validate
                             03:00 cleanup:sessions          04:45 users:update-approx-locs
                             03:00 locations:update-postcodes 05:00 integrations:sync-whatjobs
                             03:00 messages:process-expired  05:00 locations:fix-skewed
                             03:00 users:update-engagement   05:00 users:remap-locations
                             03:23 discourse:not-signed-up   05:10 electricals:stats
                                                             06:00 users:cleanup
                                                             06:20 monitor:deprecated-endpoints
                                                             06:30 users:fix-tn-names
```

Of these, only `purge:logs` (03:30) and `purge:chats` (02:00) have been examined — both carry the
rescan defect (proposal K). `purge:messages` (02:30) is in the same service and its loops use
indexed `arrival < cutoff` filters, so it is among the ten that are fine.

**The remaining 25 are unexamined.** Anything on this list could be doing what `purge:logs` does and
would never appear in a daytime profile.

**Sampled one more nightly hour (04:59-05:01) and it was different again — 74.5% of 46,479 samples
were queries not seen at any other point in 22 hours of monitoring:**

| share | client | query | job |
|---|---|---|---|
| **18.6%** | batch | `messages_groups.msgid, groupid, …` | not yet traced |
| **9.6%** | batch | `users_searches s LEFT JOIN users_se…` | `embeddings:searches` |
| 7.1% | batch | `messages.id FROM messages INNER JOIN messages_groups` | not yet traced |
| **5.9%** | **`bulk2-internal`** | `messages_spamham.spamham, messages.message` | not yet traced |
| 5.0% | batch | `DISTINCT messages.id, lat, lng, …` | not yet traced |

Two things follow. **`bulk2-internal` is a client of db2 that appears nowhere in the daytime
profile** — the estate reaching db2 is wider than the batch/apiv2/spatial split established earlier.
And `chats:update-counts` and `embeddings:searches` were both running, neither examined.

This is one 90-second sample of one nightly hour. It is enough to say the nightly window is not a
long tail of small jobs — it is a second workload of comparable size to the daytime one, and it has
had roughly 1% of the scrutiny.

## Attribution: every item verified against its source

After misclassifying the jobs count three times, every profile item was re-checked by locating its
literal SQL in the issuing service's source rather than inferring from client host or process list:

| item | source | issuer |
|---|---|---|
| reach recipient (A) | `UnifiedDigestService.php:973` | batch |
| illustrations (B) | `MessageIllustrationsService.php:34` | batch |
| users lastaccess scan (D) | `NotificationExhortService.php:44` + daily digest | batch |
| leave-check (I) | `ExpandService.php:2221` | batch |
| mod2mod chaseup (J) | `ChatChaseupModsService.php:33` | batch |
| `chat_rooms` unread count (G) | `iznik-server-go/chat/chatroom.go:1170` | **apiv2** |
| spatial browse (G) | `iznik-server-go/isochrone/message.go`, `message/postmatches.go` | **apiv2** |
| `messages_outcomes` pair (G) | `session.go:1498`, `groupWork.go:356` | **apiv2** |
| jobs count (H) | `iznik-spatial-go/dataset_jobs.go:252` | **spatial KNN** |

This matters because the fix differs by issuer: batch items need code or config changes, apiv2 items
are relocated wholesale by G, and the spatial item is moved by neither.

## Checked and found healthy — not targets

- **Ripple expand candidate scan** (`ripple:expand`, ~507 groups per pass): `range` on
  `groupid(groupid,collection,deleted,arrival)`, "Using index", **0.01 s** per call. It appears in
  samples because it runs once per group, not because it is slow.

- **Per-group newest-post probe** (`select mg.arrival, mg.msgid … order by mg.arrival desc limit 1`),
  9.0% of the overnight floor: `EXPLAIN` gives `mg` type=**range**, key=`groupid`, **rows=2**,
  "Backward index scan", with `messages` by PRIMARY eq_ref; **6-9 ms** per call. Well indexed. Like
  the expand scan, its share is frequency — once per group across 507 groups — not cost.

- **The 22.8% long tail** — nothing exceeds 3.9%, and the supporting indexes all exist:

  | query | index needed | present? |
  |---|---|---|
  | reachable-groups `ST_Intersects(g.polyindex, …)` | `polyindex` SPATIAL | yes — `groups` is 783 rows |
  | digest post selection, `chat_messages` on `refmsgid` | `msgid(refmsgid)` | yes (41 M rows, 5.9 GB) |
  | same, `messages_likes` subqueries | `msgid(msgid,type)`, `msgid_2(msgid,userid,type)` | yes (92 M rows, 7.6 GB) |

  The digest post-selection query carries **5 correlated subqueries per row**, so its cost is
  volume × rows rather than a missing access path. No further low-hanging fruit below the items
  above.

## Proposed changes

### A. Reach mail — trigger on either side changing

**Design decision (2026-09-02): a plain watermark on `rippling_reach.updated_at` is rejected.**
It would stop catching members who become eligible *after* a post's reach last changed — someone
joining the group, moving, or returning after a long absence. Picking those people up is wanted
behaviour, not an accident of the 60-minute window.

Instead, treat **both sides as change feeds** and evaluate a (post, member) pair when either moves:

1. **Post side** — posts whose reach changed since the last pass. Watermark on
   `rippling_reach.updated_at`, with a small overlap for tick safety. Evaluate all their
   candidates, exactly as now. Replaces the 60-minute look-back: 435 posts/pass → ~14.

2. **Member side** — members who newly became eligible since the last pass. Evaluate *those
   members* against the live posts for their groups, rather than re-sweeping every post.

The member-side feed is small enough to make this cheap — measured:

| signal | 1 min | 5 min | 60 min |
|---|---|---|---|
| `memberships.added` | **1** | 7 | 364 |
| `users.lastupdated` | **0** | 2 | 29 |

So roughly **1-2 member changes per minute**, against 435 posts currently re-scanned per minute.
Indexes exist for both feeds: `memberships.added_groupid (added, groupid)` and `users.lastupdated`.

**The member-side feed was investigated and mostly does not exist. Revised recommendation below.**

| signal | covers | granularity | volume |
|---|---|---|---|
| `memberships.added` | new joins | real-time, indexed (`added_groupid`) | ~1/min |
| `users_approxlocs.timestamp` | location changes | **daily** — `users:update-approx-locs` is `dailyAt('04:45')` (`routes/console.php:1105`) | 4,562/day, 0 in any given hour |
| `users.lastupdated` | **nothing useful** | — | plain `timestamp NULL`, **no `ON UPDATE`**; set only in user-merge flows (`User.php:1449`, `user.go:1792`) |
| returning after 90 days idle | **no signal at all** | — | `lastaccess > watermark` matches every active member, thousands/min |

So a dual-feed design would keep new joins (real-time, better than today), **delay location changes
to once a day** (worse than today's ≤1 h), and **lose returning members entirely**. Two of the three
eligibility routes regress.

**Revised proposal A: shorten the window rather than replace it.** `reach_mail_window_minutes`
60 → 5:

| window | posts per pass, 07:45 | posts per pass, 17:57 |
|---|---|---|
| 1 min | 10 | 8 |
| 3 min | 14 | 23 |
| **5 min** | **27** | **43** |
| 10 min | — | 92 |
| **60 min (today)** | **435** | **754** |

Measured twice, ten hours apart, so the saving is not an artefact of one moment: the 60/5 ratio is
**16.1× in the morning and 17.5× in the evening**. Note the absolute window has grown from 435 to
754 posts — evening reach activity is higher, so this query's load is worse later in the day than
the morning figures elsewhere in this document suggest.

- **~5.6× fewer executions** — 225/min down to roughly 40/min. **Not 16×**: an earlier draft
  divided the window sizes (754/43), but that is the per-*pass* count, and a pass does not take one
  minute. See the correction below.
- **No eligibility route is lost.** Every signal the 60-minute window catches is still caught, just
  within 5 minutes instead of 60 — including returning members, for which no event feed exists
- One config value, no new tables, no new feeds, no DDL, and it composes with a later dual-feed
  design if a real "member changed" signal is ever added

The 5-minute window keeps 5× headroom over the 1-minute cron, which is what the current window's
comment says it is there for.

### Correction: the shards are capacity-limited, so the saving is ~5.6×, not ~16×

Measured twice, four hours apart:

| | 13:57 | 18:12 |
|---|---|---|
| window size | 435 posts | **754 posts** |
| concurrency | 2.99 in flight | 2.64 |
| **throughput** | **254/min** | **225/min** |
| mean duration | 0.71 s | 0.70 s |

The window grew 73% while throughput *fell*. The four reach shards are saturated at ~225-254
executions/min, so the window does not set the execution rate — it sets how long a sweep takes.

**Saturation confirmed directly** (18:27): all four shard logs read `Already running, exiting.`,
i.e. every one-minute cron tick bounces off the previous pass's flock, and the four live workers
were aged 127 s, 127 s, 67 s, 67 s. The same was true at 07:50, so this is the steady state, not a
peak-hour effect. `routes/console.php:821` says "Each invocation drains the whole window in one
pass, so no --max-iterations" — in practice a pass cannot drain the window inside a minute, so the
shards run back-to-back continuously.

That is why this query holds 46-68% of db2 at every hour sampled: while reach is active its load is
a steady ~2.6-3.0 threads rather than something that ebbs with traffic.

**Overnight it is reduced, not stopped — and the first attempt to measure this was wrong.**

A `GROUP BY HOUR(updated_at)` appeared to show zero rows for hours 23-05, which read as a hard
overnight pause. **That was an artefact.** `updated_at` holds *current* state, not an event log: a
row touched overnight is touched again in the morning and moves to the later hour, erasing the
overnight evidence. The same query run an hour apart gave hour 16 as 758 then 567, and hour 17 as
766 then 677 — history rewriting itself as rows are re-updated.

The sound measurement is `next_expansion_at`, which is scheduled forward and cannot be rewritten by
later activity:

| hour UTC | 20 | 21 | 22 | 23 | 00 | 01 | **02** | **03** | **04** | 05 | 06 | 07 | 08 | 11 | 13 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| scheduled expansions | 237 | 417 | 394 | 199 | 130 | 119 | **47** | **6** | **20** | 73 | 109 | 221 | 332 | 407 | 407 |

So there is a **deep overnight trough, not a pause**. Reach never stops.

**Caveat on the numbers above — `next_expansion_at` is a rolling snapshot too.** It is
forward-looking and cannot be rewritten, but it *fills in*: posts advance and get rescheduled, so a
future hour's count grows as that hour approaches. Measured from a 20:26 vantage, hour 03 held 6 and
hour 04 held 20; from 02:27 the same hours held **118** and **158**. Do not compute ratios from a
single snapshot of it.

**Use observed arrivals instead** — those are direct: **~8/min by day, 0-2/min overnight**, with the
reach-mail window collapsing from ~750 to **4**. That is the ~15-25% of daytime that matters, and it
is what the load actually follows: mysqld bottomed at **100%** at 02:27, the lowest reading of the
whole monitoring period.

Proposal A's saving therefore applies essentially round the clock, at reduced volume for ~6 hours
overnight. An earlier draft downgraded the daily total to ~70% of a 24/7 assumption on the strength
of the artefact; ~85-90% is closer.

**Saturation is a cost problem, not a correctness one — checked.** A post only goes unmailed if a
sweep outlasts the 60-minute window. Window size measured at 435 (07:45), 754 (17:57) and 730
(18:42), so it plateaus rather than growing; at 730 posts and ~225/min the sweep is **3.2 minutes**,
against the 60 minutes a post spends in the window — roughly **18× headroom**. Posts would have to
reach ~13,500 in the window before any aged out. Nothing is being dropped today.
754 posts at 225/min is a **3.35-minute sweep**, which independently matches the directly measured
repeat rate of 2.96 executions per post per 10 minutes.

The correct arithmetic for a 5-minute window: posts enter at ~8/min (the 1-minute window count) and
each is re-checked once per 1-minute tick while it remains in the window, so demand is
**8 × 5 ≈ 40/min** — comfortably under the ~225/min the shards can serve, so that becomes the
actual rate.

| | today | 5-min window |
|---|---|---|
| executions/min | 225 | **~40** |
| DB time (threads in flight) | 2.64 | **~0.5** |
| re-checks per post | ~18 | **5** |

**~5.6× fewer executions, ~81% off this query's DB time.** Still the single largest lever on db2,
but three times smaller than the earlier draft claimed. A 2-minute window would give ~16/min
(~14×) with correspondingly less tick-overlap headroom.

**The model was then confirmed by a natural experiment**, not just asserted. Between 18:12 and
21:45 the window fell from 754 to 459 (-39%) on its own as evening reach activity declined —
and throughput did not move:

| | window | throughput | in flight | duration |
|---|---|---|---|---|
| 13:57 | ~435 | 254/min | 2.99 | 0.71 s |
| 18:12 | 754 | 225/min | 2.64 | 0.70 s |
| 19:42 | ~700 | 266/min | 2.73 | 0.62 s |
| **21:45** | **459** | **224/min** | 2.25 | 0.60 s |

Exactly what a capacity-limited system predicts: at 7 arrivals/min a 60-minute window still demands
7 × 60 = 420/min against ~225/min of shard capacity, so the shards stay pinned and a smaller window
changes nothing. **Shrinking the window only pays once `arrivals × window_minutes` drops below
capacity** — at 5 minutes that is 7 × 5 = 35/min, comfortably under, so throughput becomes ~35/min.

**That formula is the STEADY STATE, and the window lags arrivals by up to a full window period.**
Observed 23:12: arrivals had collapsed to **0.5/min** — far below the 3.75/min that `arrivals × 60
< 225` implies — yet throughput was still **198/min** with 2 of 4 shards self-bouncing, because the
window still held **339** posts banked from the earlier, busier hour. Posts leave the window only by
ageing out 60 minutes after their last update, so what the shards actually process is
`window_size / sweep_time`, and `window_size` only converges on `arrivals × window_minutes` after a
full window has elapsed.

Two consequences. First, an arrival-rate threshold predicts the *eventual* state, not the current
one — do not expect load to track arrivals minute by minute. Second, and more usefully: because
window size is proportional to the window setting, **cutting the window from 60 to 5 minutes shrinks
the backlog within one window period rather than gradually** — the benefit lands in about five
minutes, not over an hour.

This also makes the saving arrival-rate dependent: ~6.4× at 7 arrivals/min, ~4.5× at 10. The
~5.6× quoted above sits in the middle of the observed range.

**And the crossover itself was then observed live, unprompted.** As overnight arrivals fell to
5/min the system crossed out of saturation on its own:

| | 18:27 (saturated) | 22:12 (crossing) |
|---|---|---|
| shard logs | **all four** `Already running, exiting.` | **three of four** `Sending unified digests…` (starting fresh) |
| worker ages | 127 s, 127 s, 67 s, 67 s | all **32 s** — inside the 60 s tick |
| throughput | 225/min | **180/min** — first reading below the plateau |
| in flight | 2.64 | **1.66** |
| duration | 0.70 s | 0.55 s |

That is precisely the mechanism proposal A exploits, demonstrating itself without any change from
us: once demand falls below shard capacity the passes complete inside their interval, throughput
tracks demand rather than capacity, and the DB load falls with it.

**THE FULL CURVE, observed overnight without changing anything.** By 00:12 the window had drained to
**4 posts** and the reach query went with it:

| window | throughput | in flight |
|---|---|---|
| 754 (evening peak) | 225/min | 2.64 |
| 459 | 224/min | 2.25 |
| 245 | 251/min | 2.39 |
| **4** | **3/min** | **0.01** |

**2.8 threads → 0.01.** Same query, same code, same hardware; only the window changed. Throughput
is pinned at ~225-266/min by shard capacity while the window is large, and tracks the window
one-for-one once below it. That is the entire basis of proposal A, demonstrated across the full
range by the system itself.

**And the floor it leaves**, sampled at 00:14 with reach effectively absent — total active queries
down from ~44,000 per 70 s sample at peak to **11,123**, about a quarter of the busy level:

| | share of the floor |
|---|---|
| **illustrations cleanup (proposal B)** | **13.1%** |
| `messages.*` variant | 13.0% |
| `messages_groups ⋈ messages` | 9.0% |
| **users lastaccess scan (proposal D)** | **8.2%** |
| `chat_rooms`, db1 API (proposal G) | 5.3% |
| `logs ⋈ messages_groups` | 4.8% |

Note this is the *overnight* floor with the window at 4. A 5-minute window in daytime traffic
(~8 arrivals/min) gives a window of ~40 and throughput ~40/min — in-flight ~0.4 threads rather than
0.01, against today's 2.8. So expect reach to fall to roughly **15% of its current DB time**, not
to vanish. B, D and G are what dominate whatever remains.

**The earlier crossover was transient — 15 minutes later it had reverted.** At 22:27 arrivals had recovered
from 5/min to 8.2/min (demand ~492/min), throughput was back to **247/min**, in flight 2.28, and
only 1 of 4 shards was still starting fresh. So this was a brief dip below capacity, not the
overnight transition settling in. The mechanism is demonstrated; the state is not yet sustained.

That is the point of proposal A rather than an argument against it: left alone, the system sits
below capacity only during accidental troughs, and reverts as soon as arrivals tick up. A 5-minute
window puts it below capacity **by construction**, at every hour, instead of waiting for traffic to
oblige.

Expected effect: removes most of the 47-51% of db2 that this query represents, while preserving
the late-joiner behaviour the current window provides.

### B. Illustrations cleanup — drive from new attachments

Zero product tradeoff; the current query returns 0 rows, so the historical backlog is already
clear.

```sql
SELECT DISTINCT ma_ai.id, ma_ai.msgid
FROM messages_attachments ma_real
JOIN messages_attachments ma_ai ON ma_ai.msgid = ma_real.msgid
WHERE ma_real.id > :watermark          -- PRIMARY key range scan, not a full scan
  AND (ma_real.externalmods IS NULL
       OR JSON_EXTRACT(ma_real.externalmods,'$.ai') IS NULL
       OR JSON_EXTRACT(ma_real.externalmods,'$.ai') = FALSE)
  AND JSON_EXTRACT(ma_ai.externalmods,'$.ai') = TRUE
```

Store `:watermark` (max `messages_attachments.id` seen) in cache between runs.

**Verified on db2 by `EXPLAIN` and by running it** (unlike proposal C, which failed this test):

| | driving table | type | key | rows examined |
|---|---|---|---|---|
| current | `ma_ai` | **ALL** | NULL | **39,668,257** |
| proposed | `ma_real` | **range** | **PRIMARY** | **13,644** |

then `ma_ai` by `incomingid` ref, 7 rows. **2,900× fewer rows examined.**

Timed with a 20,000-id watermark: **0.072 s, 0 rows** — same answer as the current form's
**15.93 s**, and the 0.072 s was measured on db2 *under load* while the 15.93 s was on an idle
node, so the real gap is wider still.

### C. Daily digest — sargable shard predicate — **TESTED, DOES NOT WORK AS WRITTEN**

An earlier draft proposed replacing `CRC32(users.id) % 8 = :shard` with an equal-width PK range
per shard, claiming each shard would then walk an eighth of the table. `EXPLAIN` on db2 says
otherwise, in two separate ways:

**1. The optimizer ignores the range.** Plans are identical with and without it:

| variant | type | key | rows | filtered |
|---|---|---|---|---|
| `CRC32(users.id) % 8 = 0` | ref | `tnuserid` | 1,386,104 | 0.17% |
| `users.id BETWEEN 15 AND 5636502` | ref | `tnuserid` | 1,386,104 | 0.14% |

It prefers the `tnuserid IS NULL` ref lookup over a PK range and keeps walking the same rows.

**2. Even forced onto the PK, equal id-widths give wildly uneven shards.** With
`FORCE INDEX (PRIMARY)` the plan becomes `type=range key=PRIMARY rows=1,192,136` — only ~1.6×
better, not 8×, because user ids are not uniformly distributed:

| | rows |
|---|---|
| eligible pool the query actually serves | **104,075** |
| `tnuserid IS NULL` rows (what it walks today) | 1,845,185 |
| shard 0's equal-width id range (15 - 5,636,502) | **850,131** — 30% of users, not 12.5% |

To make this approach work at all you would need **quantile-based** shard boundaries (real id
percentiles, not equal widths) *and* an index hint to stop the optimizer reverting to `tnuserid`.
That is a lot of machinery for ~1.6×.

**Do D instead.** The index gives a selective path straight to the 104,075-row pool without hints,
shard rework, or uneven partitions, and it fixes the active-user scan at the same time. Keep
`CRC32` sharding as it is.

### D. Operator-only: index on `users (deleted, lastaccess)`

**Schema change on Galera — for the operator to apply, not from CI and not from Claude.**

```sql
ALTER TABLE users ADD INDEX deleted_lastaccess (deleted, lastaccess);
```

`deleted IS NULL` is the leading equality (MySQL indexes NULLs), `lastaccess` the range. Serves
**two** hot queries, both currently examining the same 1,422,686 rows:

| query | current access path | share of db2 |
|---|---|---|
| daily digest scan (finding 2) | `tnuserid` index, filtered 0.17% | 17.9% |
| active-user scan (finding 4) | `deleted` index (cardinality 15), filtered 16.66% | 4.1% |

`users` is 2.85 M rows. **This is now the only working fix for the daily digest scan**, since C was
tested and does not change the plan.

**Measured selectivity** (an earlier draft estimated 13× from the final pool size; the accurate
figure is 10×, because the index applies only `deleted` and `lastaccess` — `tnuserid` and
`bouncing` remain post-filters):

| | rows |
|---|---|
| walked today via `tnuserid` | **1,845,193** |
| what `(deleted, lastaccess)` would return | **184,832** |
| final pool after `tnuserid`/`bouncing` filters | 104,082 |

So **10.0× fewer rows examined**, with no hints, no shard rework and no uneven partitions.

A four-column `(deleted, bouncing, tnuserid, lastaccess)` would reach the 104,082 pool exactly
(~17.7×), but with `lastaccess` in the fourth position it would **not** serve the active-user scan
in finding 4, which constrains only `deleted` and `lastaccess`. One two-column index serving both
queries is the better trade on a 2.85 M-row table in a Galera cluster, where every extra index also
costs write overhead on all three nodes.

### E. Reduce digest worker concurrency

20 workers against 8 cores, with the shard counts tuned by watching the batch host's idle CPU
rather than the database's. With A, C and D landed the per-query cost drops sharply, so the shard
counts should be re-derived from db2's CPU — not raised further on the "embarrassingly parallel"
reasoning in `routes/console.php:644-651`, which measured the wrong box.

### G. Point db1's AND db2's apiv2 reads at db3

One line in `/var/www/iznik-server-go/.env` on **db1 and db2**:

```sh
export MYSQL_HOST_READ=10.220.0.47   # db3, matching what db3 already does for itself
```

then `monit restart iznik-server-go` on each — no rebuild needed, monit's start program sources
`./.env`.

**Widened 2026-09-03 after client attribution.** The original form changed db1 only. But db2's own
apiv2 also reads db2, and it is not idle: the jobs count (finding 6) arrives entirely from
`localhost`, and local database connections on db2 are held by `iznik-server-go`. Measured at 02:57,
apiv2 traffic on db2 totalled ~**32%** — `chat_rooms` 10.4%, `messages_outcomes` 6.5%, spatial
browse 6.0%, plus ~9.8% of the mixed long tail. (An earlier draft said 39% by wrongly counting the
6.8% jobs count as apiv2; it is the spatial server's — see finding 6.) Pointing **both** nodes' apiv2 at db3
leaves db2 serving batch only, which is what it was before this investigation started
(99.8% batch). Verify by the boot line `Connecting to database ...` and by db2's client mix losing its
`db1-internal` share.

Takes ~28% off db2 immediately (finding 5). **This is not the "use db1 as a read target" idea that
was ruled out** — it sends no new traffic to db1; it stops db2 absorbing traffic db1 generates, and
moves it to the 12-core node that already serves the active API backend.

**db3's headroom verified before proposing this** (17:29), rather than assumed:

| | db2 (current read target) | db3 (proposed) |
|---|---|---|
| cores | 8 | **12** |
| CPU idle | ~5-25% | **80.2%** |
| mysqld | 573% of 800% | **137.5% of 1200%** |
| RAM | 24 GB | **36 GB** |
| innodb buffer pool | 6 GB | **16 GB** |
| swap in use | 8.7 GB | 3.95 GB |
| active queries | ~9 | **2** |

db3 is the write node, so this does add read load to the node taking writes — but it has 1.5× the
cores, 2.7× the buffer pool and is 80% idle, while db2 is the saturated one. The bigger buffer pool
also suits these queries: `chat_rooms` is 6.6 M rows and the browse path touches `messages_spatial`.

db1's share was measured **twice, 30 minutes apart, at 27.8% both times** (63,859 and 44,808
observations), so this is db2's steady state and not a deploy artefact — my first reading of it was
during a failover and I wrongly predicted it would drain.

### F. my.cnf

**Swap is not causing the slowness — checked, and it is a negative result.** It would have been
the tidy explanation for the 65 ms → 0.71 s gap, but `vmstat` over 10 s shows swap-in of
**23, 0, 2, 2, 0 pages/s** and iowait of 5, 0, 1, 1, 1% while `us` sits at 57-77%. mysqld's
3.47 GB of swapped-out pages are cold and staying out. db2 is CPU-saturated, not thrashing.

So treat F as memory hygiene and headroom, **not** a latency fix — the latency lever is cutting the
execution count (proposal A), not tuning memory.

mysqld's **371 M cumulative major faults** look alarming but are a 12-day accumulation, not a live
problem: measured over 60 s, mysqld's `maj_flt` rose 2,617 (**43/s**) while system-wide
`pgmajfault` rose only **71** and `pswpin` only **16**. There is no disk-backed memory pressure
happening now. Thread closed — do not re-open it on the cumulative figure alone.

- `sort_buffer_size`: 256 MB → 2 MB. Reclaims most of the 3.47 G mysqld has swapped out.
- `table_open_cache`: 3177 → 8000+, to stop the 1.77 M overflow evictions.
- Consider `innodb_flush_method = O_DIRECT` (stops double buffering) and raising
  `innodb_io_capacity` from 200 if the volume is SSD-backed. Both need a restart, so they belong in
  a planned maintenance window.

## Post-deploy re-baseline (2026-09-02 16:57)

A production deploy that afternoon rebuilt apiv2 and routing on all three nodes (31 non-test files
in `iznik-routing-go`). Routing is what the reach digest calls to refine candidates, so the profile
was re-measured rather than assumed unchanged:

| batch-only profile | pre-deploy 13:27 | post-deploy 16:57 |
|---|---|---|
| **reach recipient query** | **68.0%** | **67.2%** |
| illustrations cleanup | 4.9% | 5.9% |
| `messages.*` variant | 3.7% | 3.4% |
| users lastaccess scan | 2.2% | 3.3% |

Unchanged within sampling noise — every measurement below still holds.

## Expected outcome

A + B + D address **75.3%** of measured load (C was tested and dropped), and G removes a further
~28% that arrives from db1. There is no spread-the-load option to fall back on, so the halving has
to come from them.

**A alone clears the target**: the reach recipient query is 47% of db2 at peak and 68% off-peak,
runs 224×/min at 0.71 s for 2.99 threads continuously, and ~95% of those executions re-do unchanged
work. Everything else is secondary to it.
