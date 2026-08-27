# Stage 2 build: connectivity-native reach (partition prototype → gate → engine)

Date: 2026-08-27. Parent design: `plans/2026-08-27-reach-cost-redesign.md` stage 2.
Direction set by Edward: build stage 2 direct (skip stages 0/1/interim as standalone work),
validate against prod data via the read-only tunnel. Adversarial review of the parent plan
(23-agent run, same day) confirmed the cost diagnosis and recommended folding the degree-2
contraction into stage-2 partitioner prep — that is what this does.

Worktree: `FreegleDocker-reach-stage2`, branch `feature/reach-stage2-connectivity` (base
origin/master 7e51d744f). Compute-only: worktree containers stopped; everything runs with
host Go against `iznik-routing-go/data/uk-latest.osm.pbf` (hardlinked from main checkout).

## The gate (from the parent plan, §stage 2 item 6)

Prototype the partitioner on the real UK graph — measure cut sizes (urban vs trunk vs
estuary), matrix totals, build time; and the membership-test path end-to-end for real posts
against today's cell answer (prod `rippling_reach.polygon_cells` via the tunnel).

## Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Worktree + branch + plan | ✅ | containers downed to free RAM; pbf hardlinked |
| 2 | Overlay: per-mode-safe degree-2 chain contraction + chain table + tests | ✅ | stage2_overlay.go; UK 56.87M→12.93M junctions (4.4x) in 30.7s; TestOverlay* incl bristol offset parity |
| 3 | Artifact snapshot: serialize overlay+chains, load in seconds | ✅ | 4.7GB, save 2.5s load 5.0s (vs ~150s PBF rebuild); round-trip test |
| 4 | Partitioner: Dinic max-flow + Inertial Flow nested bisection + tests | ✅ | bridge cut=2 found; 50×50 grid cut ≤55 (one row); bristol invariants |
| 5 | UK partition run: cut sizes, balance, depth, build time | ✅ | 4m22s; cut p50/p90/max 9/17/85; estuaries single-digit |
| 6 | Region matrices: directed boundary entries/exits, entry×exit, ecc + tests | ✅ | stage2_matrix.go; validated via task-7 exactness (matrix path exercised) |
| 7 | Labeling query: origin→exits, boundary-overlay Dijkstra, tree labels; exactness vs flat Dijkstra | ✅ | bristol: 127k arrivals EXACT (≤0.01s) across 4 origins incl chain-origin; 0 false memberships; Full-region ecc sound |
| 8 | Prod parity harness: tunnel posts + polygon_cells vs labeling | ✅ | 0/571k exactness; 3-way comparison decomposes all divergence |
| 9 | Measurements → this file + parent plan; gate verdict recorded | ✅ | gate PASS |
| 10 | Full Go suite + gofmt + quality review + PR | ✅ | 180 pass 0 fail; PR #1438 |

## Design decisions

**Contraction is partitioner prep, not a standalone stage-0.** Per-mode-safe rule from
routing-performance-step-change.md §3: a node contracts only if, for EVERY mode with any
usable incident edge, it is a pure pass-through between the same neighbour pair {a,b}
(two-way both sides, or a consistent oneway through-pattern). Penalties are already inside
edge Seconds at build time (graph.go builds them into the per-edge drive seconds), so
summing per-mode seconds along a chain is exact. Chain table keeps (endA, endB,
offsetSecs per mode from each end) per absorbed node → point lookups stay EXACT
(arrival(v) = min over ends(arrival(end) + offset(end→v)) respecting oneway direction).
No geometry is stored on contracted edges: stage 2 never rasterises (grid/polygons are
projections owned by the existing pipeline), so the water-chord trap from the adversarial
review does not apply to this engine.

**Partitioning: Inertial Flow with Dinic (unit capacities) on the drive overlay,**
undirected view, 4 direction axes (lat, lng, two diagonals), source/sink = extreme ~25%,
best cut by cut/balance trade-off, recursive to a size-thresholded tree. Components
partitioned separately (Orkney etc). This is the published Inertial Flow algorithm
(Schild/Sommer), not a hand-rolled scheme; PUNCH/KaHIP/FlowCutter remain candidates for
the full build if cut quality disappoints — measuring that is the point of the gate.

**Query = CRP-style two-phase:** exact local Dijkstra origin→own-region exits, then
boundary-overlay Dijkstra (per-region entry×boundary clique matrices + cut chains), then label
every leaf fully-in / fully-out / partial: fully-in iff min over entries(arrival+ecc(entry))
≤ T (conservative upper bound — a false "partial" only costs work, never correctness);
partial stores entry arrivals. Membership in a partial region =
min over entries(arrival + intraDist(entry, node)), intraDist from a per-REGION
(post-independent) entry→node table, computed on demand and LRU-cached. Exact because the
final segment of any shortest path after its last entry crossing is intra-region, and
arrival[] at every boundary node is globally exact (re-entering paths included).

**Prototype computes Drive only** (reach is drive-based) but all structures carry per-mode
seconds so walk/cycle are metric refills, not redesigns.

**Parity ground truth is two-layered:** (a) labeling vs local flat Dijkstra on the same
graph = must be exact (this is the correctness gate); (b) labeling vs prod polygon_cells =
expected to differ at fill/boundary (grid interior fill, water banks, clips) — quantified
and characterized, not required to match. Prod drive-speed factor must match the local
build env (ROUTING_DRIVE_SPEED_FACTOR) or (b) is meaningless — verify before comparing.

**Prod access:** read-only tunnel per reference_live_db_v2_tunnel_readonly (WINIP:1234,
creds from main-checkout .env). SELECTs only, bounded; blobs exported to files by a shell
script; the Go harness reads files, never the DB.

## Measurements (gate)

All measured this session on the 20-core / 94GB WSL2 box against uk-latest.osm.pbf
(2026-07-06 vintage, same file the July study used).

### Overlay contraction
- Base graph: 56,874,451 nodes / 117,157,737 directed edges (matches July's numbers).
- First build: **12,927,428 junctions / 31.46M chain edges (4.4x node, 3.7x edge)** in
  30.7s single-threaded — within 0.2% of July's independently-measured 12,899,893
  junction count. Drive subgraph: 9.96M junctions / 21.1M directed chain edges.
- 77.3% of base nodes absorbed; chain drive-seconds p50/p90/p99/max = 5.4/19.3/63.5/964.
- Snapshot artifact: 4.7GB, save 2.5s, load ~5s — replaces the 90-150s PBF rebuild
  (prod restarts today are a 5-7min outage; artifact load is the stage-2 fix, proven).
- Exactness: chain-offset reconstruction == flat Dijkstra on bristol, and (final rule)
  mode-disjoint parallel edges block contraction (see Defects found below).

### Partition (Inertial Flow + multi-source/multi-sink Dinic, leaf ≤ 10k, alpha 0.25)
- Input: 9,956,528 drive junctions / 10.94M undirected edges; 22,186 components
  (largest 7,857,935 — the mainland; the rest are the known tiny drive fragments).
- **Build 4m22s wall** (first implementation was serial-per-axis and heap-heavy:
  projected hours; flat-CSR arcs + 4 concurrent axes + BFS sink-level truncation
  fixed it). 1,489 flow bisections, max depth 14; leaves ≤ 10k as configured.
- **Cut quality: p50 = 9, p90 = 17, max = 85.** Splitting the ENTIRE mainland in two
  severs 71 roads. The 13 top-level (>1M junction) splits cut 85/71/67/54/50/44/42/36/
  32/26/25/22/13.
- **Estuary seams are single-digit and found autonomously** (nearest flow-cut to each
  anchor): Severn 8 (2km off), Humber 9 (7km), Thames-East 8 (5km), Forth 6 (5km),
  Mersey 9 (2km), Tyne 10 (5km). Max-flow found the water. Urban stress max 85
  (M4/Thames-valley belt) — matrix cost stays trivial even there.
- Bisection wall p50 17ms; the single whole-mainland split 121s.

### Region matrices (entry -> whole-boundary, after the Southend fix)
- **Build 1.8s** (parallel). 27,670 directed entries / 27,666 exits UK-wide;
  644,467 matrix cells = **2.6MB float32 total**; 27,726 cross edges.
  The parent plan's storage worry (matrices quadratic in boundary) lands at 2.6MB.
- 27% of entries fully cover their region internally (finite ecc) -> conservative
  fully-in stays available without any correctness risk from oneway substructure.

### Query (UK graph, warm artifacts)
- Bristol 30-min: **1.0-1.2ms** (cold first query 14.5ms incl. lazy region tables);
  Chester 38-min: 1.7ms. vs 286-310ms flat Dijkstra for the same sweep (~250x), and
  under the successor model advances need NO recompute at all — membership is
  answered from stored labels in microseconds.
- Label sizes on real posts: **~0.6-3.8KB vs 2-46KB stored cells** (and vs ~24MB
  pre-#1406 polygon schedules).

### Prod parity (13 real posts via read-only tunnel, dense/medium/sparse + estuaries)
- **Exactness vs flat Dijkstra: 0 mismatches / 571,230 probes** (worst Δ 0.0049s =
  float32 summation noise) after the two defects below were fixed. This is the
  correctness gate and it is fully green.
- Three-way membership on 646,956 lattice samples:
  - **prod-vs-local-pipeline 99.96%** — prod's stored cells are what the same
    algorithm computes here (no graph-vintage or speed-model skew; the 0.04% is
    the Southend post's origin-group union + trace noise). The comparison is
    therefore measuring exactly what it claims to.
  - **prod-vs-engine 86.15% == local-vs-engine 86.12%** — every engine/prod delta
    is the PROJECTION difference on one graph, decomposed:
    - snapFar 12.75%: sample points >60m from any road (fields, water, moor). The
      grid fills them by area; the metric has no road there. Not a member scenario
      — and it includes the unbridged-far-bank cases the parent plan calls stage
      2's correctness WIN.
    - boundary 0.80%: ±1-cell / ≤90s frontier quantization.
    - structural 0.27%: frontier fingers/pockets the adaptive-resolution trace
      (NetworkResolution at 40km scale) smooths away but the exact metric
      resolves. These are current-pipeline artifacts, not engine errors.
    - fill 0.02%.
- Per-post query 1.5-26ms (cold incl. lazy tables) vs 29-229ms flat; labels
  0.6-3.8KB vs 2-46KB cells.

### Fictional fuzz sweep (final, after partition speed-up + relative tolerance)
- 754 origins = 359 real posts + 612 fictional (random UK points incl. sea/moor —
  256 legitimately fail to snap and are skipped — mid-chain starts, junction starts,
  sliver regions, budgets 1-120min) + 39 region synthetics. 5,167,704 arrival probes:
  **0 mismatches** live AND stored (tolerance 0.01s + 10ppm — float32 summation order
  legitimately wobbles ~3ppm at 90-minute arrivals). 8 flips = budget ties.
- **False membership: 0 of 3,004,250 probes** on nodes the true search did not reach.
- Query mean 12.9ms (scattered cold origins), flat search mean 73ms.
- The fuzzer itself had a signed-modulo bug on first run (crash, fixed); the fixed
  0.01s absolute tolerance was replaced by the relative one after 790 ppm-scale
  "mismatches" at 90/120-min budgets were confirmed to be summation-order noise.

### Partition speed-up (second round)
- Each axis contracts its extreme alpha sets into super source/sink (exact — edges
  inside a contracted set can never cross an s-t cut) so the flow network holds only
  the middle band; BFS is level-synchronous and parallel on big bands.
- Whole-mainland split 121s -> 49s; whole-UK partition **4m22s -> 2m54s**, identical
  winning cuts. Boot-time choice: derive partition+tables in ~3min from the graph
  snapshot, or load 95MB of stored artifacts in ~1s. Table cache 64 -> 512 regions
  (~300MB worst) after concurrent sweeps thrashed it.

### UK-wide sweep (after the 13-post parity)
- 359 real posts, one to three per half-degree cell across the UK (read-only tunnel,
  no blobs), plus 266 synthetic origins planted in every sizable partition region the
  posts did not touch — all 1,508 sizable regions of the network exercised.
- **5,117,959 arrival checks vs the current engine's own metric: 0 mismatches**, on
  BOTH the live query result and the stored-label round trip (encode → decode → seed
  path). 7 membership flips of 5.1M = arrivals tying the budget within 10ms (float
  noise exactly at the threshold), not path errors.
- Query mean 6.5ms (cold caches at each new origin) vs 62ms flat-search mean.
- The sweep caught a third defect the 13 posts missed: two distinct parallel chains
  joining the SAME junction pair (a circular lane) fooled the origin-same-chain
  shortcut into applying one chain's offsets to the other. The shortcut now walks the
  origin's actual chain. Fixed + covered by the sweep.

### Memory (measured, ReadMemStats after GC)
- Base graph alone (what the server holds today): **3.18GB**.
- Full stage-2 engine (graph + overlay + chains + partition + matrices): **5.15GB**.
  The +1.97GB is the price of running BOTH worlds in one process during migration —
  every existing endpoint still needs the base graph. End-state (all endpoints on the
  overlay) drops the base edge list (~2.1GB) from reach's requirements.
- Per-query scratch: current flat search ≈ two hash maps over every reached node
  (~40MB at 30min, ~730MB at 120min — the class that piled up to 26GB in the
  death-spirals); engine query ≈ 2-4MB. ~100x smaller where it caused incidents.

### Defects found by the harnesses (all fixed, all regression-covered)
0. **Two parallel chains can join the same junction pair** (circular lanes): the
   origin-same-chain shortcut must walk the chain, not compare end pairs.
1. **Boundary graph must span entry -> every boundary node, not entry -> exit**: a
   shortest path can reach a sibling ENTRY internally; entry-only-via-cross-edges
   dropped up to 121s on Southend arrivals. Matrices now entry×boundary.
2. **Mode-disjoint parallel edges broke contraction**: a road plus a separately-mapped
   footway between the same two nodes passed the same-mode parallel check; the chain
   walk followed whichever came first in CSR order, silently dropping the road's
   drive seconds (26.56s hop lost). Any second edge to the same neighbour+direction
   now blocks contraction.

## Gate verdict

**PASS on every axis the parent plan set:**

1. **Cut sizes**: estuaries single-digit (Severn 8, Forth 6, Humber/Mersey 9), found
   autonomously by max-flow; whole-mainland split severs 71 roads; urban worst case 85.
2. **Matrix totals**: 2.6MB float32 for the whole UK — three orders of magnitude below
   any storage concern; boundary counts are what the partitioner minimizes and it works.
3. **Build time**: overlay 29s + partition 4m22s + matrices 1.8s = well under 6 minutes
   end-to-end from a cold graph, monthly-offline compatible; artifacts reload in ~5s,
   which also retires the 5-7min restart-outage class.
4. **Membership end-to-end vs today's cell answer**: exact against the engine's own
   metric (0/571k), and the residual vs stored cells is fully characterized as the
   grid-fill/trace-resolution projection — precisely the parent plan's "grid and
   polygons become projections only" framing, now with numbers.

Full build can proceed on this design. The two defects the harness caught (boundary
matrix scope; mode-disjoint parallel edges) are exactly the class of thing the gate
existed to find, and both carry regression tests now.

## Delivered in this branch beyond the gate

- Server integration: STAGE2_DIR artifact boot (missing partition/matrices derive at
  boot ~3min and save back; unset = the old PBF path untouched), GET /v1/reach-labels
  (gated) + POST /v1/reach-arrival (ungated), 503 until configured; endpoint +
  concurrency tests on bristol (suite now 182). Thread-safe region-table cache
  (mutex, 512-region cap) with arbitrary-source rows.
- Stored label wire format FRL1: encode/decode + seed-based evaluation proven
  IDENTICAL to live queries across the full UK sweep (every probe checked both ways).
- Docs: REACH-ENGINE.md plain-English walkthrough (incl. what the structure unlocks:
  point-to-point ~1-3ms with one extra reverse-tables artifact, many-to-many
  matrices, 1.8s metric refills, walk/cycle as metric fills), routing README
  (endpoints + STAGE2_DIR), spatial-servers page, architecture overview.

## What the full build still needs (next PRs)

- Batch adoption: store labels per post + membership read path (replacing
  polygon_cells as the membership TRUTH, with cells/polygons kept as
  display/prefilter projections).
- Reverse-direction region tables (enables point-to-point drive times).
- Walk/cycle metric fills (structures already carry per-mode seconds).
- Monthly artifact rebuild pipeline + OSM refresh hook; moving the remaining
  flat-search endpoints onto the corridor graph, after which a slim boot can skip
  the 2.1GB base edge list.
- The multi-level roll-up (tree labels above leaf level) if label sizes ever matter —
  at 0.6-3.8KB/post they do not yet.
