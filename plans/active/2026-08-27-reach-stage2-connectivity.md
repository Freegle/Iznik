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
| 5 | UK partition run: cut sizes, balance, depth, build time | 🔄 | running (top-level split of 7.86M-junction mainland) |
| 6 | Region matrices: directed boundary entries/exits, entry×exit, ecc + tests | ✅ | stage2_matrix.go; validated via task-7 exactness (matrix path exercised) |
| 7 | Labeling query: origin→exits, boundary-overlay Dijkstra, tree labels; exactness vs flat Dijkstra | ✅ | bristol: 127k arrivals EXACT (≤0.01s) across 4 origins incl chain-origin; 0 false memberships; Full-region ecc sound |
| 8 | Prod parity harness: tunnel posts + polygon_cells vs labeling | 🔄 | 13 posts exported (dense/medium/sparse + 4 estuary anchors); CCS1 reader tested; awaits UK partition |
| 9 | Measurements → this file + parent plan; gate verdict recorded | ⬜ | |
| 10 | Full Go suite + gofmt + quality review + PR | ⬜ | |

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
boundary-overlay Dijkstra (per-region entry×exit clique matrices + cut chains), then label
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

(to be filled by real runs — nothing recorded until measured)

## Gate verdict

(pending measurements)
