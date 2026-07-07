# Speeding up ripple reach computation — investigation & recommendation

Date: 2026-06-24
Branch: `perf/ripple-reach-speedup`
Status: investigation (measurements done; design recommendation below)

## The question

`ExpandService` computes each post's rippled-out reach by calling
`ReachService::computeSchedule(lat,lng)` → routing server `GET /v1/ripple-schedule`
(`iznik-routing-go`). Each call runs a full drive-time isochrone on the ~57M-node
UK graph, **sequentially, one post at a time**, 20s HTTP timeout. Measured
~25s/post; a `--limit=100` run took ~40 min. No caching; the routing server
rebuilds the plain graph from the PBF on every start.

Two levers were proposed:
1. **Parallelise** the reach computations (small, in `iznik-batch`).
2. **Faster isochrones via contraction hierarchies (CH/PHAST)** in `iznik-routing-go`
   — explicitly chosen over precompute-grid and caching. Open question: would CH
   give **different outcomes** than the current Dijkstra, and is it **faster**?

This document answers both with measurements on the **real production graph**
(the 2.5GB `uk-latest.osm.pbf`, 56.9M nodes / 117M edges) plus a real-OSM Bristol
extract, not just theory.

---

## TL;DR recommendation

1. **Parallelise (Lever 1): do it.** Clean ~N×-cores win, near-zero correctness
   risk, small change isolated to `iznik-batch`. The routing graph is read-only
   and already serves concurrent requests. This is the right primary fix.

2. **Contraction hierarchies (Lever 2): not worth it for this workload.**
   - **Consistency:** CH/PHAST compute *exact* shortest-path distances, so the
     isochrone (nodes with time ≤ T) is **identical** to the current Dijkstra's,
     down to ~ULP float jitter at the boundary — immaterial against the 400m
     origin blur and ~1km polygon rasterisation. So CH would *not* change
     outcomes. (Evidence: CH prototype on the Bristol graph — see §4.)
   - **Speed:** CH would only speed up the **isochrone** phase, and measurement
     shows that phase is **3–15% of per-post cost** — the Dijkstra is *not* the
     bottleneck. The brief's premise ("the fundamental cost is the per-request
     Dijkstra") is contradicted by the data (§2). Even an infinitely fast
     isochrone saves ≤15% per post, vs ~N× from parallelising. CH also adds heavy
     per-mode preprocessing that must be persisted (the server rebuilds from PBF
     every start), for that ≤15% ceiling.

3. **The real routing-side win is elsewhere (bigger than CH):** the dominant cost
   is `nearestNodeForMode` called **once per freegler** inside the polygon (N grid
   searches; 68–91% of per-post CPU in dense areas). A deterministic-blur cache
   (exact) or an O(1) time-grid lookup (33–212× on that phase, §3) targets the slice
   that actually matters. This is a far better routing-side target than CH.

---

## 1. How a post's reach is actually computed

`handleRippleSchedule` (`iznik-routing-go/ripple.go`), per post, does:

1. **1 bounded Dijkstra isochrone** to `max_minutes` (30) drive
   (`Isochrone`, `dijkstra.go`) — explores only the reachable subgraph, pruned by
   an admissible haversine bound.
2. **1 full `IsochronePolygon`** over the reached set → WKT for the spatial query.
3. **1 HTTP POST** to `spatial-knn /v1/userapproxlocs/within_coords` with that WKT
   → returns **every freegler inside the polygon** (N points), JSON-unmarshalled.
4. **`nearestNodeForMode` once per freegler** (N grid searches) to map each to a
   reached node and read its drive-time, building the cumulative-time distribution.
5. **9 per-tick `IsochronePolygon`s** (ticks = `len(hazard_hours)` = 9): filter the
   reached set by each tick's drive-time cutoff (O(reached)) and rasterise.

`ReachService` then stores the schedule and `ExpandService` ripples the post into
the covered groups. The loop over posts is sequential; each post blocks on step 3's
HTTP call.

---

## 2. Measured cost structure (the decisive finding)

Real UK graph (`uk-latest.osm.pbf`, 56.9M nodes / 117M edges; builds in **2m7s**,
~13GB `Sys` / 3.1GB live heap). One process, 30-min drive, per-phase wall time:

| Origin | reached nodes | Dijkstra | full poly | 9 tick polys | nearest/call | nearest×50k |
|---|--:|--:|--:|--:|--:|--:|
| London-central | 378,511 | **542 ms** | 49 ms | 844 ms | 293 µs | **14.7 s** |
| Birmingham | 600,664 | 1.12 s | 118 ms | 1.40 s | 112 µs | 5.6 s |
| Manchester | 659,637 | 1.50 s | 148 ms | 1.50 s | 135 µs | 6.8 s |
| Reading (suburb) | 240,843 | 275 ms | 29 ms | 343 ms | 79 µs | 3.9 s |
| Norwich | 193,982 | 160 ms | 18 ms | 190 ms | 62 µs | 3.1 s |
| rural-Devon | 35,105 | 21 ms | 1 ms | 26 ms | 18 µs | 0.9 s |
| rural-mid-Wales | 1,197 | 1 ms | – | 1 ms | 4 µs | 0.2 s |

`nearest×N` uses N = freeglers inside the polygon. In dense areas (which rippling
targets and which dominate the ~25s/post) N is large — tens to >100k. London at
N=100k spends **29s** in `nearestNode` alone.

**Dijkstra's share of per-post CPU** (Dijkstra ÷ (Dijkstra+polys+nearestNode), N=50k,
*excluding* the within_coords HTTP+JSON which adds further O(N) cost):

| Origin | Dijkstra share | nearestNode share |
|---|--:|--:|
| London | **3.4%** | 91% |
| Birmingham | 13.6% | 68% |
| Manchester | 15.1% | 68% |

**The Dijkstra is 3–15% of per-post cost; per-freegler `nearestNode` snapping is
68–91%.** A faster isochrone (CH/PHAST) attacks the small slice.

> Why `nearestNode` is so costly in dense areas: `nearestNodeGrid` scans every
> node in nearby ~1km grid cells; dense cells hold thousands of nodes, so it is
> *slower per call* **and** *called more often* (more freeglers) exactly where it
> hurts.

Bristol extract (158k nodes) corroborates the scaling: `nearest×50k` = 2.2–3.5s
while the Dijkstra is 0.2–34ms across 2–30 min budgets (see appendix).

---

## 3. The bigger routing-side win: fix the per-freegler snap

Replace `nearestNodeForMode` per freegler with a one-off **time-grid**: bucket the
reached nodes into the cells of the resolution the polygon already uses
(`AutoResolution`), keeping the min arrival time per cell; each freegler's
drive-time is then an O(1) cell lookup. O(reached) build + O(N) lookups instead of
O(N × grid-search).

Measured on the real UK graph (30-min drive, N=50k freeglers). Speed/accuracy
tradeoff over grid resolution (`current` = `nearestNode` per freegler):

| Origin | current | grid @ 222m | grid @ 111m | grid @ 56m | err mean/p50/p95 (@111m) |
|---|--:|--:|--:|--:|--:|
| London | 10.05s | 38ms (**267×**) | 47ms (**212×**) | 79ms (127×) | 25 / 14 / 88 s |
| Birmingham | 4.15s | 89ms (47×) | 127ms (33×) | 162ms (26×) | 29 / 14 / 107 s |
| Reading | 3.14s | 22ms (141×) | 46ms (68×) | 54ms (58×) | 25 / 11 / 92 s |

So a ~110m time-grid turns the 68–91% slice into ~50–130ms — **a 33–212× cut of
the dominant phase**, two orders of magnitude more than CH could ever give the
3–15% Dijkstra slice.

**Honest caveat — this is an approximation, unlike CH which is exact.** The grid
assigns each freegler the min drive-time in its cell, so per-freegler error at
~110m is mean ~25–30s, p50 ~14s, but with a long tail (p95 ~90s, rare outliers to
~10 min near motorway-adjacent cells/voids). Two things make this acceptable *for
the schedule*: (a) the stored artifact is the 9-tick **cumulative** drive-time
distribution over a 30-min range, into which roughly-symmetric ±30s per-freegler
errors largely wash out; (b) the origin is already 400m-blurred and the polygon is
rasterised at 1.1km, so the pipeline is already this coarse. If exactness is wanted,
two variants avoid even this approximation: have `within_coords`/spatial-knn return
each freegler's nearest graph node directly (it already has a spatial index), or
dedupe freegler coords (`users_approxlocs` are blurred, so many collide → cache the
snap). Any of these dwarfs CH.

> Root cause of the current slowness: `nearestNodeGrid` scans *every node* in
> nearby cells computing haversine; dense London cells hold thousands of nodes, so
> each of the N calls is expensive. A coarser lookup or a returned-nearest-node
> removes the per-freegler scan entirely.

---

## 4. Contraction hierarchies: consistency and speed

To answer this with evidence (not just theory) I built and verified a real CH +
PHAST one-to-all sweep on the Bristol graph (drive mode, 94,826 drive nodes /
182,941 drive edges → 170,545 shortcuts, 0.85s preprocessing).

### Consistency — CH gives IDENTICAL outcomes

CH/PHAST compute *exact* shortest-path distances (a shortcut is only inserted when a
witness search confirms it is a true shortest path), so the isochrone `{node :
dist ≤ T}` is identical to Dijkstra's. Verified:

- **Point-to-point cross-check:** 1,000 random (source,target) pairs, CH vs plain
  Dijkstra → **0 mismatches**, max distance difference **2.8 ms**.
- **Isochrone comparison** (3 origins × {5,15,30} min = 9 cases): **every case
  `set_identical = true`, symmetric difference = 0**, max per-node arrival-time
  difference **0.09–1.71 ms** — pure float32 summation-order jitter, boundary-only.

| origin | min | Dijkstra reached | CH reached | identical | max time diff |
|---|--:|--:|--:|:--:|--:|
| central Bristol | 30 | 91,193 | 91,193 | ✅ | 1.34 ms |
| Clifton | 30 | 91,577 | 91,577 | ✅ | 1.34 ms |
| Brislington | 30 | 90,783 | 90,783 | ✅ | 1.71 ms |

The only divergence sources are (i) float32 summation order over shortcuts vs path
relaxation (ULP-level, can only flip nodes whose time is within an ULP of the T
cutoff — using integer milliseconds would remove even that), and (ii) the existing
admissible haversine prune in `Isochrone()` (could differ by 1–10 boundary nodes on
the full UK graph, all within ε of the cutoff). None is observable after the 400m
origin blur + ~1km polygon rasterisation. **So CH would not change which groups a
post ripples into.**

### Speed — real, but it's the wrong slice

- Plain point-to-point CH is the **wrong tool** for one-to-many isochrones (no
  target to meet in the middle / stall against). The one-to-all tool is **PHAST**;
  **RPHAST** needs a *known* target set, which an isochrone — whose answer *is* the
  reachable set — cannot provide. The fast modern isochrone methods (isoPHAST,
  Baum/Buchhold et al. 2015) layer **graph partitioning** on top of CH, not CH
  alone.
- PHAST does work proportional to the **whole graph** (a linear sweep over all
  nodes, fixed ~ms overhead) regardless of T. Measured on Bristol:

  | minutes | reached | bounded Dijkstra | PHAST | ratio |
  |--:|--:|--:|--:|--:|
  | 5 | 4,926 | 1.4 ms | 2.4 ms | **0.6× (PHAST slower)** |
  | 15 | 62,250 | 23.9 ms | 7.1 ms | 3.4× |
  | 30 | 91,193 | 34.2 ms | 7.2 ms | 5× |

  Bristol *flatters* PHAST: a 30-min drive covers 96% of its nodes, so the
  full-graph sweep is fully amortised. On the 57M-node UK graph a 30-min drive
  reaches only ~0.5–1% of nodes (London 378k / 57M), so PHAST would still sweep all
  57M while bounded Dijkstra prunes at the cutoff — the literature (Baum et al.) is
  explicit that for **small bounded T, plain RangeDijkstra is competitive or faster
  than PHAST**. The headline "1000× CH isochrone" figures are for multi-hour /
  continental ranges, not a 30-min local isochrone.
- **Preprocessing & persistence cost:** CH is per scalar metric → the graph's **3
  modes (walk/cycle/drive) need 3 CHs**. Realistic preprocessing on a 57M-node /
  117M-edge graph is **~15–40 min per mode** (OSRM-class, sequential) and the
  augmented graph adds roughly **+1× edges as shortcuts (~9–15 GB across 3 modes)**.
  Crucially the server **rebuilds the plain graph from the PBF on every start
  (2m7s) with no cache**, so a CH would have to be **serialised to disk and reloaded**
  (and rebuilt on every map update) — new build-pipeline + storage + staleness
  logic the server does not have today.

**Net:** CH is exact (good) but only accelerates the **3–15%** Dijkstra slice, is
not even faster than the current bounded Dijkstra for a 30-min isochrone, and costs
3× preprocessing + a persistence subsystem. A 10× Dijkstra speedup saves ≤135 ms of
a ~25 s post. **Not worth it.**

---

## 5. Parallelise (Lever 1) — design

**Approach: chunked `Http::pool()`, two phases per chunk.** Refactor
`initialiseNew`/`advanceDue` so that, for each chunk of up to
`RIPPLE_COMPUTE_CONCURRENCY` (env, default 8) posts:
- **Phase 1 (parallel, read-only):** fire all `ripple-schedule` GETs concurrently
  via `Http::pool()` (Guzzle `curl_multi`). No DB access. `Http::pool` is already
  used elsewhere in the codebase (`EeeVisionService`), so no new infrastructure.
- **Phase 2 (serial, ordered):** iterate responses in original post order and run
  the *existing* per-post DB sequence unchanged (`rippling_reach` INSERT/UPDATE →
  `rippleIntoNewGroups` → `addPosterMembershipToRippledGroups` →
  `mailNewlyReachedForPost`). One writer, strict order → **no Galera multi-writer
  conflicts, no schema/locking changes.**

Requires extracting `ReachService::parseScheduleResponse()` from the bottom half of
`computeSchedule()` so Phase 2 parses the pooled JSON without a second HTTP call
(`computeSchedule()` stays as the single-post wrapper for tests/dry-run).

- **Concurrency cap = routing-host physical cores** (default 8). Each request is
  CPU-bound on the routing host (1 Dijkstra + 10 rasterisations + N `nearestNode`),
  so N concurrent requests need N cores; beyond core count you get scheduler
  queueing and tail latency, not throughput.
- **Server safety:** the Fiber/Go graph `g` is built once and captured read-only;
  every per-request structure (`dist` map, `fwt` slice, tick `filtered` maps) is
  freshly allocated. `cmd/ripplesim --workers=8` already hammers it concurrently in
  practice. Per-request peak heap ≈ 20–25 MB on a London origin → ~160–200 MB at
  concurrency 8, negligible vs the ~13 GB baseline.
- **Failure/timeout:** a pooled timeout/5xx surfaces as a `Throwable`/non-2xx
  `Response`; Phase 2 treats null schedules as `skipped` (retried next cron), exactly
  as today. **Raise the per-request timeout to 60s on the pool path** so one
  pathological slow post times out gracefully without holding back the chunk's fast
  posts. Crash mid-chunk is safe: `initialiseNew`'s `LEFT JOIN ... WHERE mr.msgid IS
  NULL` re-issues only the un-written posts (idempotent).
- **Expected speedup: ~5–7×** on a mixed UK batch at concurrency 8 (chunk wall time
  = slowest routing call + serial DB writes). The `--limit=100` run that took **40
  min drops to ~6–7 min**. Note this only *parallelises* the per-post cost; it does
  not reduce it — which is why §3 (the `nearestNode` fix) compounds with this.

> Guardrail to encode in code: a comment at the chunk loop that **Phase 2 must stay
> serial** — a future refactor moving it into goroutines/parallel PHP would
> reintroduce Galera multi-writer contention.

## 6. Recommended order of work

1. **Parallelise `ExpandService` (Lever 1)** — ~5–7×, isolated to `iznik-batch`,
   near-zero risk. Do first.
2. **Dedup by blurred origin — no cache needed.** `computeSchedule` is
   deterministic per blurred origin, and `computeSchedule` is called once per post
   *at init* (`advanceDue` reuses the stored schedule). So group each fetched batch
   by `blurOrigin(lat,lng)` in PHP and compute **once per distinct origin**, applying
   the result to every post that shares it. Exact, no accuracy loss.
   - **Measured on prod** (`messages_spatial`, 2026-06-24, apiv2-live tunnel):
     53,850 active posts share only **20,672 distinct origins → 2.6× dedup ceiling**
     (62% of routing calls are redundant). The distribution is very skewed — the
     hottest origins carry 275 / 205 / 198 posts each (postcode centroids), so even
     a *chronological* batch already dedups: 500→344 (1.45×), 2000→1302 (1.54×),
     5000→2952 (1.69×).
   - **No SQL `ORDER BY` and no Redis cache.** In-batch grouping captures the
     duplication regardless of row order; to approach the 2.6× ceiling just use a
     **larger batch** (the eligible set is only tens of thousands of rows). Memory =
     distinct origins in the current batch (≤ batch size), freed after the run — it
     is bounded by *posting origins* (~20.7k), never by user count. (An `ORDER BY
     ST_Y/ST_X` would filesort and defeat the `LIMIT` early-stop — harmless at 53,850
     rows but unnecessary; a bigger batch is strictly better.)
3. **Kill the `nearestNode` per-call cost (§3)** for the computes that remain after
   dedup — the real bottleneck (68–91%). Cheapest first:
   - **Time-grid snap** (server-side, ~110m): 33–212× on the phase, ~25–30s mean
     drive-time error that washes out in the 9-tick schedule (§3).
   - **Return nearest-node/drive-time from `within_coords`** (spatial-knn protocol
     change): removes the per-freegler snap entirely, exactly.
   - **Parallelise the 9 tick polygons in-request** (goroutines): 844 ms → ~100 ms.
4. **Do *not* invest in contraction hierarchies** for this workload (§4).

---

## Appendix — methodology & raw data

- Benchmarks: `iznik-routing-go/ripple_profile_test.go` (Bristol),
  `ripple_uk_profile_test.go` (real UK), `ripple_nearestfix_test.go` (time-grid).
  Run with `CGO_ENABLED=0` (pure-Go zlib path; cgo/pkg-config unavailable on host).
- The real UK PBF is not committed (2.5GB); production builds it via
  `spatial:update-data` (osmium merge of Geofabrik GB + Ireland). For this study it
  was loaded from a local copy. UK graph: 56.9M nodes / 117M edges, builds in 2m7s,
  ~13GB `Sys`.
- The CH prototype (drive mode, Bristol) was built + verified in an isolated
  worktree: 1,000-pair point-to-point cross-check vs Dijkstra = 0 mismatches;
  9/9 isochrone comparisons identical to `Isochrone()`.

Bristol raw (drive, central origin 51.4545,-2.5879; graph 158,625 nodes /
330,228 edges; the `nearestNode×N` simulates N freeglers):

| minutes | reached | Dijkstra | full poly | 9 tick polys | nearest×1k | nearest×10k | nearest×50k |
|--:|--:|--:|--:|--:|--:|--:|--:|
| 2 | 217 | 0.20 ms | 19 µs | 0.15 ms | 73 ms | 754 ms | 3.53 s |
| 5 | 4,926 | 1.38 ms | 95 µs | 1.74 ms | 60 ms | 634 ms | 3.29 s |
| 10 | 29,420 | 10.8 ms | 1.0 ms | 11.9 ms | 59 ms | 564 ms | 2.70 s |
| 20 | 79,444 | 27.9 ms | 1.6 ms | 41.4 ms | 44 ms | 414 ms | 2.31 s |
| 30 | 91,193 | 33.9 ms | 2.1 ms | 54.0 ms | 43 ms | 444 ms | 2.23 s |

### Key references (CH / PHAST / isochrones)
- Geisberger, Sanders, Schultes, Delling (2008), *Contraction Hierarchies*, WEA.
- Geisberger et al. (2012), *Exact Routing in Large Road Networks Using CH*, Transp. Sci. 46(3).
- Delling, Goldberg, Nowatzyk, Werneck (2013), *PHAST: Hardware-Accelerated Shortest Path Trees*, JPDC.
- Delling, Goldberg, Werneck (2011), *Faster Batched Shortest Paths in Road Networks* (RPHAST), ATMOS.
- Baum, Buchhold, Dibbelt, Wagner et al. (2015), *Fast Computation of Isochrones in Road Networks*, arXiv:1512.09090.
- Wan et al. (2024), *Parallel Contraction Hierarchies Can Be Efficient and Scalable* (SPoCH), arXiv:2412.18008.
