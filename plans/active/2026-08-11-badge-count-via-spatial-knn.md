# Badge count via spatial-knn: move reach containment out of MySQL

## Problem (measured, 2026-08-11)

The nearby unread badge (`GET /api/message/count`, apiv2 `isochrone.Count` →
`nearbyCount`) tests "which live reaches contain the viewer" in MySQL:
R-tree prefilter on `rippling_reach.outer_bound`, then exact `ST_Contains`
against 11k-vertex polygons for the 7–19% of candidates between the sandwich
bounds. Cost: **~0.5–0.6s per call, ~215 calls/min at evening peak ≈ 2 cores
of steady mysqld burn on db3** (the write node and only active apiv2 backend),
visible as load-12–16 wobble in `/tmp/cpuspike` captures even after the
2026-08-11 dashboard and lazy-support-extras fixes. The cost is geometry BLOB
reads (17GB table) + polygon math on a memory-tight box (7.5G swap in use);
slimming the SELECT (3b9cc7b90) did not move it because the cost is in the
WHERE.

## Proposal

Add a **reach dataset** to `iznik-spatial-go` (which already runs natively on
db1/2/3 at :8194, co-located with apiv2) and serve point-in-reach from RAM:

1. **Dataset** (`dataset_reach.go`, modelled on `dataset_messages.go` which
   already does messages_spatial with `RebuildInterval 24h` /
   `DeltaInterval 2min` + DriftChecker): load `rippling_reach` rows
   (msgid, status, polygon (+ sandwich bounds if present)). At load time,
   rasterise each polygon onto a grid **in memory** and keep only the cell
   set + bbox — do NOT hold raw polygons resident (52k × ~178KB ≈ 9GB; cells
   ≈ 52k × O(10²–10³) cells × 8B ≈ low hundreds of MB). The reach polygon is
   itself traced from a rasterisation grid (`iznik-routing-go/bounds.go`), so
   cell-testing on the same resolution is exact by construction, not an
   approximation. Delta cadence follows the existing 2-minute pattern; reach
   rows change only on ripple ticks so this is fresh enough for a badge that
   already polls at 60s.

2. **Endpoint**: `GET /v1/reach/containing?lat=&lng=` → `{msgids: [...]}` —
   all live (status != held) reaches whose cell set contains the viewer's
   cell. Internal, no auth, same as the other :8194 endpoints.

3. **apiv2**: `nearbyCount` (and later the feed's membership arm) calls
   localhost:8194, then runs the cheap user-specific SQL over the returned
   ids: `COUNT ... WHERE ms.msgid IN (...) AND ms.successful=0 AND ml.msgid
   IS NULL` + author reach cap. IN-list is the viewer's in-reach set
   (typically O(10²)) — keyed lookups, single-digit ms. **Fallback**: if
   spatial is unreachable / returns not-ready (existing "dataset not ready"
   convention), fall through to the current SQL containment path — same
   gating shape as `rippling.ReachBoundsReady`.

## Why not alternatives

- **Routing server per-request**: Dijkstra 1.8–14.7s measured 2026-07-17 —
  dead end. Routing's grid stays a generation-time input.
- **MySQL `rippling_reach_cells` table**: same idea, but adds Galera write
  volume and 17GB-table adjacency; spatial-go's in-memory index + delta
  loader already exists and needs no schema change or batch writer changes.
- **Materialized per-user counts**: biggest win but hardest correctness
  (author caps, distance slider, group churn); not needed if containment is
  ~free.

## Deploy surfaces (BOTH, per deploy runbook)

- native spatial-go on db1/2/3 (web path — this is the one the badge uses)
- local `freegledocker-spatial-knn` container (batch digest path) — rebuild
  so digests can use the same endpoint later if wanted.
- apiv2 on db1/2/3.

## Risks / edge cases

- Boundary quantisation: use the generator's grid resolution; a viewer in a
  cell the polygon only partially covers gets the generator's own answer.
  If the load-time raster uses a different resolution than bounds.go, mark
  boundary cells and exact-test only those few (bbox + winding test on a
  simplified ring kept per reach).
- Held→released flips must appear within the delta cadence (status is part
  of the delta row).
- ST_Difference clips (completed-post degradation) shrink a polygon: delta
  must replace that msgid's whole cell set, not union (keyed replace, as
  jobs ApplyDelta does).
- Memory on db nodes: budget ≤ 500MB; log the built size; refuse to serve
  (not-ready) rather than OOM alongside mysqld/apiv2/routing.
- Sitewide count parity: keep a temporary shadow-compare (log when spatial
  path and SQL path disagree by >0) for a soak period on one node.

## Measured cost split (2026-08-11, EXPLAIN ANALYZE on db3, 4 live viewers)

| viewer | full query | containment only | residual with ids precomputed | outer cands | inner accepts | inner_bound NULL |
|---|---|---|---|---|---|---|
| Cardiff | 103 ms | 182 ms | 7.8 ms | 216 | 33 | 156 (72%) |
| Kent (rural) | 496 ms | 444 ms | 5.4 ms | 415 | 22 | 349 (84%) |
| Edinburgh | 349 ms | 284 ms | 12.5 ms | 894 | 166 | 581 (65%) |
| Lancashire | 387 ms | 328 ms | 10.9 ms | 807 | 142 | 524 (65%) |

- Geometry containment is **95–98% of the query**; the user-specific SQL
  (joins, unseen, author cap) over a precomputed id list is **5–13 ms**.
  So handing apiv2 the in-reach msgids (from spatial-knn RAM) is a
  **~30–50× win per call**, and in-reach lists are O(10²–10³) — IN-list
  friendly.
- Root cause of the geometry cost is worse than the July estimate: the
  inner-bound quick-accept barely functions. **65–84% of outer-bound
  candidates have `inner_bound` NULL** (small reaches erode to no inner),
  so they all fall through to the exact 11k-vertex `ST_Contains`. The July
  "7–19% band" figure did not account for NULL inners at this scale.
  (An alternative cheaper-than-spatial fix — synthesising better inner
  bounds — would attack the same 65–84%, but caps out well short of the
  cells approach and leaves the BLOB reads in mysqld.)
- Measurement method: `/tmp/measure-count.sh` (scratchpad copy in the
  session dir) — EXPLAIN ANALYZE of the deployed SQL, containment-only,
  and residual-with-ids variants per viewer point. GOTCHA: GROUP_CONCAT
  needs `group_concat_max_len` raised or the id list silently truncates;
  `mysql --raw` needed for parseable EXPLAIN ANALYZE trees.

## Status

- 2026-08-11: plan written after live spike captures identified the badge
  count as the dominant residual db3 load. Cost split measured (above):
  design validated, ~30–50× per-call win available.
- 2026-08-11 (later): IMPLEMENTED, dark-launched.
  - spatial-go: `raster.go` (tri-state polygon raster, never-wrong property
    tested incl. 11k-vertex ring; ~14ms/polygon build, parallelised load),
    `dataset_reach.go` (2-min delta on updated_at, held rows removed, drift
    check), `GET /v1/:dataset/containing` (PointContainer interface).
    Rasters instead of raw cells: ~2.3KB/reach fixed, ≈150MB for 52k.
  - apiv2: `isochrone/reachspatial.go` + nearbyCount wiring. Gated on
    `SPATIAL_REACH_MODE=on` per node .env; any spatial error/not-ready falls
    back to the SQL containment path. Partial ids exact-tested in SQL WITH a
    held re-check (covers holds newer than the spatial delta).
    Test: TestNearbyCountSpatialReach (stubbed spatial server; in/partial/
    fresh-hold/fallback/mode-off).
  - ROLLOUT: deploy spatial-go db1/2/3 (unmonitor dance not needed — KNN
    reopens warm, but reach builds fresh ~2min on first start; badge falls
    back meanwhile) + local spatial-knn container; deploy apiv2; then set
    SPATIAL_REACH_MODE=on on ONE node's apiv2 .env, compare counts + watch
    db3 mysqld digest for the au-join COUNT dropping, then enable the rest.
- 2026-08-11 (evening): DEPLOYED AND LIVE on all three nodes + local
  container, SPATIAL_REACH_MODE=on everywhere. Live parity harness
  (/tmp/reach-parity.sh, scratchpad copy) reached 6/6 exact MATCH vs MySQL
  ground truth after three delta-design fixes it surfaced (2178a2983 +
  25ce7892d→3549a7ef7): sync point stamped at build/tick START (not end),
  per-tick two-way id reconcile (hard deletes + gaps), sync point persisted
  in an index meta table (restart resumption). RESULT on db3: the two
  legacy badge COUNT digests went from ~150-230s per 90s window to ZERO
  new executions; replacement IN-list count runs ~62ms avg at ~2/s; evening
  load 12-20 spikes → 4-8 steady. Deploy gotchas hit: monit restart bind
  race leaves OLD spatial binary serving (verify proc-mtime ≥ bin-mtime;
  use unmonitor/kill/manual-start/monitor for first builds — listener binds
  only AFTER startupLoad, and a flap mid-build leaves a corrupt 4KB
  reach.db → SQLite 522 → rm reach.db* + admin-port rebuild).
- REMAINING (tuning, not correctness): London-style dense-urban viewers
  have fat partial bands (63% — tiny reaches spanning few raster cells), so
  their exact-test residue is larger; option = finer grid for small
  polygons, or skip rasterising polygons under ~N cells and mark all-partial
  (they're cheap to exact-test anyway). Parity harness is the check.
- 2026-08-11 (later still): the `in` bucket had no rippling_reach reference at
  all, so it counted posts the FEED excludes. Only the count uses the raster; the
  feed is always reachCandidateQuery with its unconditional `rr.status != 'held'`,
  so any status the two disagree about shows a member "N new posts" above
  "you're up to date" with nothing in between. Fixed: both buckets now require a
  live, non-held reach row via EXISTS (one PK lookup per id; the `in` bucket still
  skips the geometry, which is the whole point of the raster). Deleted rows drop out
  too, so the SQL side no longer relies on reconcile() alone for hard deletes.
  Tests added to TestNearbyCountSpatialReach: fresh-hold on an `in` id, and an
  `in` id whose reach row has gone. NOTE this is now a LIVE defect, not a
  pre-rollout one: the mode went on everywhere the same evening. The 6/6 parity
  harness would not have caught it - it compares counts at a moment, while this
  window only opens for the ~2 minutes after a hold, so a spot-check almost always
  lands outside it.
- KNOWN RESIDUAL, now live: a polygon that SHRINKS (retraction, trimming) is only
  picked up on the next delta, so for up to ~2 minutes an `in` id can claim
  containment the feed's ST_Contains would refuse. Not closed, deliberately: testing
  containment for `in` ids is exactly the geometry cost the raster exists to avoid,
  and it is what the 12-20 -> 4-8 db3 load drop bought. Status and existence are
  cheap and are checked; containment is not. To detect it, the parity harness needs
  to sample DURING a reach change rather than at rest.
