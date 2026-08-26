# Cellset legacy removal (follow-up to PR #1406)

Edward 2026-08-26: "grid replacement for ripple is now live and backfilling. pr has follow on
work - build that now."

Scope, from PR #1406 "Not in this PR" + plans/2026-08-24-rippling-reach-raster-storage.md:676:
delete the then-dead legacy branches (including GeomShareService and rippling/geomshare.go,
which survive only as the read path for a legacy row), convert the polygon-writing fixtures,
and turn the drop migration on by default - so the schema and the code stop diverging at the
same moment.

**MERGE GATE: this PR must NOT merge until production has applied phase 2 (the column drop).**
Production is currently in the transition era (phase 1 + backfills running). The legacy
branches being deleted here are what read a legacy row meanwhile.

Branch: feature/rippling-cellset-legacy-removal off origin/master (f5e597959).
Worktree: /home/edward/FreegleDocker-reach-raster.

## Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Scope survey (era-guard call sites, commands, spatial-go) | ✅ | ~34 PHP sites, ~25 Go sites, 2 spatial-go files |
| 2 | Worktree on new branch, containers up, status port known | ✅ | status port 12019 |
| 3 | Migration on by default (RIPPLE_DROP_LEGACY_GEOMETRY) | ✅ | opt-OUT now; refusal guards kept |
| 4 | PHP: delete legacy branches + GeomShareService + era guard | ✅ | all call sites cells-only |
| 5 | PHP: delete transition-era commands | ✅ | 7 commands gone; BackfillRings too (wrote overflow_bounds); BackfillInnerBounds kept, ratio vs outer_bound |
| 6 | Go: delete legacygeom.go + geomshare.go, unconditionalize call sites | ✅ | SpatialReachIDs moved to reachmembership.go; SPATIAL_REACH_MODE gone |
| 7 | spatial-go: dataset_reach.go + dataset_reachoverflow.go cells-only | ✅ | module suite green (go test ./...) |
| 8 | Fixtures/tests: polygon-writers -> cells; era-fake tests collapse to one era | ✅ | PHP: SeedsReachCells trait (offline rect grids) + FakesRingIndex serves reach/containing; Go: reachindexstub_test serves index from test rows |
| 9 | Docs: rippling-algorithm.md + first-reply.md + freshness green | ✅ | |
| 10 | Full suites green via status API: Laravel + Go + spatial-go | ⬜ | worktree status port TBD |
| 11 | Review pass + PR | ⬜ | merge gate in body |

## Key decisions (planned)

- Era guards (LegacyGeometry.php, legacygeom.go) get DELETED, not stubbed: every call site
  unconditionalizes to the cells branch. The guard is a column-existence memo; after the
  drop it always answers false everywhere.
- SpatialReachIDs (Go) SURVIVES - it is the post-drop read path - but loses the
  SPATIAL_REACH_MODE + LegacyPolygonReady gate (after the drop the spatial index is always
  tried; degraded path is outer_bound + cells probe).
- has_overflow / has_max_reach generated columns STAY (regenerated over cells columns by
  the drop migration's phase 3).
- outer_bound / inner_bound and every SQL prefilter that drives them are UNTOUCHED
  (2026-08-21 outage index).
- The drop migration keeps its per-column "refuse while any live row lacks cells" guards -
  they are what makes the default flip safe on any DB that still has legacy rows.

## Post-#1406 master fixes to respect (already merged: #1409-#1412)

- e041b4c3a: ring parity compares by probing, not decoding (area-proportional decode).
- #1411: partial ring conversion is normal (per-lane cells fallback).
- #1410: cellset client coverage.
Do not reintroduce decode-to-compare patterns.

## Verification obligations (from the plan doc)

- Both suites green via the status API with the columns DROPPED in the test schema.
- Golden vectors unchanged.
- PostDropEraTest keeps meaning: post-drop era is now the ONLY era; the era-fake test
  hooks (LegacyGeometry::fake, SetLegacyGeomForTest) go with the guards.
