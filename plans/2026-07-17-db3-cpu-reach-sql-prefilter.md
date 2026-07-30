# db3 CPU: reach-polygon SQL prefilter (sandwich bounds) + adjacent hot-query fixes

**Status: measured & verified design, NOT yet implemented. 2026-07-17.**
All numbers measured live on prod db3 (read-only probes). Multi-agent audit + adversarial
verify run against the actual code (journal: session workflow `wf_a7988a71-685`).

## Problem

db3 user CPU hits ~94% in bursts tracking the morning digest (~1,000 mails/min 06:00–07:30).
mysqld is ~4.5 of 12 cores. Top real consumers over 10 days (performance_schema):

| workload | calls | total time | avg |
|---|---|---|---|
| reach browse/count `ST_Contains(rr.polygon, viewer)` (3 digests) | 3.4M | ~605 h | 480–849 ms |
| chat list query (GET /chat, 30s poll/tab) | 8.0M | ~204 h | 92 ms |
| `UPDATE users SET lastaccess=NOW()` | 1.8M | ~197 h | 387 ms |

Root cause of the reach cost: `rippling_reach.polygon` averages **11,152 vertices / 178 KB**
(max 54k / 846 KB; 8.2 GB total over 48.5k rows) — grid-fill isochrones. Per query
(London, worst case): R-tree returns 1,953 MBR candidates; each costs a 178 KB BLOB
fetch (~64 µs) + parse/point-in-poly (~53 µs). ~46% of candidates belong to completed
posts, filtered by `ms.successful=0` only *after* paying full polygon cost.

## Measured dead ends (do not retry)

- **bbox/lat-lng prefilter**: the SPATIAL R-tree *is* the bbox prefilter and IS used
  (EXPLAIN: `Index range scan ... rippling_reach_polygon`). MBR precision already 46%
  (902/1953). No stored box can beat the polygon's own MBR.
- **Lossless vertex reduction**: `ST_Simplify` at tol 1e-10..1e-5 removes ZERO vertices
  (no collinear points — every vertex is a direction change).
- **Lossy `ST_Simplify` as replacement**: UNSAFE — tol 0.005° bridges the Thames at
  Gravesend (gains Rainham marshes on the north bank); tol is in DEGREES (coords are
  lng/lat mislabeled SRID 3857); Thames is ~0.007–0.012° wide. Confirmed on 6 reaches.
- **Routing server as synchronous replacement**: `/v1/posts-for-member` is barrier-correct
  but the Dijkstra costs 1.79 s @30 min, 14.7 s @60 min vs 0.26 s for the whole SQL query.
  Viable only with a cached per-viewer drive-time map (future option).

## MySQL executor facts (measured, they dictate the query shape)

1. AND conjuncts are staged cheap-first and BLOB fetch is lazy per stage: a failing cheap
   conjunct means the polygon BLOB is never fetched (0.6 ms vs 125 ms over 1,953 rows).
2. Inside a single OR/CASE/IF item, ANY reference to the polygon column forces the BLOB
   fetch for every evaluated row — `(msgid>0 OR ST_Contains(polygon,pt))` over 8,129 rows:
   **2.10 s**, vs 2.3 ms without the reference. Laziness does NOT cross expression items.
3. A correlated `EXISTS (SELECT ... r2.polygon ...)` inside an OR IS lazy: 5 ms when the
   cheap arm is true, 2.78 s (342 µs/row) only when forced. This is the only safe place
   for the big polygon.
4. `MBRContains(polygon, pt)` drives the R-tree (`type: range`) from the index alone.

## The fix: conservative sandwich bounds (validated)

Store two SMALL derived polygons per reach; the exact polygon stays authoritative:

- `outer` = superset bound (e.g. `ST_Buffer(ST_Simplify(polygon, tol), +tol)`), fallback
  `ST_Envelope(polygon)` (always safe, just useless).
- `inner` = subset bound (`ST_Buffer(ST_Simplify(polygon, tol), -tol)`), fallback NULL
  (disables cheap-accept, still correct).

No Thames risk by construction: simplification is never authoritative. Validated on
~1,455 polygon×point trials across London/B'ham/Bristol/Mcr/Edinburgh/**Tilbury**:
**0 violations both directions** at tol 0.001/0.002/0.005.

Selectivity (tol 0.002, 966-pt outer ≈ 15 KB): outer rejects 54% (all MBR false
positives), inner cheap-accepts 77% of true hits, **boundary band needing the full
11k-vertex test: 7% (tol 0.001) / 19% (tol 0.002) / 29% (tol 0.005)**.

Query shape (every element measured):

```sql
WHERE MBRContains(b.outer, @pt)                 -- small R-tree drives
  AND ST_Contains(b.outer, @pt)                 -- ~15KB fetch, kills 54%
  AND (ST_Contains(b.inner, @pt)                -- cheap accept (NULL→falls through)
       OR EXISTS (SELECT 1 FROM rippling_reach r2   -- 178KB touched ONLY here,
                  WHERE r2.msgid = b.msgid          -- only for the 7–19% band
                    AND ST_Contains(r2.polygon, @pt)))
```

Projected: worst-case London query 259 ms → ~74 ms (tol 0.001); BLOB traffic
348 MB → ~35 MB/query (big buffer-pool churn relief for everything else).

### Schema: SIBLING TABLE, not ALTER (verify condition #10)

`rippling_reach_bounds(msgid PK, FK→rippling_reach ON DELETE CASCADE, outer GEOMETRY
NOT NULL SRID 3857 SPATIAL KEY, inner GEOMETRY NULL)`. Avoids TOI DDL on the ~10 GB hot
table (INSTANT is unreliable on Percona 8.0.43; SPATIAL needs NOT NULL). Missing row ⇒
reader falls back to full `ST_Contains` — fail-safe rollout, trivially reversible.
Backfill INSERTs ~30 KB/row (~1.5 GB replication total) one-row-at-a-time, paced,
resumable (backfillReach pattern), off-peak.

### Write sites (audit COMPLETE, independently verified)

PHP `ExpandService.php`: initialiseNew ~805, advanceDue ~949, recomputeReach ~185,
backfillReach ~1629, reapplyClips ~1743 (ST_Difference in place). Go `message.go:2268`
ClipReachForRejectedGroup (ST_Difference) + dependent wholly-within DELETE :2276.
All schedule-driven writers call reapplyClips after — derive bounds from the FINAL
stored polygon after clips, in SQL, per-row try/caught (GIS functions can THROW on the
~94%-invalid stored polygons; a failed sandwich must never abort the tick advance).

**Hard rules from the adversarial verify:**
- Any polygon SHRINK (both ST_Difference clip paths) must synchronously shrink-or-NULL
  `inner` — a stale inner cheap-accepts viewers in a just-rejected group's area
  (over-visibility; same class as a Thames leak). Stale `outer` is safe-loose.
- Keep the wholly-within DELETE (message.go:2276) keyed on `polygon`, NOT outer —
  `ST_Within(outer, G)` is stricter and would stop the secondary-rejection clip firing.
- Write-time verification: `ST_Contains(outer, polygon)=1` and `ST_Contains(polygon,
  inner)=1`; anything ≠1 (incl. errors on invalid geometry) ⇒ fallbacks.
- Cutover strictly writers → 100% backfill → offline A/B predicate sweep on sampled
  viewer points → readers.
- Preferred long-term: compute bounds in iznik-routing-go (geometry origin, real geometry
  lib, controllable validity), ship per-tick outer/inner in the schedule; SQL keeps only
  union+clip maintenance. Cached old schedules lack bounds ⇒ keep SQL fallback.

### Completed-post pruning: only via the bounds table

Verify verdict on pruning `rippling_reach` itself: **UNSAFE both variants**.
(a) DELETE ⇒ `initialiseNew`'s anti-join (:643-655, no outcome filter) resurrects rows
with full polygons next ripple:expand — permanent delete/recreate churn (+created_at
corruption breaking analytics windows). (b) Degenerate polygon ⇒ digest "came and went"
posts vanish (UnifiedDigestService:1526-30 gates has_success posts); ALL replies to taken
rippled posts get held-then-'taken-gone' (chatmessage.go:575-90, IncomingMailService:1655,
3239 — no outcome checks); un-completion is a real automated flow (message.go:2748/2890/
3166/4403 + MessageSpatialService:144-9) and would leave reopened posts permanently
invisible with no repair path. recomputeReach's `status != 'rejected'` filter is a NO-OP
('rejected' not in the enum).

Safe variant: degenerate/skip the **bounds row** on completion (browse arms all filter
`successful=0`; every other consumer reads `polygon`, untouched). Hook the outcome flip
in MessageSpatialService::updateOutcomesAndPromises both directions (completed ⇒ degrade
outer; reopened ⇒ re-derive from stored polygon — pure SQL, no routing call). Halves the
R-tree candidate set (46% completed) ⇒ combined projection ~40 ms (~6.5×).

## Adjacent fixes (same magnitude, much cheaper to ship)

1. **users.lastaccess (197 h/10 d)**: authMiddleware.go:89 throttle is app-side and racy
   (N parallel requests all pass, same-row writes spray across db1/2/3 ⇒ Galera cert
   conflicts ⇒ 387 ms avg). Fix = SQL guard `AND (lastaccess IS NULL OR lastaccess <
   DATE_SUB(NOW(), INTERVAL 10 MINUTE))` (the sessions.lastactive pattern 3 lines below
   runs 11 ms — proven in situ). AND delete notification.go:64's unthrottled duplicate
   (fires per 60s navbar poll for any user with unread notifications).
2. **Badge poll silently runs the heavy query**: nearbyCount path (b) (message.go:697) —
   any saved browseMaxDistance upgrades the 60s badge poll from COUNT to full candidate
   enumeration (849 ms digest). Fix: distance-limited COUNT without envelope/views/replies.
3. **Chat list (204 h/10 d)**: GET /chat polled every 30s/tab incl. hidden tabs; copy the
   ModTools pattern (cheap count endpoint, refetch list only on change); drop the
   ROW_NUMBER CTE (chatroom.go:1143-9, duplicate of the adjacent LIMIT 1 join); composite
   index rippling_held_replies(chatmsgid, status); skip poll when document.hidden.
4. Consistency nit: nearbyCount unlimited path lacks `rr.status != 'held'` (message.go:689
   vs :184) — badge can count posts the feed hides.

## Done already (2026-07-17)

- innodb_buffer_pool_size 6G→16G **dynamically** (3 s, no stall) + persisted in
  /etc/mysql/my.cnf (backup my.cnf.bak-20260717-bufferpool). 6G was smaller than the
  polygon data alone. Baseline query 326→259 ms from this alone.
- WARNING: `systemctl is-active mysql` = failed on db3 while mysqld runs (Jul 7,
  `--wsrep-new-cluster`) — systemd is NOT managing the live process; do not assume
  `systemctl restart mysql` restores service.
