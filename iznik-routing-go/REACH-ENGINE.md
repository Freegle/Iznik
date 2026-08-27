# The reach engine, explained in plain English

This is the design behind the `stage2_*.go` files (the "stage 2" of the parent plan — the only stage built, so everywhere else it is just "the reach engine"): how a post's reach is computed
from the road network itself, why it is fast, and why the answers are exact.
Parent design: `plans/2026-08-27-reach-cost-redesign.md`; measurements:
`plans/active/2026-08-27-reach-stage2-connectivity.md`.

## The problem

A post's "reach" is the area within a growing drive-time of the poster, and the
question the platform asks millions of times is: *is this member's address within
this post's current reach?*

Today that is answered geometrically: a road search draws the reachable area as a
shape, the shape is stored as a grid of filled squares, and membership is
"is your square filled?". The shape is redrawn from scratch every time the reach
grows — a full road search each time — and that recomputation is what melted the
routing host three times in one day in August.

Two things are wrong with the shape approach. It recomputes what it already knew,
and a filled square is a fib: the far bank of an unbridged river gets filled
because it is *near*, not because you can *drive* to it. The road network already
knows the true answer. Reach engine stores that answer directly.

## The graph, in plain English

OpenStreetMap describes every road as a chain of closely-spaced points — a bend
gets a point, a lay-by gets a point. Loaded raw, the UK is 56.9 million points
joined by 117 million tiny hops. Almost none of those points is a *decision*: on
a country lane between two junctions there is nothing to decide, you can only
carry on. Only 12.9 million points are real junctions.

**Contraction** collapses each run of no-decision points into a single edge that
remembers the total driving seconds from one junction to the next ("this lane
takes 43 seconds"). Nothing is lost: for every collapsed point we keep how many
seconds it sits from each end of its lane, so the arrival time *at any point of
any lane* is still exact — arrive at either end junction, add the stored offset,
take whichever direction is quicker (one-way lanes only allow one). Two traps
matter and are regression-tested: a road and a footpath joining the same two
points must not be merged (they have different driving times — one has none),
and two parallel lanes joining the same two junctions must not be confused for
one another.

## Cutting the country into regions

Britain's road network has a shape: dense towns stitched together by a few
connecting roads, and estuaries and firths that force everything through a
handful of crossings. The partitioner finds those narrow places automatically.

It works by a tug-of-war called a **maximum flow**. Take the country, hold the
westernmost quarter of junctions in one hand and the easternmost quarter in the
other, and ask: how many separate roads would have to be severed so that no
route at all remains between the two hands? A classic result (max-flow = min-cut)
says the answer, and the exact set of roads to sever, can be computed
efficiently — and that set is by definition the narrowest waist of the network.
Try the tug in four directions (north-south, east-west, both diagonals), keep
the narrowest waist found, split the country there, and repeat on each half
until every region is at most ten thousand junctions.

Nobody tells it where the water is. The Severn comes out as an 8-road seam, the
Forth as 6, the Humber and Mersey as 9 — because those crossings genuinely are
the only ways over. Half of all the splits sever 9 roads or fewer; the worst,
in the London-to-Reading sprawl, severs 85. The whole UK partitions in under
3 minutes on a 20-core box.

Why regions? Because a region with few doors is summarisable. For each region we
precompute a small table: from every way in (an "entry") to every boundary node,
how many seconds does it take to cross *inside the region*? All those tables for
the whole UK total 2.6MB — the narrow seams are exactly what keeps them tiny.

## What a post's reach becomes

When a post is created, one road search runs from the poster's location — over
the junction graph, so it is small — and the result is boiled down to a **label
per region**:

- **fully in reach** — every junction in the region is within the time budget;
- **partly in reach** — stored with the arrival time at each of the region's
  entries;
- **not reached** — not stored at all.

A real post's labels are 0.6-3.8KB. That replaces both the repeated road
searches (the schedule of arrival times at region entries never changes as the
reach grows — only the time budget does) and, eventually, the stored grid.

Membership is then a lookup. Find the member's nearest road point (same snapping
rule as today). If it is a junction in a fully-reached region: in reach. In a
partly-reached region: take each entry's stored arrival, add the precomputed
inside-the-region seconds from that entry to the member's junction, and compare
the best against the budget. If it is mid-lane: do that for both ends of the
lane and add the lane offset. Microseconds, no search.

## Why the answers are exact and how we know

The one non-obvious step is proving the region shortcut loses nothing. Any
fastest route to a member ends with a final stretch inside the member's region,
entered through one of its entries; the arrival times at entries are computed
globally (routes that leave a region and come back are accounted for); and the
inside-the-region tables are exact. So "best entry arrival plus inside-time"
*is* the fastest route, not an approximation.

That argument is checked by brute force, not trusted:

- 5.1 million arrival times across 625 origins covering every sizable region of
  the UK, compared against a plain full-graph road search: **zero mismatches**,
  both for a live query and for labels written to bytes and read back.
- Hundreds of fictional origins on top — random points in the sea and on moors,
  mid-lane starts, one-minute budgets, two-hour budgets, slivers of regions —
  plus probes confirming nothing *outside* the true reach is ever claimed in.
- 646,956 samples against production's stored grids for 13 real posts, with
  every disagreement accounted for by the grid's known character (filling
  ground with no road on it, one-square edge rounding, the coarse tracing of
  today's boundary) — including the unbridged-riverbank squares where the road
  answer is the correct one.

The verification harnesses caught three real bugs during development (a path
that re-enters a region through a second entry; the road-plus-footpath merge; a
circular lane sharing both endpoints). They are kept in the tree as regression
tooling — `reach parity`, `reach sweep` — and the arguments above are only as
good as their continued green.

## Road distance on the site (implemented)

Every arrival the engine computes carries the road METRES of the winning path
alongside its seconds (verified across the same 5.1-million-check UK sweep:
none missing, 0.005% differing only where two routes tie on time). On top of
that sit batch-first interfaces at every hop — routing `POST /v1/drive-metrics`
(one origin, up to 1000 targets, one call), apiv2 `POST /drivedistance` (the
logged-in member to up to 100 points), and a frontend composable that collects
every card rendered in a pass and issues ONE request — so post lists, chat
headers and profiles show "N miles by road" instead of crow-flies, falling
back to crow-flies automatically wherever the engine is not deployed. The
far-away warning when replying deliberately still uses crow-flies: it is a
logic threshold, and road distance over blurred coordinates can exaggerate.

Displayed locations are BLURRED for privacy, and a few hundred metres of
circular blur can put a point on the wrong side of a river — a tiny crow-flies
error but a huge road-distance one. Member and post display blurring is
therefore road-aware now: apiv2 blurs every displayed location through
`POST /v1/blur-batch` (one batched call per list response, cached — blur is
deterministic per location), which picks a pseudo-random road point between
R/2 and 3R/2 ROAD metres away and at least R/4 crow-flies metres away, so a
blurred location stays on its own bank by construction and a hairpin lane
cannot leave it deceptively close. Output keeps the classic 3-decimal-place
rounding. When the routing server cannot answer, the classic circular blur is
the automatic fallback — behaviour degrades to exactly what it was before.

## Fairness is untouched

The deprivation-quintile fairness machinery (the fairness weight, the overflow
lanes, `/v1/fairness`) runs on the existing pipeline: no fairness, overflow or
deprivation file changes anywhere in this work, and the full fairness test set
passes unchanged. The new road-distance answers are plain drive metric —
display values, not allocation decisions. When batch later adopts labels as
the membership truth, overflow cells remain a separate additive geometry: the
labels do not subsume them.

## What else this structure unlocks

The regions-plus-boundary-tables structure is the core of "Customizable Route
Planning", the method built for general routing, so reach is only its first
customer:

- **Point-to-point drive times, now served.** `/v1/drive-time` answers from
  the engine when it is live (exact, milliseconds, road miles included, the
  sweep kept as fallback), and `/v1/drive-metrics` answers one-to-many in a
  single call. The remaining refinement is reverse-direction region tables so
  unbounded point-to-point avoids computing the whole origin reach — the
  current form is bounded by the 120-minute cap, which every current caller
  is under. The same machinery makes the proximity-notes "is it quicker?"
  checks near-free.
- **One-to-many and many-to-many.** Arrival from one origin to many points is
  already the membership path; region-to-region time matrices compose from the
  boundary tables in milliseconds — useful for volunteer/collection
  assignment and clearance routing.
- **Cheap metric updates.** When only the edge *times* change — a speed-model
  recalibration, seasonal factors — the partition and graph stand; just the
  2.6MB of tables rebuild, in under two seconds. Today that class of change
  means rebuilding the graph on every host.
- **Walk and cycle for free-ish.** The partition is topological, shared by all
  modes; each extra mode is a metric fill of the same tables.
- **Faster display isochrones** for the catchment and group endpoints, since
  the labeling query is 25-250x the flat search.

## Fast "what is near me" for messages, chitchat, anything

Finding nearby things fast is a different problem from pricing travel, and it
already has the right tool: the KNN index service (iznik-spatial-go) is built
for point lookups over datasets that change by the minute. The road graph
should not replace it — a per-origin travel summary is not an index. The wins
come from combining them:

- **Road-true ordering, today**: the index shortlists candidates crow-flies
  (nearby messages, chitchat threads, freeglers); one `/v1/drive-metrics`
  call prices the whole shortlist by road; the ordering and any "within X"
  threshold become road-true. No schema changes needed.
- **Leaf-tagging, the adoption-phase design**: stamp every located thing with
  its partition region id at creation (`LeafOf` lookup, O(1), one small
  integer column). "Things roughly reachable from me" then becomes "things
  whose region is in my labels' reached set" — a single `IN (...)` clause
  that is road-aware by construction (the far bank's region is simply not in
  the list), replacing crow-radius and cell-containment prefilters. Exact
  membership still goes through the labels where it matters. This belongs to
  the batch-adoption phase because it needs a column and feed-query changes;
  the primitive it depends on (region ids + labels) ships here.

## Artifacts and lifecycle

Everything derived is a file: the contracted graph (4.7GB, loads in ~5s versus
90 seconds to 16 minutes of rebuild-from-map today), the partition (92MB), the
tables (3.4MB). Partition and tables can also be derived at boot in ~3 minutes
if storing them is undesirable. Monthly map refresh = rebuild the lot offline,
under 6 minutes end to end.
