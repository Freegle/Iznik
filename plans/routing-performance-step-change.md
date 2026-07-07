# Routing / isochrone performance: step-change design

**Date:** 2026-07-07
**Status:** DESIGN ONLY - nothing implemented. Produced by a multi-agent design study
(4 survey agents, 6 design angles, adversarial review of each, synthesis).
**Scope:** iznik-routing-go (the routing/isochrone server) and its batch callers
(iznik-batch ripple crons). Goal: a step change in steady-state efficiency; startup
secondary.

---

## 1. Measured ground truth

All numbers measured this session against the real UK graph unless marked otherwise.

**Graph** (built from uk-latest.osm.pbf on every process start, no cache):
- 56,874,451 nodes / 117,157,737 directed edges; average out-degree 2.06.
- Nodes are ALL OSM way nodes: 77.9% are degree-2 geometry pass-throughs.
- Drive-usable edges: 59.2%. One 26.46M-node connected drive component (46.5% of N);
  most of the rest is footway/cycleway-only geometry. Orkney is a genuine separate
  42k-node component (any component pruning must be size-thresholded).
- Build time ~90-98s on a 24-core box; 5-16 min observed on prod/dev under load.

**Isochrone engine** (`dijkstra.go`): plain bounded Dijkstra, `dist` is a
`map[NodeID]float32`, heap items are individually allocated pointers, and there is a
haversine call per edge relaxation.
- London 30-min drive: 377,947 nodes reached in ~286-310ms.
- London 120-min drive: 7,334,766 nodes in ~12.05-12.55s (44x time for 19x nodes -
  superlinear, the map degrades as it grows).
- pprof shares of Isochrone CPU: map ops 16.8% (30-min) rising to 28.4% (120-min);
  heap 17.4% / 10.3%; haversine ~6%; EdgesFrom CSR access 17.9% / 9.2%.

**The decisive finding - the sweep is NOT the hot endpoint's main cost.**
`handleRippleSchedule` Step 3 (ripple.go:424 area) calls `nearestNodeForMode` once per
candidate freegler in the reach bbox with NO location dedup (`snapMembers` right next to
it DOES dedup). Measured 74-184µs per snap:
- 10k candidate freeglers -> ~1.8s; 50k -> ~9.2s. Versus 280-676ms for the whole
  Dijkstra sweep of the same call.

**Recomputation waste (workload analysis):**
- `ripple:proximity-notes` (every 5 min, limit 200): a `rippling_proximity` row is only
  written when `quicker=true`, so "not quicker / unreachable" rows are re-checked every
  run for the full 8-day window. Standing tax ceiling: 200 rows x ~754ms x 288 runs/day
  ~= **12 CPU-hours/day**. (The 2026-07-06 Sentry storm - 4282 slow-call warnings from
  group 21521 - was this plus the missing max_minutes budget; the budget fix is already
  in the working tree.)
- `ripple:expand` (every minute): tick geometry is fetched per post per tick via live
  `/v1/catchment` (one Dijkstra + one rasterise each), ~185 calls/min. The numeric
  schedule is origin-deduped (2.6x); the geometry is not. A fully-expanding post pays
  9 Dijkstras for one deterministic isochrone.
- Server has essentially no caching: the only cache anywhere is `activesCache`
  (group_actives.go, 1h TTL). Group seeds (`groupSeedNodes`: DB fetch + WKT parse +
  nearest-node snap per polygon vertex) are recomputed on every group-proximity /
  group-extent / catchment?groupid call.

---

## 2. Recommended program (phases are independently shippable, in priority order)

### Phase 0 - negative memoization for proximity notes (Laravel, ~2-3 days)
- New marker table `rippling_proximity_checked (msgid, groupid, checked_at)` written on
  EVERY definitive check ("quicker" or "genuinely not quicker / unreachable").
  NOT nullable columns on `rippling_proximity`: iznik-server-go/message/message.go scans
  p/q as non-null and would break.
- Widen `ReachService::groupProximity` to a tri-state: quicker / genuinely-no /
  transient-failure (HTTP error, timeout, routing server mid-restart). Only definitive
  results are memoized; transient failures retry next run. Without this, a routing
  restart would permanently suppress notes for whatever rows were in flight.
- Effect: ~12 CPU-hr/day recurring -> one-time backlog drain (~15 min), then organic
  new-row volume only.

### Phase 1 - dedup/cache the freegler snap loop (Go, ~2-3 days)
- In `handleRippleSchedule` Step 3 and `handleRippleEval`: dedup candidate freegler
  locations before snapping (mirror `snapMembers`' per-request nodeCache), and consider
  a small LRU keyed by quantized (lat,lng,mode) across requests.
- Biggest wall-clock win available on the hottest endpoint; trivial risk.
- (Optional follow-up: persist snapped NodeID per user approx-location, invalidated on
  graph rebuild - only if the LRU proves insufficient.)

### Phase 2 - flat-epoch Dijkstra core (Go, ~1 week)
- Replace `map[NodeID]float32` with pooled per-call buffers of
  `struct{cost float32; epoch uint32}` sized N+1 (455MB per buffer; pool bounded to
  concurrency). Epoch bump = O(1) reset, no clearing.
- Allocation-free index-based heap (no per-push pointer allocs).
- Replace per-edge haversine prune with a safety-margined squared-equirectangular test.
- Apply to ALL FOUR sweep implementations - `Isochrone` (dijkstra.go), `costToTargets`
  (proximity.go), `multiSourceIsochrone` (catchment.go), and the inline copy in
  `FairnessIsochrone` (fairness.go) - unifying them behind one engine type.
- Projected from live pprof decomposition: 1.46x at 30-min, 1.57x at 120-min; gains
  grow exactly where the 3000ms Sentry threshold bites (large/dense sweeps).

### Phase 3 - never compute the same isochrone twice (Go + minor batch, ~1-2 weeks)
- In-process superset cache keyed by (snapped origin NodeID, mode): store the largest
  reached-set computed for that origin; serve any smaller budget by filtering. Extend to
  memoize the fine-resolution `/v1/catchment` polygon per exact tick budget. Kills 8 of
  9 Dijkstra+rasterise calls per fully-expanding post and collapses the ~185/min tick
  geometry churn to one computation per distinct origin. Zero change to what is stored
  in `rippling_reach.polygon` (three live exact consumers depend on it - see rejected
  designs).
- Group-seed / group-diameter TTL cache keyed (groupid, mode), same pattern as the
  existing `activesCache`. Shared by /v1/group-proximity, /v1/group-extent,
  /v1/catchment?groupid.
- Diff-test cached vs fresh responses on fixed probes before enabling by default.

### Phase 4 - weak-component fix for disconnected snapping (Go, ~2-3 days)
- Build-time union-find over undirected adjacency (NOT directed SCC - Kosaraju/Tarjan
  would fragment one-way-heavy urban gyratories), size-thresholded so genuine islands
  (Orkney, IoW, IoM) stay separately routable.
- `nearestNodeForMode` prefers nodes in a sufficiently large component: fixes the
  known blurOrigin-snaps-to-disconnected-island -> empty isochrone bug.

### Phase 5 - mmap graph snapshot (opportunistic, ~1 week)
- Serialize the built CSR (+ grid + component labels) to a versioned snapshot at
  OSM-refresh time; on start, mmap and validate, else fall back to PBF build.
- Restart: 5-16 min -> seconds. Eliminates the monit rebuild-loop outage class
  entirely. Startup was declared secondary, but this is cheap, independent, and
  removes a whole failure mode.

**Rough sequencing:** 0 and 1 in parallel first (both small, immediate production
relief), then 2, then 3, then 4/5 as capacity allows. Total ~4-6 engineer-weeks.

---

## 3. Evaluated and NOT adopted

**Junction-graph contraction (deferred, revisit after Phases 1-3 + production data).**
Collapsing degree-2 chains to a junction-only graph is genuinely attractive on paper and
was measured properly: 12,899,893 junctions (22.68% of N) using a corrected per-mode
junction rule (a naive any-mode rule undercounts - 523,929 nodes have any-mode degree 2
but are genuine per-mode branch points because way usability differs per mode);
16.1M contracted chain edges (26.8% of unique undirected edges); contraction build pass
~7.5s measured. But the adversarial review rated it weak FOR NOW:
- Wrong denominator: after Phase 1, the sweep is a minority of the hot endpoint's cost;
  a perfect 2.9x sweep speedup moves /v1/ripple-schedule end-to-end by only ~2-30%.
- polygon.go rasterisation and NetworkResolution call `g.EdgesFrom(u)` on every reached
  node - absorbed nodes need synthesized adjacency or the old CSR stays resident
  (breaking the memory claims).
- Four sweep implementations to port; chain breaks needed at every oneway transition,
  per-node quintile fidelity for fairness, pathMetres exactness, max-reach pruning per
  absorbed node - a large correctness surface.
If revisited: only for the polygon-heavy endpoints (/v1/catchment, /v1/fairness,
/v1/digest-simulator, /v1/posts-for-member), with lazy expansion (junction-only sweep +
O(1) point lookups via a NodeID->(chain,offset) table) for the point-lookup endpoints.

**CH / PHAST / CRP preprocessing:** not justified for 30-120 min regional bounded
isochrones at this scale; the win over a well-implemented flat-array Dijkstra does not
cover the preprocessing complexity and memory.

**REJECTED - verified would-be bugs:**
- Replacing groupProximity's first sweep with a per-group forward multi-source distance
  field: directionally wrong on one-way streets (forward-from-seeds is not
  time-TO-seeds). Any batching of that path needs a reverse-CSR sweep instead.
- Replacing `rippling_reach.polygon` with coarse grid cells: the column is read by three
  live exact consumers with no compensating check - browse-feed ST_Contains, the
  chat-reply hard 403 "not_in_reach" gate, and reachBlocked flagging.

**Deferred (real but lower value now):**
- Reverse-CSR multi-source batching of proximity notes (one sweep per group settling all
  pending posts): mathematically sound (edge costs are direction-symmetric per way, so
  From/To swap is exact), but Phase 0 shrinks day-to-day volume so far that this becomes
  backlog-surge insurance. Revisit only if post-Phase-0 telemetry says so.
- Spatial (Hilbert/BFS) node renumbering and per-mode compact graphs: literature-derived
  multipliers only; gate behind on-box perf measurement after Phase 2 ships.

---

## 4. Open questions (maintainer decisions)

1. **Tombstone retry policy:** should a definitive "unreachable" ever be retried within
   the 8-day window (e.g. after an OSM graph rebuild), or is checked-once-forever fine?
2. **Group-seed cache invalidation:** TTL-only (matches activesCache precedent) or a
   ModTools group-boundary-save hook?
3. **RAM budget:** pooled flat buffers (Phase 2) + superset cache (Phase 3) add real RSS
   on db1/2/3 - set explicit byte ceilings before rollout.
4. **rippling_reach.polygon future:** harden the three exact consumers with a
   compensating check now (opening the door to cheaper geometry later), or accept
   fine-resolution-forever?

---

## 5. Artifacts

- Full design/verdict JSON, survey reports, synthesis: session scratchpad
  (`synthesis.md`, `designs.json`, `surveys.txt`); workflow run `wf_737eba3c-5cf`.
- Baseline timings taken against the local full-UK instance (spatial-live):
  catchment 30-min Manchester ~1.7s vs 10-min ~85ms; ripple-schedule 30-min slim ~2.0s;
  group-proximity 21521 ~1.1-1.6s.
