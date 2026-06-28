# Ripple extent governor — buildable MVP (audience-sized reach)

**Date:** 2026-06-28
**Status:** Scoped, not started. Needs a product decision on target audience size + go-ahead.
**Origin:** Discourse #9808/250 (Neville Reid) — NW-London Offers ripple ~32 km (Chilterns↔East London, ~1 h drive, ~27k members); Jos #248 (London 27k vs Swindon ~1k for the same drive-time reach).

## This is NOT a new design

The design already exists: [`rippling-out-algorithm.md`](rippling-out-algorithm.md) §"Eyeball-governed reach — how far we expand (design 2026-06, rev. 2)" — two prior Opus multi-agent passes. Verdict there: **"sound in architecture, not yet buildable as a closed-loop extent governor."** Two blockers it names:

1. **Measurement gap (load-bearing).** Real eyeballs aren't measurable today: `MarkSeen` (passive list-scroll) and `handleView` (real page open) write the same `(msgid,userid,'View')` row in `messages_likes` with no distinguishing column → `E*`-stop is meaningless until a schema change (source column or ~30 s dwell). Gates the **closed loop only**.
2. **Lag → feed-forward/closed-loop hybrid.** At t=0 ~70% of sends are committed before any eyeball signal exists; the loop only governs the low-value ~30% tail. So **Stage A (feed-forward burst sizing) is the part that fixes London and needs no measurement.**

This plan scopes **Stage A only** — the buildable-now piece.

## New grounded findings (2026-06-28)

- **The routing schedule already carries member counts.** `ReachService::computeSchedule` → `iznik-routing-go /v1/ripple-schedule` returns `{total_freeglers, max_drive_min, ticks:[{tick, drive_min, cumulative_users, wkt}]}`, persisted in `rippling_reach.schedule` (longtext) + `total_freeglers`. **No schema change** needed for member-based (not eyeball-based) governance.
- **The burst IS the decision.** Verified on a live suburban post: tick 1 already = **2,909 / 4,156** members (the `step-70` ~70% burst) at 21 min; ticks 2–9 only add the outer 30% out to 30 min. So a pure-batch "cap at an outer tick" is **insufficient for dense areas** — in London tick 1 alone is ~13–19k members. Governing extent means sizing the **first tick**, exactly as the rev.2 doc says ("the extent decision IS the burst-sizing decision, made blind").
- Today `ExpandService::initialiseNew` picks the tick purely by **elapsed hours** (`entryForTick`/`tickForElapsedHours`) — no audience consideration anywhere.

## MVP: audience-sized burst, drive-time as outer ceiling only

Replace "fixed `step-70` % of pool at a fixed drive-time" with "expand nearest-by-drive-time until **cumulative_users ≥ N\***, capped by the 45-min isochrone, floored by a minimum." Member count `N*` is the MVP's budget unit (the eyeball target `E*`/`r_segment` refinement is deferred to Stage B once impressions are measurable).

- **Dense (London):** `cumulative_users` crosses `N*` at a small drive-time → tight reach. Fixes the complaint.
- **Sparse (rural/Swindon):** never reaches `N*` → rides to the 45-min ceiling. Intended (unchanged).

`N*` stratified by ONS rural-urban class (the RU-classification + `fairness.go` deprivation multiplier already exist) so "enough audience" is calibrated per area type, not one global number.

## Where it hooks

1. **`iznik-routing-go` `/v1/ripple-schedule` (the real lever).** Add a member-target mode: given `target_users` (= `N*`), return a **burst isochrone sized to ~`N*` nearest Freeglers** as tick 0 (it already walks `cumulative_users` along drive-time — find the drive-time where it ≈ `N*`), alongside the existing hazard ticks. Without this, the batch can only trim outer ticks, which doesn't touch the dense-area burst.
2. **`iznik-batch ExpandService`** (`initialiseNew` Phase 2 + `advanceDue`): pass `N*` (per origin RU-class) to `ReachService`; choose the served tick as `min(time-tick, audience-tick, 45-min ceiling)` where audience-tick = first tick with `cumulative_users ≥ N*`; never below a floor tick (anti-starvation).
3. **`ReachService::scheduleParams`** — add the `target_users` param.
4. **`config/freegle.php` `ripple`** — new `extent` block (below).

## Config knobs (proposed)

```
'extent' => [
  'enabled'        => env('RIPPLE_EXTENT_ENABLED', false), // dark by default
  'target_users'   => env('RIPPLE_EXTENT_TARGET_USERS', 4000), // N* default; override per RU-class
  'target_by_ru'   => [ 'A1'=>3000, 'B1'=>4000, 'C1'=>4000, ... ], // dense gets a tighter audience
  'min_drive_min'  => env('RIPPLE_EXTENT_MIN_DRIVE_MIN', 10),  // floor: never tighter than this
  'max_drive_min'  => existing max_minutes (45-min ceiling stays the outer cap),
],
```

## Rollout & measurement (no risk first)

1. **Dark-compute.** With `extent.enabled=false`, log per post: current served extent vs the audience-sized extent that *would* be chosen (`cumulative_users` is already stored). Quantifies the London tightening and confirms rural is untouched, with zero member impact.
2. **Calibrate `N*`** from the existing simulator + the catch-rate data already in the rev.2 doc (urban 80% / rural 65% catch-the-claimant; reply rate 14%→28% with exposure) — pick `N*` (per RU-class) that preserves catch rate while cutting London's tail.
3. **Enable scoped** (the existing `RIPPLE_WITHIN_GROUPS` experiment mechanism) on a London cluster; watch impressions/post, repliers/post, mail volume, success rate vs control.

## Deferred (Stage B — needs the measurement fix)

- `messages_likes` source/dwell schema change → real eyeball signal → true `E*`-stop closed loop + futility-cut.
- `r_segment` conversion priors (eyeballs per notified member, by RU-class × delivery-mode) → switch the budget unit from members (`N*`) to eyeballs (`E*`).

## Risks

- **Rural starvation** — mitigated by the min-drive floor + "only cap when `cumulative_users` clearly exceeds `N*`"; rural never binds anyway.
- **`N*` miscalibration** — dark-compute + scoped rollout before network-wide.
- **RU-class coverage** — confirm every group has an RU-class (the classification work covered ~98%); fall back to the global `target_users` where missing.
- **Member ≠ eyeball** — the MVP governs *audience size*, not true impressions; it's the feed-forward approximation the rev.2 doc sanctions, refined later by Stage B.
