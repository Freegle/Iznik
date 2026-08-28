# Reach labels-truth cutover (follow-on to PR #1438)

Stored FRL2 labels become the DECIDING record for reach membership; the cell
grids stay as the spatial-index prefilter, the display geometry, and the
verdict for any post the backfill has not labelled. Self-activating per post
as `ripple:backfill-reach-labels` progresses - no flag day.

## Read path

- routing `POST /v1/reach-eval` {lat,lng,msgids<=1000}: loads stored labels +
  the post's CURRENT tick budget (schedule[tick].drive_min, max as fallback)
  from MySQL (cached: labels 10min, budgets 60s), snaps the member once,
  answers in/out/nolabels per candidate. Fingerprint-mismatched labels =
  nolabels.
- apiv2 rippling.LabelVerdicts (breaker-aware, chunked, fail-soft nil) +
  DropLabelOut. Wired into:
  - rippling.ReachMembership -> the reply gate + message reach surfaces
    override their cell verdict wherever a label decided.
  - reachContainmentSQL -> the browse feed + badge counts narrow the
    grid-admitted id list; overflow rings re-admit on top exactly as before.
- batch ReachService::labelVerdicts -> UnifiedDigestService::getPostsForUser
  narrows its containment universe the same way (digest can never mail what
  browse hides).

## Deliberately unchanged

- Retraction + group targeting: polygon/cell GEOMETRY questions, stay so.
- First-reply MaxReach/MatchMail: heuristic mail targeting on max-reach
  cells; not the reach record.
- Narrowing-only this PR: grids over-cover by construction (fill, edge
  rounding), so labels dropping grid admissions is the whole member-visible
  fix (the far bank). The residual 0.27% under-coverage band (trace
  smoothing) stays grid-governed until the prefilter itself moves to the
  leaves table (phase 2, below).

## Disk (measured on prod 2026-08-28)

rippling_reach = 7.72GB / 51,121 rows; polygon_cells avg 17.5KB +
max_polygon_cells avg 20.4KB = ~1.9GB of blobs. Nothing droppable in THIS
PR (cells still prefilter/display/retraction). Phase 2 - leaves-table
prefilter + on-demand polygons from the engine - makes both cell columns
droppable (~1.9GB) and an OPTIMIZE then reclaims fragmentation; the table
should end well under 1GB.

## Status

- routing endpoint + tests: DONE (TestReachEvalVerdicts).
- apiv2 gate/feed wiring + tests: DONE (TestLabelVerdictsOverrideCells,
  TestDropLabelOut).
- batch digest wiring + tests: DONE (drops-label-out / keeps-nolabels).
- Suites: running.
