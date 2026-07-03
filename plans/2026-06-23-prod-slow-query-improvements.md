# Prod slow-query improvements (db3) — 2026-06-23

Source: `performance_schema.events_statements_summary_by_digest` on **db3** (the Synced node;
db1 down, db2 SST-donor during capture). Digest counters reset at the mysqld restart during
today's cluster incident, so figures are "since ~20:1x today" — a short but representative live
window. All analysis read-only (`EXPLAIN`/`EXPLAIN ANALYZE`/`SELECT` — never wrote to the
recovering Galera cluster). EXPLAINs run on db3 against live data.

## Top offenders (db3, by total time)

| # | Query (normalized) | total | avg | calls | no-index | rows/call |
|---|---|---|---|---|---|---|
| 1 | `COUNT(DISTINCT messages_spatial.msgid)` Nearby/browse count w/ isochrone join | **538s** | 2069ms | 260 | all | 56k |
| 2 | `chat_rooms` list w/ correlated unseen/replyexpected subqueries | **160s** | 186ms | 862 | 642 | — |
| 3 | Nearby/browse **fetch** (`ST_Y/ST_X(point)` UNION messages) | **83s** | 768ms | 108 | all | 18k |
| 4 | `DISTINCT chatid FROM users_expected … TIMESTAMPDIFF(date,lastaccess)` | 30.6s | 15ms | 2032 | 0 | — |
| 5 | `COUNT(*) expectedreply FROM users_expected … TIMESTAMPDIFF` | 30.5s | 14ms | 2115 | 0 | — |
| 6 | `COUNT(DISTINCT messages_by.msgid) collected …` | 26.5s | 12ms | 2115 | 0 | — |
| — | embedding query (`subject_embedding, body_embedding`) | 7.6s | **3811ms** | 2 | all | 109k |

---

## TL;DR — highest-value fixes (all proven below)

1. **#1 (538s): remove the no-op `ST_SRID(point, 3857)` wrapper in the isochrone count.**
   The `messages_spatial.point` column is *already* SRID 3857, so wrapping it makes the column
   non-sargable and **defeats the SPATIAL index** → full scan of all 55,502 rows on every call.
   Proven ~**25× faster** (867ms→34ms) on isochrone 304 just by un-wrapping. Pure Go one-liner.
   File: `iznik-server-go/isochrone/message.go:243`.
2. **#1 (538s): the rippling `NOT EXISTS` runs on every browse-count even though rippling is
   globally OFF in prod.** It is gated on table-existence only, not on `rippleEnabled()`. It also
   forces the optimizer to MATERIALIZE the subquery and pick the full-scan plan. Gate it behind
   `rippleEnabled()` so it disappears entirely while the feature is dark. File:
   `iznik-server-go/isochrone/message.go:211-220`.
3. **#4/#5 (61s, 4147 calls): the `users_expected` TIMESTAMPDIFF loop** does one PK lookup into
   `chat_messages` per history row (up to 764 for the worst expectee → 245ms). No index fixes the
   cross-column `TIMESTAMPDIFF`; the lever is calling it far less often / batching. Lower priority.
4. **#2 (160s): NOT a missing index.** Both correlated subqueries are already well-served (the
   `date >= unseenSince` window keeps them to a tight `chatid_2`/`chatmax` range — proven on a
   7,784-message chat: range scan, ~0). The 186ms avg is the *fan-out* of the outer query (many
   LEFT JOINs + per-row `(SELECT … users_images … ORDER BY id DESC LIMIT 1)` subqueries) × 862
   calls. A `(chatid,replyexpected,replyreceived,date)` index would NOT change the plan materially.

---

## #1 — Nearby/browse COUNT (538s, the biggest cost)

Source: `iznik-server-go/isochrone/message.go` → `isochroneCount()` (lines 200-261), one goroutine
per isochrone. The exact statement is built at line 241-249:

```go
"INNER JOIN isochrones ON ST_Contains(isochrones.polygon, ST_SRID(point, ?)) "  // ← line 243
...
reachClause +  // "AND NOT EXISTS (SELECT 1 FROM rippling_reach mr WHERE mr.msgid = messages_spatial.msgid AND ST_Contains(mr.polygon, ST_SRID(POINT(?, ?), ?)) = 0) "
```

`rippling_reach` table state on prod (db3):

```
rippling_reach_rows = 239
SHOW INDEX FROM rippling_reach:
  PRIMARY                                BTREE   msgid                 (so NOT EXISTS has a usable PK on msgid)
  rippling_reach_polygon                 SPATIAL polygon (32)
  rippling_reach_next_expansion_at_index BTREE   next_expansion_at
  rippling_reach_status_index            BTREE   status
```

So the NOT EXISTS is **not** missing an index — and the subquery is tiny (239 rows). The damage is
elsewhere.

### Bottleneck A (dominant) — the `ST_SRID(point, ?)` wrapper defeats the spatial index

`messages_spatial.point` is already SRID 3857 (confirmed: `SELECT ST_SRID(point) FROM
messages_spatial` → 3857). Wrapping the indexed column in `ST_SRID(point, 3857)` is a functional
no-op but makes it non-sargable. EXPLAIN of the FULL #1 statement (isochrone 175, reach included):

```
isochrones        const   PRIMARY
messages_spatial  ALL     key=NULL   rows=55475   Using where         ← FULL TABLE SCAN
<subquery2> (mr)  eq_ref  auto_distinct_key                            Not exists
messages_likes    eq_ref  msgid_2
groups            eq_ref  PRIMARY
mr (MATERIALIZED) ALL     PRIMARY    rows=238
```

Even with the NOT EXISTS removed, the wrapper alone forces the scan:

```
-- WITHOUT the rippling NOT EXISTS, still ST_SRID(point,3857):
messages_spatial  ALL     key=NULL   rows=55475   Using where         ← still a FULL SCAN
```

Drop the wrapper (use the bare `point` column, which is the same SRID) and the SPATIAL index comes
back:

```
-- ST_Contains(isochrones.polygon, point)  (bare column):
messages_spatial  range   key=point  rows=354     Using where         ← SPATIAL INDEX, 156× fewer rows
```

`EXPLAIN ANALYZE`, same isochrone (304), proves the wall-clock impact:

```
WRAPPED  ST_SRID(point,3857):  Aggregate (cost=11412) actual time=867..867   (full scan)
BARE     point:                Aggregate (cost=542)   actual time=34..34     (Index range scan on point, rows=2033)
```

**~25× faster (867ms → 34ms)** from removing a no-op cast. This is the single biggest lever and a
trivial code change.

> The "uses spatial index rows=238" EXPLAIN quoted in the earlier draft of this doc must have been
> a differently-shaped query (bare `point`, or a smaller isochrone). The production statement, as
> emitted by the Go code, wraps `point` and does **not** use the index. Corrected here.

### Bottleneck B — rippling NOT EXISTS runs while rippling is globally OFF

`rippleEnabled()` (`message/message.go:396`) reads `RIPPLE_ENABLED` env / `config('freegle.ripple
.enabled')`; default **off**. Confirmed off on prod db3 (no `RIPPLE_ENABLED` in the running
`iznik-server-go` process env or its `.env`). The feature is dark: `message/message.go:824` and
`message/reach.go:93` both short-circuit on `!rippleEnabled()`.

But `isochroneCount()`'s `applyReach` gate (lines 211-216) checks **only**:
- viewer has a known lat/lng, and
- the `rippling_reach` table exists.

It does **not** check `rippleEnabled()`. Because the table now exists (239 rows left over from
engine testing), `applyReach` is `true`, so the `NOT EXISTS (… ST_Contains(mr.polygon, …))`
subquery is appended and **MATERIALIZED on every browse-count call** even though its result is
discarded everywhere else (the browse list itself, via `FilterReachBlocked`, is the consumer and
that path is inert when off). It is wasted work and it perturbs the optimizer toward the materialize
plan.

### Proposed fix for #1 (both levers, both in `iznik-server-go/isochrone/message.go`)

1. **Remove the `ST_SRID(point, ?)` wrapper at line 243** — use the bare column:
   ```go
   "INNER JOIN isochrones ON ST_Contains(isochrones.polygon, point) "
   ```
   and drop the corresponding leading `utils.SRID` from `args` (line 237). Same SRID, identical
   results, but the SPATIAL index is used. **Proven ~25×.** Apply the identical change to the
   `mygroups`/other call sites if any wrap `point` (none do here; `bounds.go` already passes bare
   `point` — see #3).
2. **Gate `applyReach` on `rippleEnabled()`** so the NOT EXISTS vanishes while the feature is dark:
   ```go
   applyReach := false
   if rippleEnabled() && (latlng.Lng != 0 || latlng.Lat != 0) {
       // … table-exists check …
   }
   ```
   (`rippleEnabled()` lives in package `message`; either export it or duplicate the 2-line env read
   in `isochrone`.) This also keeps the count consistent with the now-inert browse list.

Lever 1 is the must-do. Lever 2 removes residual per-call cost and prevents the materialize plan
from being chosen once the table is large. Do both.

> Index DDL is **not** needed for #1 — the SPATIAL `point` index already exists and is sufficient
> once the wrapper is removed. Do **not** hand a new index to the DBA for this query.

---

## #3 — Nearby/browse FETCH (83s)

Source: `iznik-server-go/message/bounds.go` (lines 48-99), a 2-branch `UNION` wrapped in an outer
`SELECT * FROM (…) t ORDER BY … LIMIT`.

### Spatial branch (line 62) — already uses the index

Here `point` is the **bare** column and the constant box polygon is the search arg:
`ST_Contains(ST_SRID(POLYGON(LINESTRING(...)),?), point)`. EXPLAIN:

```
messages_spatial  range   key=point   rows=308   Using where        ← SPATIAL index used
messages_likes    eq_ref  msgid_2                 Using index
groups            eq_ref  PRIMARY
```

So the spatial branch is fine — it correctly passes the bare column (note the contrast with #1,
which wraps it). No change needed.

### Own-posts branch (line 72-82) — bounded by `fromuser`, well-indexed

This branch is `WHERE fromuser = ? AND messages_groups.arrival >= ? AND ST_Contains(box,
ST_SRID(POINT(messages.lng, messages.lat), ?))`. It wraps a computed point, but it is anchored on
`fromuser = ?` first:

```
messages           ref     key=fromuser_2   rows=1   Using where; Using temporary
messages_groups    ref     key=messageid    rows=1   Using index
groups             eq_ref  PRIMARY                    Using index
messages_outcomes  ref     key=msgid                  Not exists
```

The `fromuser_2` index reduces this to the viewer's own recent posts (a handful), so the wrapped
spatial predicate runs on only those rows — not a scan. No index change warranted.

### Where #3's cost actually is

The branches are individually cheap; the 768ms avg comes from (a) the spatial-branch `point` range
returning up to a few thousand candidate rows for large boxes (inherent), (b) the `UNION` (dedupe +
filesort for the outer `ORDER BY unseen DESC, arrival DESC, id DESC`), and (c) #3 shares the
per-row `postvisibility` CASE with #1. **No code/index defect** here comparable to #1 — the spatial
predicate is already sargable. If #3 needs trimming later, the lever is reducing the candidate set
(tighter box / pagination), not an index. Lower priority than #1.

---

## #2 — chat_rooms list (160s, 642/862 "no-index")

Source: `iznik-server-go/chat/chatroom.go` (the big `SELECT DISTINCT chat_rooms…` at lines
1040-1082). Two correlated `COUNT(*)` subqueries over `chat_messages` per room, plus several
correlated `(SELECT id FROM users_images … ORDER BY id DESC LIMIT 1)` / `groups_images` joins and a
CTE window for the last message.

`chat_messages` indexes available: `chatid_2(chatid,date)`, `chatmax(chatid,id,userid,date)`,
`userid_2(userid,date,refmsgid,type)`, `date(date,seenbyall)`, `PRIMARY(id)`.

### "unseen" subquery — already indexed

`WHERE id > ? AND chatid = ? AND userid != ? AND date >= ? AND reviewrequired=0 AND reviewrejected=0
AND processingsuccessful=1`. EXPLAIN:

```
chat_messages  range  key=chatid_2  key_len=20  Using index condition; Using where  rows=1
```

### "replyexpected" subquery — already indexed; date window keeps it tight

`WHERE chatid = ? AND replyexpected=1 AND replyreceived=0 AND userid != ? AND date >= ? AND
processingsuccessful=1`. EXPLAIN on a small chat:

```
chat_messages  ref  key=chatmax  key_len=8  Using index condition; Using where  rows=1 filtered=5.00
```

`filtered=5.00` looks alarming (it reads the chat's messages then post-filters `replyexpected/
replyreceived`), so I tested the **busiest** chat in prod (16989549, **7,784 messages**) with
`EXPLAIN ANALYZE`:

```
Index range scan on chat_messages using chatid_2 over (chatid=16989549 AND '2026-05-01' <= date)
  (actual time=0.034..0.034 rows=0 loops=1)   ← the date>=unseenSince window bounds it, NOT a scan of 7784
```

The `date >= unseenSince` clause (`unseenSince = now - CHAT_ACTIVE_LIMIT days`,
`chatroom.go:660-663`) is itself part of the `chatid_2(chatid,date)` range, so even a 7,784-message
chat only scans the recent slice. **A `(chatid,replyexpected,replyreceived,date)` index would not
convert anything — the existing `chatid_2`/`chatmax` already give a range/ref, and the date window
already trims the candidate set.** I verified the optimizer picks `chatid_2`/`chatmax` unprompted,
so it is not an index-selection problem either.

### Where #2's cost actually is

The "642/862 no-index" flag is the **outer** query (the `DISTINCT` over `chat_rooms` with many
LEFT JOINs and the per-row image/last-message correlated subqueries), not the two COUNT subqueries.
160s / 862 calls = 186ms each is fan-out, not a single hot scan. Levers (lower priority than #1,
and none is a simple index):
- Replace the per-row `LEFT JOIN users_images i1 ON i1.id = (SELECT id FROM users_images WHERE
  userid=u1.id ORDER BY id DESC LIMIT 1)` pattern (3 of these) with a join to a precomputed
  "latest image per user/group" — or fold into the CTE.
- Fetch the two COUNT(*) values in a single grouped pass over the in-scope chat ids rather than two
  correlated subqueries per room.

**Recommendation: do NOT add the `(chatid,replyexpected,replyreceived,date)` index** — proven it
won't change the plan. Treat #2 as a query-restructure task, after #1.

---

## #4 / #5 — users_expected TIMESTAMPDIFF loop (61s combined, 4147 calls)

Source:
- #4 `user/user.go:387-391` (`SELECT DISTINCT(chatid) … `)
- #5 `user/userInfo.go:188-192` (`SELECT COUNT(*) AS expectedreply …`)

Both: `INNER JOIN users ON users.id=expectee INNER JOIN chat_messages ON id=chatmsgid WHERE
expectee=? AND date>=? AND replyexpected=1 AND replyreceived=0 AND TIMESTAMPDIFF(MINUTE,
chat_messages.date, users.lastaccess) >= ?`.

`users_expected` indexes: `PRIMARY(id)`, `chatmsgid` (UNIQUE), `expectee`, `userid(expecter)`.

EXPLAIN ANALYZE on the worst expectee in prod (39133318, 764 `users_expected` rows):

```
Aggregate: count(0)  (actual time=245..245 rows=1)
  Nested loop inner join
    -> Index lookup on users_expected using expectee (expectee=39133318)  rows=764 (actual 1.53..8.22, 764 rows)
    -> Filter: (replyreceived=0 and replyexpected=1 and date>='2026-05-01' and timestampdiff(...)>=30)
         -> Single-row index lookup on chat_messages using PRIMARY (id=users_expected.chatmsgid)  rows=1, loops=764
```

### Bottleneck & verdict

The `expectee` index returns **all** of that user's history rows (764), and for **each** it does a
PK lookup into `chat_messages` to apply `date`/`replyexpected`/`replyreceived`/`TIMESTAMPDIFF`. The
predicates that would prune (date window, replyexpected) live on `chat_messages`, not on
`users_expected`, so they can't be pushed into the `expectee` index. The cross-column
`TIMESTAMPDIFF(date, users.lastaccess)` is inherently un-indexable.

- A **covering index does not help** — the discriminating columns are in the joined table.
- The realistic levers (lower priority, given 15ms avg):
  1. **Call it far less often.** 4147 calls strongly implies a per-user loop (e.g. user-info
     enrichment for a member list). Compute `expectedreply` once per request set, or cache it.
  2. **Set-based rewrite:** join `users_expected` to `chat_messages` and `users` once for the whole
     batch of expectees rather than a query per expectee, so the 764-loop tail is amortised.
  3. The per-call cost is dominated by the tail (most expectees have few rows and run in <1ms; the
     764-row user costs 245ms). Bounding history age in `users_expected` (or pre-filtering by a
     stored `replyexpected` flag) would shrink the loop, but that is a schema/producer change.

No DBA index DDL recommended for #4/#5; the fix is in the calling pattern (Go).

---

## #6 — `messages_by` collected count (26.5s, 2115 calls)

`COUNT(DISTINCT messages_by.msgid) … messages_by JOIN messages JOIN chat_messages ON
refmsgid=messages.id JOIN messages_groups WHERE chat_messages.userid=? AND messages_by.userid=? …`.
0 "no-index" and 12.5ms avg — same high-frequency per-user enrichment loop as #4/#5 (same 2115
call count). Not individually defective; reduce call frequency along with #4/#5. Not deep-dived
(lowest of the listed offenders, no index/plan defect flagged).

---

## Embedding query (7.6s total, 3811ms avg, 2 calls)

`subject_embedding, body_embedding` full-table-ish scan; only 2 calls in the window so total cost
is low, but 3.8s/call is a latent risk if call volume rises (semantic search). Out of scope for
this pass (separate vector-index workstream); flagged for awareness.

---

## Prioritized action list

| Pri | Fix | Where | Type | Proven effect |
|-----|-----|-------|------|----------------|
| **P0** | Remove `ST_SRID(point, ?)` wrapper → bare `point` | `isochrone/message.go:243` (+ drop `utils.SRID` from args:237) | Go 1-liner | 867ms→34ms (~25×) on isochrone 304; full scan → `point` range |
| **P1** | Gate `applyReach` on `rippleEnabled()` (rippling is OFF in prod) | `isochrone/message.go:211-220` | Go | Removes the MATERIALIZE'd NOT EXISTS on every browse-count |
| P2 | Reduce call frequency / batch the per-user enrichment loop | `user/userInfo.go:188`, `user/user.go:387`, `messages_by` #6 | Go | 4147+2115 calls → fewer; tail user = 245ms |
| P3 | Restructure chat_rooms list (image subqueries + combined COUNTs) | `chat/chatroom.go:1040-1082` | Go | 186ms × 862; NOT an index fix (proven) |
| — | #3 fetch: no defect — spatial branch already sargable | `message/bounds.go` | none | leave as-is |

### Explicitly NOT recommended (tested and rejected)
- New index `rippling_reach(msgid)` — PK on msgid already exists; subquery is 239 rows.
- New index `(chatid, replyexpected, replyreceived, date)` on `chat_messages` — proven the
  date-windowed `chatid_2`/`chatmax` range already serves both #2 subqueries; would not change the
  plan.
- Any new index for #1 — the SPATIAL `point` index is sufficient once the wrapper is removed.

## Open items / follow-ups
- [x] FULL #1 EXPLAIN incl. rippling NOT EXISTS; `rippling_reach` indexes + row count; cost source
      identified (wrapper defeats index; NOT EXISTS is a secondary waste run while rippling is off).
- [x] Both branches of #3 EXPLAINed; spatial branch already uses `point` index, own-posts branch
      bounded by `fromuser`.
- [x] #2 replyexpected subquery EXPLAINed (incl. busiest 7,784-msg chat); proven the candidate index
      would NOT help — date window + `chatid_2`/`chatmax` already give a range.
- [x] #4/#5 per-call plan confirmed (764-row loop tail); covering index ruled out; rewrite = batch.
- [ ] Re-capture db2 digests once it is Synced (write-path digests may differ).
- [ ] Confirm cost-vs-area distribution of #1 calls (which calls hit the largest isochrones) once
      the cluster is stable enough to query `events_statements_histogram_*` — not blocking; the
      wrapper fix helps every isochrone size.

---

## NEW (2026-06-24) — `groups_digests` immediate-digest cursor query (10–24s on db2)

Surfaced live during the 07:00 UK digest send (sharded UnifiedDigest workers, db2). The query (one per shard, `MOD(groupid,8)=k`):

```sql
SELECT gd.groupid, gd.msgid AS cursor_msgid, gd.msgdate AS cursor_msgdate
FROM groups_digests AS gd
WHERE gd.frequency = -1
  AND EXISTS (SELECT 1 FROM memberships
              WHERE memberships.groupid = gd.groupid
                AND memberships.emailfrequency = -1 AND memberships.collection = 'Approved')
  AND MOD(gd.groupid, 8) = 3;
```

EXPLAIN (db3, live):
```
gd            ALL    rows=3159          Using where        ← trivial, groups_digests is 3,031 rows
<subquery2>   eq_ref auto_distinct_key
memberships   ref    key=collection(1)  rows=2,369,371     ← MATERIALISED EXISTS scans 2.37M Approved
```

The `EXISTS` is the whole cost: it's served by the `collection` index alone, so it materialises **2.37M `Approved`** rows and filters `emailfrequency=-1` in memory. There is **no index on `emailfrequency`**. Selectivity (db3):
- Approved members: **4,840,014**
- immediate members (`emailfrequency=-1`): **32,850** (0.68%)

### Fix (proven by selectivity, not yet applied)
Add **`memberships(emailfrequency, groupid)`** — ideally `(emailfrequency, groupid, collection)` so the EXISTS is index-only. The optimiser then resolves the EXISTS from the ~33k immediate members instead of sieving 2.37M Approved → **10–24s → ms**, for all 8 shards. `MOD(groupid,8)` is NOT the problem (`groups_digests`=3,031 rows).
- DDL is on `memberships` (4.84M rows) → run via **`pt-online-schema-change`**, NOT a TOI `ALTER` (Galera).

## NEW (2026-06-24) — ripple reach / isochrone computation (~25s per post)

`iznik-batch ReachService::computeSchedule(lat,lng)` calls the routing server `GET /v1/ripple-schedule` **fresh per post**, **sequentially**, 20s timeout — a full Dijkstra drive-time isochrone on the ~57M-node UK graph each call. Observed ~25s/post (a `ripple:expand --limit=100` run took tens of minutes). No caching in `ReachService` (plain `Http::get`).

### Speedups (cheapest first)
1. **Cache by blurred location.** Reach depends only on `(lat,lng,mode,ticks,max_minutes,curve)` and the origin is already ~400m-blurred before the call, so clustered posts collapse to the same point and identical reach. Memoise per-run, or persist (like the existing `isochrones` cache) keyed on the rounded blurred point + params. Biggest win in concentrated areas; small `ReachService` change.
2. **Parallelise** the per-post calls (sequential today). Routing server is Go + handles concurrency; each isochrone is CPU-bound, so ~N× up to the routing node's core count on cache misses.
3. **Routing-server algorithm** (bigger): Contraction Hierarchies / precomputed isochrone grid in `iznik-routing-go` to cut the per-request Dijkstra. Only worth it at production rippling scale.

(1)+(2) take a 100-post run from ~40min to a few minutes without touching the routing engine.
