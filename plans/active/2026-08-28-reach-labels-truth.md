# Reach labels-truth cutover (follow-on to PR #1438)

Stored FRL2 labels become the DECIDING record for reach membership; the cell
grids stay as the spatial-index prefilter, the display geometry, and the
verdict for any post the backfill has not labelled. Self-activating per post
as `ripple:backfill-reach-labels` progresses - no flag day.

## Read path

- routing `POST /v1/reach-eval` {lat,lng,msgids<=1000,budget,discover}: loads
  stored labels + the post's CURRENT tick budget (schedule[tick].drive_min,
  max as fallback) from MySQL (cached: labels 10min, budgets 60s), snaps the
  member once, answers in/out/nolabels per candidate. Fingerprint-mismatched
  labels = nolabels. budget:"max" evaluates at the label's full budget (the
  eventual reach - first-reply targeting). A member inside a rejected group's
  area (rejected_groups + groups.polyindex point-in-polygon, cached) is "out"
  whatever the label says. discover:true additionally returns label-admitted
  posts NOT in msgids, from the stored leaves (rippling_reach_leaves) - the
  band where grids under-cover the true road reach; msgids may be empty when
  discovering.
- apiv2 rippling.LabelVerdicts / LabelVerdictsAtBudget / LabelVerdictsWithDiscover
  (breaker-aware, chunked, fail-soft nil) + DropLabelOut. Wired into:
  - rippling.ReachMembership -> the reply gate + message reach surfaces
    override their cell verdict wherever a label decided.
  - reachContainmentSQL -> the browse feed + badge counts narrow the
    grid-admitted id list AND union the discovered ids; overflow rings
    re-admit on top exactly as before.
  - message/search.go searchReachArmIDs -> search narrows the same way.
  - firstreply/passthrough.go -> the gate asks labels at budget "max" first,
    falls back to max_polygon_cells.
- batch ReachService::labelVerdicts / labelVerdictsWithDiscover /
  reachArrivalBatch / currentBudgetSecs. Wired into:
  - UnifiedDigestService::getPostsForUser: narrows its containment universe
    AND unions discovered ids (digest can never mail what browse hides, nor
    hide what browse shows).
  - MaxReachService::isWithinMaxReach: labels at "max" first, cells fallback;
    both maxreach backfill queries skip labelled rows (whereNull reach_labels).
  - MatchMailService::applyCellBand: ONE reach-arrival call evaluates the
    stored label at every candidate; band = arrived-eventually AND not-yet-in,
    ordered by seconds past the current edge; cells fallback. Candidate SQL
    accepts labels OR max cells as the eventual-reach record.

## Deliberately unchanged

- Retraction + group targeting: polygon/cell GEOMETRY questions, stay so.
- polygon_cells (CURRENT reach grid) is still materialised every tick for
  every row: it is the SOURCE the spatial server's reach containment index
  (iznik-spatial-go dataset_reach.go) is built from - the badge/feed/digest
  prefilter - and the routing-down fallback. Draining it would leave the
  spatial index serving stale or absent reach. It only becomes droppable if
  that index is rebuilt to answer from labels (i.e. the routing server takes
  over containment), which changes the outage story and is NOT this PR.

## Disk (measured on prod 2026-08-28)

rippling_reach = 7.72GB / 51,121 rows; polygon_cells avg 17.5KB +
max_polygon_cells avg 20.4KB = ~1.9GB of blobs.

Phase 2 (in this PR): `ripple:drop-cell-grids` NULLs max_polygon_cells for
labelled rows (~1.0GB of blobs) - every max-cells reader is labels-first, and
without the grid they fail closed during a routing outage, which is the
conservative direction for what are all extra-mail decisions. The maxreach
backfill skips labelled rows so drained grids are not rewritten. Follow with
`OPTIMIZE TABLE rippling_reach` to hand the space back. polygon_cells stays
(see above).

## Operator sequence on live

1. `php artisan ripple:backfill-reach-labels` (progress+ETA printed).
2. Deploy this PR's containers; soak.
3. `php artisan ripple:drop-cell-grids --dry-run`, then for real.
4. `OPTIMIZE TABLE rippling_reach;` (online, InnoDB).

## Adversarial review round (2026-08-28 late)

Four parallel reviewers; all confirmed findings fixed + tested:
- MULTIPOLYGON rejected areas (wktAreaRings + pure even-odd containment).
- evalMu held across MySQL (areas resolved outside the lock).
- Row-mutable state (rejected/held/tick/origin gid) on the 60s TTL.
- origin_area union rule: out inside the post's origin group's area = NO
  verdict; both clients skip it, the cell grid (which holds the union) decides.
- Badge now shares labelNarrowAndDiscover with the feed; search unions
  discoveries too.
- Non-snapping member point answers nolabels (200); Go breaker only on 5xx;
  PHP labelEval gets the 5-min breaker.
- Discover: held never discovered, fan-out capped 1000, later-chunk out
  verdict beats a discovery (both clients).
- MaxReach indexed sweep skips labelled rows (has_max_reach knows nothing of
  labels); fillCumulativeForLabelled feeds max_cumulative_users from the
  schedule so the engagement nudge keeps working.
- Tests: TestWktAreaRings, origin/ocean/held/empty arms,
  TestLabelVerdicts4xxDoesNotTripBreaker, TestLabelNarrowAndDiscover, PHP
  breaker/origin/multi-chunk/sparse-keys/--limit/indexed-scan tests.

## Grid-removal endgame (2026-08-28, "do it in that pr")

See plans/active/2026-08-28-reach-grid-removal-endgame.md for the design and
its delivered adjustments. In this PR:

- origin_union_secs (migration 000003) computed at label store
  (/v1/reach-labels?msgid=) and backfilled (/v1/reach-union, second pass of
  ripple:backfill-reach-labels); eval verdicts become DEFINITIVE for
  union-known rows (origin_area flag only transitional for NULL).
- rippling_reach_leaves.fp + dual-build engine (REACH_DIR_PREV,
  decodeLabelsAnyBuild): map refresh = rolling label migration.
- Per-row grid retirement: ExpandService writers NULL polygon_cells + skip
  rasterise for union-ready rows; dataset_reach REMOVES labelled+drained rows
  (delta + reconcile); drop-cell-grids drains current grids for union-ready
  rows (done/stopped rows).
- Degraded-path labels rescue (filterProbed); mod overlay engine-isochrone
  fallback; reply-gate quiet fail-closed for drained rows.

## Status

- Labels-truth + review round + endgame all built. Routing suite green
  (full, local, incl. TestReachUnionEndgame + TestReachEvalDualBuild).
- apiv2 go + laravel suites: running at time of writing; commit follows green.
