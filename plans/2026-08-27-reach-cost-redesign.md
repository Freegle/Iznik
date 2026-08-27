# Reach cost redesign: stop recomputing, then make one computation near-free

Date: 2026-08-27. Context: three host death-spirals in one day traced to the cost of
generating reach grids. Containment shipped same day (PR #1435 cron single-instance locks,
PR #1436 routing compute gate); this plan removes the cost itself. Design agreed in
conversation with Edward; the entry/exit pairwise formulation in stage 2 is his.

## The measured problem

- Schedules are computed ONCE per post at init (good), but slim (`polygons=0`), so **every
  advance of every post calls `/v1/catchment`: a fresh full Dijkstra to the current radius**,
  re-exploring everything inside the previous radius, tracing a ~20-37k-vertex polygon,
  shipping WKT, then batch re-rasterises the ENTIRE area to cells and re-applies every group
  clip over the full grid. Next tick: all of it again, one radius bigger.
- 8,182 posts actively expanding; >=3,370 advances/day; every post near another repeats the
  same road exploration. proximity-notes / maxreach / digest-sim add their own searches.
- Each search's cost structure: graph adjacency is already CSR (fine); the transient cost is
  the RESULT maps (`dist`/`distM` as Go maps, ~50B/entry, millions of entries -> hundreds of
  MB per query + GC churn). Unbounded concurrency of those transients was the 26GB routing
  bloat. polygon_cells grids themselves are small (avg 20KB, max 50KB RLE at 0.0003 deg).

## Stage 1 — per-tick cell deltas, computed once at init (ships with today's engine)

The init-time search already yields arrival-time per node. Tick T's cells = cells first
reached in (T-1, T]. So:

1. `/v1/ripple-schedule` gains `cells=1`: after its one max-extent Dijkstra it rasterises
   arrival times Go-side into the 0.0003-deg grid and returns **RLE per-tick cell deltas**
   (same encoding as polygon_cells). No polygon tracing — post-#1419 reach membership is
   cell-based; the 37k-vertex boundary on this path is display baggage.
2. Stored schedule = tick metadata + deltas (delta total ~= final grid size, i.e. tens of KB
   — far below the old 24MB polygon schedules).
3. **Advance = `polygon_cells ∪= delta[tick]`** — a streaming run-boundary merge (sibling of
   `subtractEncoded`), clips applied to the DELTA only. No routing call, no Dijkstra, no WKT,
   no full-area rasterise. Seconds+GB-transients per advance -> milliseconds+KB.
4. max reach falls out free (union of all deltas); overflow lanes stay init-time as now.
5. In-flight posts keep the catchment path until their slim schedules drain (~days); only
   newly initialised posts get deltas. Old-server/new-batch and new-server/old-batch both
   degrade to current behaviour.

Result: rippling's routing load drops from per-advance to one search per post lifetime
(thousands/day, a few per minute).

## Stage 2 — connectivity-native reach: min-cut partitions, snapped members, tree labeling

Edward's critique of the first stage-2 draft (spatial cells + per-cell entry/exit matrices):
grids are a fundamentally SPATIAL basis, but reach is a property of the weighted graph's
metric. Geometric cells cut across connectivity (one-bridge rivers, motorways) and pay for
it in boundary-matrix size and semantic patches. Revised design is graph-native throughout:

1. **Partition the graph by minimum edge-cut** (nested, 2-3 levels — the tree), not by
   geometry. Region boundaries fall on connectivity's natural seams: few crossings,
   rivers/estuaries respected structurally. Per-region pairwise entry/exit through-time
   matrices (directed boundary nodes) make composition EXACT — this part of the earlier
   draft survives, on better partitions. (This is Customizable Route Planning with the
   partitioner doing the work; contraction hierarchies remain the fallback comparison.)
2. **Members are snapped to a graph node once**, stored, refreshed on location change
   (per mode). Independent immediate win: per-freegler nearestNodeForMode is the documented
   dominant cost of ripple-eval ("seconds in a dense area, dwarfing the isochrone") and is
   recomputed today for people who have not moved.
3. **Reach per post = a labeling of the partition tree**: each region fully-in / fully-out /
   partial (entry arrivals stored). RLE in METRIC space — compact like the grid for the same
   reason, but exact about connectivity (the unbridged bank is fully-out however close it
   looks). Membership test = walk to the member's leaf region (known at snap time);
   fully-in/out answers immediately, partial does one min-over-entries add. Microseconds.
4. **Grid and polygons become projections only**: display, and the sloppy-but-cheap SQL
   R-tree prefilter (outer_bound survives as a projection; losing that index was the
   2026-08-21 outage, so it stays).
5. Artifact lifecycle unchanged from the earlier draft: partitions + matrices + serialised
   graph are disk artifacts rebuilt monthly with OSM data, ending rebuild-on-every-start
   (each routing restart today is a 5-7 minute outage window).
6. Gate before committing: prototype the partitioner on the real UK graph — measure cut
   sizes (urban vs trunk vs estuary), matrix totals, build time; and the membership-test
   path end-to-end for one post against today's cell answer.

Stage 1 is unaffected by this revision: delta schedules remove RECOMPUTATION regardless of
representation (they are compute-once-committed, not grid-committed); the stored delta
encoding can move from grid-RLE to tree labels when stage 2 lands.

## Interim engine tweaks (orthogonal, cheap)

- Replace `dist`/`distM` maps in `Isochrone` with per-worker epoch-stamped flat arrays +
  index heap where worker count × 456MB is acceptable, or defer until stage 2 shrinks the
  array to the overlay. Kills the transient-map bloat class entirely.


## Literature grounding (reviewed 2026-08-27)

- Canonical survey: Bast/Delling/Goldberg et al., "Route Planning in Transportation
  Networks" (arXiv:1504.05140). Families: CH (us queries, exploits the road hierarchy),
  CCH (topology once, metric customized in seconds), CRP/MLD (nested min-cut partitions +
  boundary cliques = OUR stage-2 shape, low-ms queries), Hub Labeling, PHAST/RPHAST
  (one-to-all/one-to-many sweeps).
- **Our exact problem has a paper**: Baum/Buchhold/Delling/Wagner, "Fast Computation of
  Isochrones in Road Networks" (arXiv:1512.09090; journal "Isocontours", ACM JEA 2019).
  isoCRP / isoGRASP / isoPHAST: few-ms isochrones on continental graphs. Their query marks
  partition regions fully-in/fully-out/boundary and descends only into boundary regions —
  the same "reach = partition-tree labeling" formulation as stage 2. Boundary POLYGON
  extraction is treated as a separate optional problem (ESA 2016 minimum-link isocontours)
  — grid/polygon as projection, confirmed.
- Partitioning is off-the-shelf: PUNCH natural cuts (designed for road networks; rivers/
  motorways emerge as boundaries), KaHIP (best cuts on Europe road graph), FlowCutter /
  InertialFlowCutter (nested-dissection orders for CCH). Do not hand-roll.
  **Ford-Fulkerson is the engine underneath** (Edward's observation): max-flow min-cut
  duality finds the seams constructively — Inertial Flow = one unit-capacity max-flow
  between directional extremes; FlowCutter = incremental flows enumerating cut/balance
  trade-offs. Unit capacities + tiny road cuts make augmenting paths O(E x cut) i.e.
  genuinely practical. A region's boundary count IS its max-flow value, and our pairwise
  entry/exit matrices are quadratic in it — the partitioner directly minimizes our
  storage. Prototype gate restated: measure the certified cut values per seam
  (estuaries should be single digits; urban sprawl is the stress case).
- Engines: OSRM = CH; Valhalla = tiled multi-level + request-time costing (closest to our
  artifact lifecycle); GraphHopper isochrones = plain shortest-path tree (what we do today
  — fine at city scale/low QPS, not at ours).
- **Stage 0 discovered by comparison**: literature's Western Europe = 18M vertices; our
  UK-only graph = 57M because every OSM way-point is a graph node. Contract degree-2
  chains into single weighted edges (geometry as edge attribute for display) -> ~3-5x
  smaller graph, every search and scratch array shrinks, zero algorithmic risk.
- CCH topology/metric split maps onto our drive/walk/cycle: one partition artifact,
  three cheap metric customizations.

## Sequencing

0. Stage 0: degree-2 chain contraction in the graph build (cheapest big win, no design risk).
1. Stage 1 now (batch + routing, branch-and-PR, CI validates; biggest cost removed).
2. Flat-scratch tweak opportunistically.
3. Stage 2 prototype -> measurements -> full build as its own project.
