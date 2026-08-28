# Reach grid removal endgame (follow-on to #1446)

Goal: the graph becomes the ONLY reach representation. `rippling_reach` loses
both blob columns (~1.9GB + fragmentation on prod), the spatial server's reach
containment dataset and the per-tick rasterise round-trips disappear, and
there is one authority instead of two that must agree.

Prerequisite state: #1446 merged, labels backfill complete, max-grid drain +
soak done. Everything below assumes labels-truth is the deciding record and
the grids are only (a) the containment index source and (b) the parachute.

## 1. Origin-group union goes road-native

The union has two halves; only one needs a road form.

- Membership test: UNCHANGED - point-in-polygon at eval (`groupAreaContains`)
  already answers "is the member inside the origin group's area". The polygon
  stays a polygon.
- Activation condition (today: isochrone covers >=90% of the group's area,
  recomputed geometrically every tick): precompute once per group a sample of
  graph nodes inside its polyindex; at label-store time evaluate the stored
  label against those nodes and find the smallest budget where >=90% are
  reached; store that ONE number per post (`origin_union_secs`, nullable
  float, NULL = never). Eval: `in` if arrival <= budget, OR (budget >=
  origin_union_secs AND point-in-group-area). Exact at every tick, no
  geometry, no rasterise. Replaces the `origin_area` no-verdict flag from
  #1446 with a definitive verdict.
- Discovery: the true "road equivalent of the polygon" - group polyindex ->
  set of partition regions, computed once per group per partition build,
  merged into `rippling_reach_leaves` at label-store time so union-admitted
  members beyond max drive time still discover the post.
- Retraction already works grid-free (rejected_groups + area test at eval).
- Display already works grid-free (#1438 engine catchment/tick polygons).

## 2. Containment serving moves to the routing server

The discover machinery generalised from gap-filler to primary index:
member point -> leaf region(s) -> candidate posts (leaves table) -> label
eval each. Replaces iznik-spatial-go's `reach` dataset (dataset_reach.go,
built from polygon_cells).

- Per-call work is small (one snap, then table-lookup arrivals) but inherits
  badge QPS (~215/min peak). Keep the leaf->posts cache; measure capacity
  honestly on prod-shaped data before cutover (the parity harness pattern
  from #1438 - replay a day of badge/feed calls against both indexes and
  diff answers + latency).
- The reachoverflow (rings/wedges) dataset is separate and UNAFFECTED.
- apiv2/PHP callers switch `SpatialReachIDs`/`reachContaining` to the routing
  containment endpoint; the labelNarrowAndDiscover union collapses into it
  (one call instead of grid-then-labels).

## 3. Routing becomes tier-1: redundancy + the rebuild window

- Instances are stateless from the artifact: run two, standard ops.
- The sharp problem is the partition-rebuild window: a map refresh renumbers
  regions and invalidates EVERY label until `--all` re-backfills (45min-2h).
  Grid-free that is a site-wide reach outage on every map update. Fix:
  dual-build engine - labels already embed the partition fingerprint, so the
  engine holds old+new builds simultaneously and routes each blob to its
  matching build. The re-backfill becomes a rolling, invisible migration;
  drop the old build when no stored label references it.
  Cost: second graph+matrices resident (~5GB); the routing hosts already
  drop the 2.5GB pbf at runtime under artifact boot, so headroom needs
  checking per host before this lands.

## Sequencing

1. Union road-native (1) - self-contained, lands while grids still exist;
   verdicts become definitive (origin_area flag retired) and can be
   parity-checked against the geometric union on live data.
2. Dual-build engine (3b) - also useful before cutover (removes today's
   post-rebuild nolabels window where grids silently take over).
3. Containment on routing (2) behind a comparison period: serve both, diff,
   then flip; spatial reach dataset retired.
4. Redundant routing instances (3a); grids stop being written; final drain of
   polygon_cells + OPTIMIZE; delete rasterise paths, cells fallbacks, and
   the spatial reach dataset code.

## Non-goals

- reachoverflow rings/wedges storage (separate system, unchanged).
- Any change to what members see: every step is parity-gated against the
  current answers before it takes over.

## Status

- Plan only. Blocked on #1446 merge + backfill + drain soak.
