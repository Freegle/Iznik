# Rippling reach — current-mechanics map

Date: 2026-07-02. Scope: read-only code/config audit. No implementation. This is the
factual baseline for the "principled tuning-parameter" design work (task: DESIGN
principled method for setting rippling reach parameter, no slider).

Sources read: `plans/2026-06-28-ripple-extent-governor-mvp.md`,
`plans/rippling-out-algorithm.md` ("Eyeball-governed reach" section + glossary +
technical-detail sections), `2026-06-22-rippling-extent-governor-design.md`,
`2026-06-22-rippling-reach-experiment-spec.md`, `2026-06-22-rippling-experiment-userlevel-redesign.md`
(all under `~/Downloads`), and the live code: `iznik-batch/app/Services/Ripple/*.php`,
`iznik-batch/config/freegle.php` (`ripple` block), `iznik-routing-go/ripple.go`,
`fairness.go`, `deprivation.go`, `ripple_extent_test.go`, `iznik-server-go/message/message.go`
(`handleView`) + `markseen.go`, and the `iznik-batch` migrations for every `rippling_*` table.

**Headline correction to the design docs**: several things the plan documents describe as
open blockers or "not yet buildable" are **already implemented in code**, just dark
(feature-flagged off) or otherwise unwired. The docs are dated 2026-06-22/28; code has moved
since. See "What's newer than the docs" below.

---

## 1. Parameter inventory — every tuning knob, where it lives, its value, provenance

| # | Name | Lives in | Current value | Who/what set it | Basis |
|---|------|----------|----------------|------------------|-------|
| 1 | `RIPPLE_ENABLED` | `config/freegle.php:285` (`ripple.enabled`) | `true` in prod (per memory: live ~2026-06-14) | Product go-live decision | Master kill-switch; gates the whole `ripple:expand` cron |
| 2 | `RIPPLE_ENABLED_AT` | `ripple.enabled_at` | `2026-06-23` | Go-live flood guard | Only posts arriving on/after this date ever start rippling |
| 3 | `RIPPLE_WITHIN_GROUPS` | `ripple.within_groups` | empty (no experiment scope active) | Per-group before/after experiment mechanism | Lets a subset of groups ripple while global switch is off |
| 4 | `RIPPLE_CURVE` | `ripple.curve` | `step-70` | Simulator-derived (10,521 historical posts; see algorithm doc empirical-results table) | Won on catch-rate/waste/lead-time vs 8 other shapes tested |
| 5 | `RIPPLE_MODE` | `ripple.mode` | `drive` | Design choice | Only drive-time mode is used in production |
| 6 | **`RIPPLE_MAX_MINUTES`** | `ripple.max_minutes` | **30** (float, minutes) | Simulator-derived original design | **This is the live ceiling — NOT 45.** Confirmed: no `45` literal exists anywhere in `iznik-routing-go/*.go` or `ExpandService.php`/`ReachService.php`/`config/freegle.php`. The "45-min ceiling" language in both the eyeball-governed-reach design and the MVP plan is a **proposed future ceiling for the (unbuilt) audience-sized-burst mode**, not a value that has shipped. Go server independently clamps any caller-supplied `max_minutes` to `(0, 120]` (`ripple.go:277-279`, `:56-58`), falling back to 30 if out of range — so 120 is a hard upper sanity bound, not a design ceiling. |
| 7 | `RIPPLE_COMPUTE_CONCURRENCY` | `ripple.compute_concurrency` | 8 | Ops tuning (routing-host core count) | Throughput knob, not a reach knob |
| 8 | `RIPPLE_REUSE_REACH` | `ripple.reuse_reach` | `true` | Perf optimisation | Reuses schedule for same blurred origin (4dp); must be `false` for one full recompute after changing curve/max_minutes/extent |
| 9 | `RIPPLE_REQUEST_TIMEOUT` | `ripple.request_timeout` | 60s | Ops | Per-`/v1/ripple-schedule` call timeout |
| 10 | **`RIPPLE_REPLY_SATURATION_STOP`** | `ripple.reply_saturation_stop` | **5** (distinct repliers) | Discourse community consensus ("How many replies do you need to an Offer?" thread, #8415) | **Live and load-bearing.** Applied at both `initialiseNew` (a post with ≥5 distinct `Interested` chat repliers never starts rippling) and every `advanceDue` tick (a post that becomes saturated mid-ripple stops and is marked `done`). Type-agnostic (Offers and Wanteds). 0 disables. |
| 11 | `RIPPLE_RIPPLED_IN_PENDING_HOURS` | `ripple.rippled_in_pending_hours` | 0 | Mod-load design decision | 0 = rippled-in posts auto-approve immediately on receiving groups (already vetted on origin); >0 would leave a mod-veto window |
| 12 | **`hazard_hours`** (array, not an env var) | `ripple.hazard_hours` | `[1, 3, 6, 12, 24, 48, 72, 120, 168]` (hours since arrival) | Simulator-derived | 9 ticks over 7 days wall-clock (not literally "24h/30 ticks" as the prose glossary says — see discrepancy note below) |
| 13 | `RIPPLE_ACTIVE_START_HOUR` / `RIPPLE_ACTIVE_END_HOUR` | `ripple.active_start_hour/active_end_hour` | 06 / 23 | Ops/UX (don't wake devices at 3am) | Ticks due outside this window wait |
| 14 | **`RIPPLE_EXTENT_ENABLED`** | `ripple.extent.enabled` | **`false` (dark)** | MVP plan (2026-06-28), coded not activated | Stage-A audience-budget cap master switch |
| 15 | **`RIPPLE_EXTENT_TARGET_USERS`** | `ripple.extent.target_users` | **4000** (inert while #14 is false) | Placeholder default, **not calibrated** — no per-RU-class table wired (`target_by_ru` mentioned in the MVP plan doc is NOT present in the actual `config/freegle.php`, only a single flat `target_users`) | This IS the parameter the overall design effort is meant to derive |
| 16 | `RIPPLE_DIGEST_W_CLOSE/FRESH/BUDGET/ANCHOR` | `ripple.score.weights` | close=1.0, fresh=0.0, budget=1.0, anchor=0.0 | Problem-2 (digest ordering), separate concern | Not an extent/reach knob — governs sort order within the already-computed reach, not how far reach extends |
| 17 | `RIPPLE_DIGEST_WINDOW_HOURS` | `ripple.score.window_hours` | 24 | Problem-2 | Freshness decay window for digest scoring |
| 18 | `RIPPLE_DIGEST_BUDGET_DECAY` | `ripple.score.budget_decay` | 25 | Problem-2 | Engagement-budget decay constant |
| 19 | `RIPPLE_DIGEST_DEFAULT_REACH_M` | `ripple.score.default_reach_metres` | 30000 (~30km) | Fallback for posts with no `rippling_reach` row | Approximation of the 30-min isochrone in metres, for pre-rippling/backlog posts |
| 20 | `RIPPLE_DISTANCE_FILTER_ENABLED` | `ripple.distance_filter.enabled` | `true` | Separate, newer feature (member-set `browseMaxDistance` slider extended to emails, PR merged 2026-07-01) | Per-member opt-in narrowing, not a system-wide extent governor; orthogonal axis |
| 21 | IMD deprivation-quintile weight `W` | `iznik-routing-go/fairness.go` `/v1/fairness` endpoint, `fairnessWeight` query param | Only used by the **separate** `/v1/fairness` endpoint (Q1 up to 2.0× time-budget at W=1) | Fairness/equity-access feature | **NOT called by the ripple pipeline at all.** `ReachService`/`ExpandService` never hit `/v1/fairness`; only `/v1/ripple-schedule`. This is IMD deprivation (English indices of multiple deprivation, LSOA-level, quintiles 1-5), a *different axis* from ONS Rural-Urban class. The plan docs' claim "a `fairness.go` deprivation-quintile time-budget multiplier already exists ... a second shape lever besides drive time" is code-true but pipeline-false: it exists in the codebase, unconnected to rippling. |
| 22 | ONS Rural-Urban class (`ru_category`: A1/B1/C1/C2/D1/D2/E1/E2/F1/F2) | `transport_postcode_classification` table (Laravel migration `2025_12_10_094529`) | Static reference table, populated from ONSPD | Offline data import | **Used ONLY by the offline extractor** `iznik-routing-go/cmd/rippleextract/main.go` (joins post postcode → RU category for the historical-data dump fed to the simulator/`ripplesim`). **Not read anywhere in the live `ripple:expand` request path.** No RU-stratified `target_users` exists in the live pipeline despite being named in both design docs as the intended stratification axis. |
| 23 | Reach-mail per-post/per-member caps (3,000-member cap, 24h fatigue cap, explosion-detector 5,000 threshold, group-insertion cap of 5) | Described in the **experiment spec** (`2026-06-22-rippling-reach-experiment-spec.md` §6.1-6.4) as required blockers | **Not present in `ExpandService.php` at all** — confirmed by grep: no `notified_count >= cap`, no fatigue-cap query, no explosion-detector count check, no per-post group-insertion cap in the live code | Experiment-spec authors flagged these explicitly as "current state: absent from codebase, this is a blocker" | Still true as of this read — these guardrails are speculative/planned, not shipped |
| 24 | `rippling_params` per-`ons_category` overrides (`curve`, `max_minutes`, `target_density`, `hazard_schedule`) | `rippling_params` table (migration `2026_06_18_000010`) | Table exists; **`ripple:tune` (the only writer) is not scheduled** (`routes/console.php:1244-1250`, explicit comment: "left unscheduled for now... pending decision on production rollout") | Self-tuning-loop scaffold (§16 in some earlier design pass) | **Nothing in the live pipeline reads `rippling_params`.** It's a write-only, unscheduled, dead table today — no consumer exists in `ReachService`/`ExpandService`. Even if scheduled, `proposeParams()` (below) is a crude stub. |
| 25 | `messages_likes.pageview` / `messages_likes.source` | Migrations `2026_06_22_000001` and `2026_06_23_000002` | **Live in schema and partially wired** | T1.2 "dwell-gated View" design item | See "What's newer than the docs" — this is further along than the design docs assume |

### Discrepancy note: "24h / 30 ticks" prose vs the actual hazard schedule

`plans/rippling-out-algorithm.md`'s glossary says "We use 30 ticks over 24 hours, so a
tick fires every 48 minutes" and "70% up front, 30% over a day." The **live config**
(`ripple.hazard_hours`) is `[1, 3, 6, 12, 24, 48, 72, 120, 168]` — **9 ticks over 7 days**
(168 hours), not 30 ticks over 24 hours. The `step-70` curve shape (70% at tick 1, remaining
30% linear across the rest) is unchanged, but the tick *granularity* and *lifetime* the prose
describes is the earlier/simulator design, not what `ExpandService`/`ReachService` actually
schedule against in production. Tick 1 fires at t=1h (not t=0 as several design docs say
loosely — "70% of sends committed at t=0" is an approximation for "by the first tick, 1h in").
This matters for any lag-vs-tick-1 arithmetic in the eyeball-governor design (e.g. the
"~70% of sends already committed by t≈3h" claim spans hazard-hours ticks 1-2, i.e. 1h and 3h
elapsed — consistent, but the "t=0" shorthand used throughout both design docs should read
"tick 1 / ~1h in", not literally zero).

---

## 2. Expansion timeline (mechanics, precisely)

1. **Trigger**: `ripple:expand` cron, every minute, `withoutOverlapping(15)` (only runs when
   `ripple.enabled=true`, or scoped via `--within-group` for an experiment).
2. **Init (`initialiseNew`)**: for every post in `messages_spatial` with no `rippling_reach`
   row yet (subject to the arrival cutoff, reply-saturation pre-check, and any area/shard
   scope), fan out to `iznik-routing-go GET /v1/ripple-schedule` (`compute_concurrency=8`
   parallel via `Http::pool`), passing `lat, lng, mode=drive, ticks=9, max_minutes=30,
   curve=step-70` (+ `target_users` only if extent cap is enabled — currently never sent).
3. **Routing server computes**, per origin:
   - One Dijkstra out to the 30-min isochrone (`Isochrone()`).
   - Queries `iznik-spatial-go` for every `users_approxlocs` member inside that 30-min
     polygon (`/v1/userapproxlocs/within_coords`) → sorted ascending by drive-time; this
     sorted list is `total_freeglers` (the full reachable pool, pre-cap).
   - `effectiveTotal = effectiveScheduleTotal(total, targetUsers)` — currently always equals
     `total` because `targetUsers` is never sent (extent cap dark).
   - For tick `k` in 1..9: `frac = curveFraction("step-70", k/9, 9)`; `target =
     round(frac * effectiveTotal)`; drive-time for tick `k` = the drive-time of the
     `target`-th nearest member; polygon = isochrone filtered to nodes reached within that
     drive-time.
   - Response: `{total_freeglers, max_drive_min, schedule: [{tick, drive_min,
     cumulative_users, polygon}, ...]}` (9 entries).
4. **Batch stores** the parsed schedule into `rippling_reach` (`schedule` = JSON array of the
   9 tick entries, `polygon` = the current tick's WKT unioned with the origin group's own
   area, `tick`=1, `total_ticks`=9, `total_freeglers`, `max_drive_min`, `next_expansion_at` =
   arrival + hazard_hours[1] = arrival+3h).
5. **Cross-post**: `rippleIntoNewGroups` inserts a `messages_groups` row (Pending or
   auto-Approved per `rippled_in_pending_hours`) for every published/onhere Freegle group
   whose `polyindex` intersects the current reach polygon — **every intersecting group**, not
   a chunked/home-first selection (the "T2.1b mod-load-aware chunking" design item is unbuilt).
6. **Mail**: `mailNewlyReachedForPost` / `UnifiedDigestService::sendReachDigests` notify
   newly-in-reach members not yet in `rippling_reach_notified` (composite PK
   `(msgid,userid)`, so re-notification is impossible by construction).
7. **Advance (`advanceDue`)**, every minute, for rows `status='expanding' AND
   next_expansion_at <= now()`:
   - Re-check reply-saturation stop (≥5 distinct repliers → `status='done'`, no more ticks).
   - Re-check terminal outcome (Taken/Received/Withdrawn/Promised → `status='done'`).
   - Compute `target = tickForElapsedHours(elapsedHours)` clamped to the post's own
     `total_ticks`; if due, overwrite `polygon`/`tick`/`next_expansion_at` from the cached
     schedule entry (no new Dijkstra call — reuses the stored 9-tick schedule).
   - `next_expansion_at = arrival + hazard_hours[tick]`; `status='done'` once `tick=9`
     (168h/7 days after arrival) with no further entry.
8. **Stop conditions that exist today**: reply-saturation (≥5), terminal outcome
   (claim/promise/withdraw — "stop-on-claim"), post removed from `messages_spatial`
   (rejected/expired/deleted — `removeStaleAndRetract`, unscoped runs only), 30-min geometry
   ceiling (implicit — the schedule simply has no tick beyond `max_drive_min`).
9. **Stop conditions that do NOT exist today**: no eyeball/view-based stop, no `E*` target,
   no per-post people-cap, no per-member fatigue cap, no explosion-detector circuit breaker,
   no futility-cut, no trailing-extension, no RU-class-stratified anything in the live path.

---

## 3. `rippling_reach.schedule` JSON — exact contents

Confirmed from `ReachService::parseScheduleResponse` + the `rippling_reach` migration
comment. One row per rippling post; `schedule` (longtext) holds a JSON array, one entry per
hazard-schedule tick (currently 9 entries):

```json
[
  {
    "tick": 1,
    "drive_min": 8.4,
    "cumulative_users": 2909,
    "wkt": "POLYGON((...))"
  },
  { "tick": 2, "drive_min": 12.1, "cumulative_users": 3450, "wkt": "..." },
  ...
  { "tick": 9, "drive_min": 30.0, "cumulative_users": 12544, "wkt": "..." }
]
```

Sibling columns on the same `rippling_reach` row: `msgid` (PK), `lat`/`lng` (blurred
origin), `arrival`, `mode`, `tick` (current cursor, 1-9), `total_ticks` (9), `total_freeglers`
(the full uncapped pool size — this is what the MVP plan calls "the routing schedule already
carries member counts... no schema change needed for member-based governance" — confirmed
true), `max_drive_min`, `polygon` (current tick's geometry, SRID 3857, spatially indexed),
`next_expansion_at`, `status` (`expanding`/`stopped`/`done`), `rejected_groups` (secondary
"out of area" clips), `ripple_intro_sent` (added later). **`cumulative_users` is the
audience-size number every governor design (MVP audience-cap, reply-calibrated `N_t0`) would
key off — it already exists per-tick, per-post, right now, with zero schema change.**

---

## 4. Other data already persisted per post (beyond `rippling_reach`)

| Table | What it holds | Live? |
|---|---|---|
| `rippling_reach_notified` | `(msgid, userid, notified_at)` — who's been mailed, prevents re-notify | Live, load-bearing |
| `rippling_held_replies` | Replies from out-of-reach repliers held pending coverage | Live (`RippleReplyService`) |
| `rippling_event_metrics` | Daily counters per event type (`reply-blocked-by-reach`, `held-reply` transitions, `secondary-group-rejection`, `immediate-mail-on-expansion`, `rippled-in-group`, `reply_saturated`, `outcome_stop`, `reach_shrunk`) | Live, upsert-on-event |
| `rippling_live_metrics` | Weekly rollup: `volume_posts` (per-group + p50/p90 overall), `reach_drive_min` (per-group avg) | **Dormant** — only written by `RippleTuneService::rollup()`, which only runs if `ripple:tune` is invoked, and `ripple:tune` is unscheduled |
| `rippling_hotspots` | Robust-outlier (median+MAD z-score) per-group anomaly flags | **Dormant**, same reason |
| `rippling_params` | Per-`ons_category` proposed/active param overrides | **Dormant + effectively a stub** — see below |
| `rippling_reply_attribution` | Migration exists (`2026_06_23_000003`); not inspected in depth this pass — flagged for follow-up, likely the reply-calibration `r` support table |
| `messages_likes.pageview` / `.source` | Per-(msgid,userid) genuine-open flag + arrival-path tag | **Schema live, partially wired** — see below |

---

## 5. What's newer than the docs (code has moved past the design snapshots)

The three `~/Downloads` design docs and the MVP plan are dated 2026-06-22/28 and describe
several things as unresolved blockers. As of this read (2026-07-02), some have moved:

1. **T1.2 "dwell-gated View split" (design doc's single most load-bearing open issue) is
   now schema-live and partially wired**, not merely proposed:
   - `messages_likes.pageview` (migration `2026_06_22_000001`): 1 = genuine page-open
     (`handleView`), 0 = list-scroll impression (`MarkSeen`), NULL = legacy/unknown.
   - `messages_likes.source` (migration `2026_06_23_000002`): tags arrival path (comment
     explicitly says `?src=ripple_notify` for notification-click opens).
   - `handleView` (`message.go:4506`) now writes `pageview=1` + `source` on a genuine
     open, upgrading an existing scroll-impression row rather than being conflated with it.
     `MarkSeen` (`markseen.go`) writes `pageview=0` and never overwrites an existing `1`.
   - **This resolves the exact ambiguity** the eyeball-governed-reach design (rev.2) calls
     "the single most load-bearing open issue" ("`MarkSeen` and `handleView` write the same
     row with no distinguishing column"). That is no longer true in the live schema.
   - **However it is not fully wired**: no code was found that actually appends
     `?src=ripple_notify` to any outgoing rippling notification link (grepped
     `ExpandService`, `UnifiedDigest.php`, `RippleIntroMail.php` — none build such a URL).
     So `pageview` correctly distinguishes real opens from scroll-impressions today, but
     `source`-based ripple-notification attribution (needed for a clean `r` = eyeballs/
     delivered calibration split by channel) is still not populated in practice. **The
     dwell/pageview half of T1.2 has shipped; the source-tagging half has schema but no
     producer.**
2. **T2.1 Stage-A audience-budget cap (`N_t0`/`target_users`) is fully coded, tested, and
   wired end-to-end** — not just designed. `iznik-routing-go/ripple.go`
   `effectiveScheduleTotal()` (unit-tested in `ripple_extent_test.go`, 6 cases including the
   dense/rural/boundary cases), `ReachService::scheduleParams()` conditionally sends
   `target_users`, `ExpandService::recomputeReach()` exists to retroactively shrink
   already-rippling posts once the cap is turned on. **Everything is dark
   (`RIPPLE_EXTENT_ENABLED=false`) and the value (4000) is an inert placeholder**, but "needs
   a product decision on N*" (the MVP plan's stated blocker) is the *only* remaining gap —
   the plumbing is done.
3. **A self-tuning loop scaffold is built** (`RippleTuneService`, 3 tables:
   `rippling_live_metrics`/`rippling_hotspots`/`rippling_params`) that is more advanced
   machinery than either design doc credits, but it is **weaker than it sounds**:
   - `proposeParams()` only fires when a category's post-volume delta vs its own prior period
     falls outside a flat ±10%/+50% band, and then just writes `max_minutes = 25` (tighten)
     or `35` (widen) — hardcoded literals, not derived from any eyeball/reply/collection
     signal, and **not proportional to how far outside the band** the category is.
   - `categoryVolumeDeltas()` — the method that would compute those per-category deltas — is
     a **literal stub returning `[]`** ("baseline wiring lands once live-vs-baseline data
     accrues (advisory stub)"). So `proposeParams()` never actually proposes anything today
     even if `ripple:tune` were scheduled.
   - `rippling_params` has **no reader** anywhere in the codebase — even if a human promoted a
     proposed row to `status='active'`, nothing in `ReachService`/`ExpandService` selects from
     it. The loop is entirely open (write-only, unconsumed).
   - `ripple:tune` is coded but **not scheduled** in `routes/console.php` (explicit comment:
     "pending decision on production rollout").
   - Ground truth: **this is not a working self-tuning system, it is an unwired scaffold with
     one stubbed method** — closer to a placeholder than the design docs' framing of "existing
     machinery" suggests.

---

## 6. Decisions the existing designs already made (do not relitigate)

From `rippling-out-algorithm.md` "Givens" + the MVP plan + the extent-governor design:

- **Objective is eyeballs, not raw notification count.** People-notified is a proxy, not
  the target. (Design-level decision; not yet implementable — see open items.)
- **Reply is the interim success proxy**, not collection/Taken (too sparse; `Taken` outcome
  data is noisy re: chase-up-prompted marking, per the experiment spec's Blocker/confound
  analysis).
- **Assume the 66%-zero-reply problem is an exposure deficit, not a desirability deficit**,
  pending contrary evidence. First real-data test (33k Offers, 60-30 days old) *supports*
  this (reply rate rises 14%→28% with exposure, holds within rural alone, urban≈rural at
  matched size) — kill-switch did not fire.
- **Rural posts riding to the drive-time ceiling is intended, not a bug.** Any governor must
  not artificially truncate sparse-area reach.
- **A people-count cap that DECREASES with density is wrong** — the taker-distance
  validation (7,800 Taken Offers, 120 days) refutes it: dense-area real takers rank ~1,719th
  nearest active member (mean 5,126th), so a tight cap (1,500) misses 54% of real collectors;
  loosening to `E*=5`(≈7,015 active) only misses ~19%. Collector *abundance* ≠ interested-
  collector abundance.
- **The extent decision is the burst-sizing decision**, because ~70% of the pool is
  committed by tick 1 (~1-3h in) before any eyeball signal can possibly exist for
  digest-majority recipients (lag to eyeball is ~24-31h for daily digest). So governance must
  be feed-forward (frozen prior `r`) for the dominant tick-1 burst; a live closed loop can
  only ever touch the trailing ~30%, and mostly matters as cross-post calibration, not
  within-post control.
- **No per-member fatigue/contention cap until Unified-Digest scroll/click data exists** —
  explicitly deferred, not because it's unimportant but because there's no measurement to
  size it against yet.
- **`r` (conversion prior) must be computed on the ACTIVE-member basis**, matching the pool
  (`users_approxlocs` pool is already ~97.5% active) — historical reply-calibration gives
  `r_reply,active ≈ 1/1,403` (1 reply per 1,403 active members reached), NOT the ~14,600
  figure you get from nominal (dormant-diluted) group membership.
- **The primary test for whichever cap-shape is chosen must be the taker-distance /
  collection-catch validation**, not just a volume-reduction number — a cheap-looking cap can
  still be a collection-catastrophe if it doesn't reach where real interest lives.
- **Group-level (per-group slider) rejected by product owner** (would be set randomly by
  groups) — this is the reason the effort must be a data-derived, self-maintaining, per-area
  method instead.
- **Mod-load and member-notification-volume are governed by DIFFERENT levers**: reply-stop +
  home-first group chunking controls mod load (groups opened); the audience cap (`E*`/
  `target_users`) controls notification volume. Capping members alone does *not* shrink group
  count under the current "ripple into every intersecting group" engine — chunking
  (unbuilt) is required for that.

---

## 7. The decisions that remain explicitly open

In priority/dependency order, as the source designs themselves frame it:

1. **What is `N*` / `E*` — the actual numeric target, and by what unit (people vs
   eyeballs vs replies)?** The MVP plan ships a single flat placeholder (4000); the extent-
   governor design's reply-calibrated version (`N_t0 = E*·1,403`) is a specific, computed
   candidate (from the *existing* reply-saturation-stop threshold of 5, giving `N_t0≈7,015`
   active members) but explicitly flagged "generous v1... learn it later" — not adopted as
   the production value. **This is the parameter the whole effort exists to derive
   properly**, and no per-area/self-maintaining mechanism for setting it exists in the live
   pipeline today (the `rippling_params`/`ripple:tune` scaffold that could hold it is
   unwired and its core proposal method is a stub).
2. **Per-area stratification axis and its calibration.** Two candidate axes exist in the
   codebase and neither is wired into the ripple decision path: ONS RU-class
   (`transport_postcode_classification`, offline-only, used by the simulator/extractor) and
   IMD deprivation quintile (`fairness.go`, wired to a *different* endpoint, `/v1/fairness`,
   never called by `ReachService`). The design docs assume RU-class will be the
   stratification key ("`target_by_ru`") but this key does not exist in
   `config/freegle.php` today — only a flat global `target_users`.
3. **Whether the primitive is a people-count cap, an expand-until-signal (`E*` eyeball/
   reply-count) stop, or a hybrid** — the experiment spec (5-arm design: Control /
   People-cap-Low(500) / People-cap-High(3000) / Signal-stop(2 replies) / Signal-stop+cap) is
   the proposed way to discriminate between these empirically, but it has not run — six
   preconditions are listed as blockers (3,000-member cap, 24h fatigue cap, schema, explosion
   detector, LIA/GDPR sign-off, SAR export), of which the reply-and-notification-volume
   caps are confirmed absent from `ExpandService` today.
4. **Whether `r` (the eyeball-per-notified-member conversion prior) can be calibrated at all
   yet.** The dwell/pageview split (T1.2) has shipped in schema+read form but the
   notification-link `?src=ripple_notify` write-side tagging has not, so a clean
   channel-attributed eyeball signal for calibrating `r` is still not accruing in practice —
   this is a smaller, more precisely-scoped gap than the design docs describe (they treat the
   whole pageview/View ambiguity as unresolved; it's actually just the source-tagging
   producer that's missing).
5. **The explosion-detector / auto-pause circuit breaker** — repeatedly named across all
   docs as the thing that must exist and be wired *before* re-enabling/scaling any governor
   change, on the grounds that "an explosion is by definition a failure regardless of cause."
   Confirmed absent from `ExpandService` (no notified-count threshold check, no
   `rippling_live_metrics`-driven auto-pause).
6. **Whether/how to activate the self-tuning loop** (`ripple:tune`, `rippling_params`) —
   coded scaffold exists but (a) is unscheduled, (b) its core `categoryVolumeDeltas()` is a
   stub, (c) nothing reads `rippling_params` back into the pipeline even if populated, (d) its
   only proposal logic (flat volume-delta band → hardcoded 25/35 min) has no relationship to
   the eyeball/reply/collection-outcome calibration the design docs actually argue for. This
   table/command pairing looks more like an earlier, abandoned first-cut at "self-maintaining"
   than a component the current design should build on as-is.
7. **Mod-load chunking** (`T2.1b`, "fill home group first, open a neighbour only when budget
   exceeds it") — the modelled ~1,185→~133-207 group-count reduction depends entirely on this
   being built; today's engine still opens every intersecting group regardless of any cap on
   member count. Not started.
8. **Per-member fatigue/contention cap** — explicitly deferred pending Unified-Digest
   scroll/click data; still zero fatigue-cap code in `ExpandService` (confirmed by grep).
9. **GDPR/LIA sign-off for out-of-group notification** — flagged as a hard precondition in
   the experiment spec, explicitly marked TBD/placeholder pending Edward's separate
   assessment; not something this read can resolve.
10. **Deliverability→`r` feedback loop guard** — a second positive-feedback path (high
    volume → spam-foldering → measured `r` looks artificially low → bigger `N_t0` computed →
    more volume) flagged as needing an independent high-side volume/complaint circuit
    breaker; not designed in detail, not built.
11. **Recipient de-dup across overlapping groups** (~4.5 groups/member average) — flagged as
    an `r`-denominator and `N_t0`-arithmetic bias; tracked but explicitly "not blocking v1."

---

## 8. Answers to the specific resolve-questions posed

- **Is the live ceiling 30 or 45 min?** **30.** `RIPPLE_MAX_MINUTES` default and only
  configured value is 30 (`config/freegle.php:311`, `ReachService.php:41`). No 45 exists
  anywhere in the live code path. "45-min ceiling" is design-doc language for a proposed
  *future* ceiling once the audience-sized-burst extent governor ships (both the MVP plan and
  the eyeball-governed-reach design use 45 as the intended outer cap once `N_t0`/`target_users`
  becomes the primary stop condition and drive-time becomes "shape only, not the limit") — it
  has not been implemented or configured anywhere.
- **What is the expansion schedule (how much at t=0)?** Not literally t=0 — **tick 1 fires
  at t=1h** post-arrival (first `hazard_hours` entry). At tick 1, `step-70` puts **70% of the
  (uncapped) reachable pool** in reach (`stepCurve(0.70, 9, 1/9)`, ramped smoothly within the
  first tick but production snaps to tick boundaries so it's effectively "70% at the 1h mark").
  The remaining 30% spreads **linearly across ticks 2-9**, i.e. hours 3, 6, 12, 24, 48, 72,
  120, 168 (up to 7 days). A live measurement cited in the extent-governor design confirmed
  this empirically: tick 1 on a real suburban post = 2,909/4,156 members (70%) at 21 min
  drive-time; ticks 2-9 add only the outer 30% out to 30 min.
- **What does `rippling_reach.schedule` JSON contain exactly?** A 9-element array (one per
  hazard-hours tick), each `{tick, drive_min, cumulative_users, wkt}` — see §3 above for the
  exact shape, confirmed against `ReachService::parseScheduleResponse` and
  `rippleScheduleEntry`/`rippleScheduleResponse` in `ripple.go`.
- **What per-RU-class machinery already exists?** An offline reference table
  (`transport_postcode_classification`, ONS 2011 A1-F2 codes) used only by the historical
  extractor for simulator analysis, and a **different**, code-complete but pipeline-
  disconnected IMD-deprivation-quintile isochrone multiplier (`fairness.go`, `/v1/fairness`
  endpoint) that the ripple pipeline never calls. **No RU-class stratification exists in the
  live `/v1/ripple-schedule` → `ReachService` → `ExpandService` path.** The
  `rippling_params.ons_category` column is the closest hook for this, but it has no reader.
- **What governor pieces are built vs planned?**
  - **Built, dark**: the audience-budget cap plumbing end-to-end (Go `effectiveScheduleTotal`
    + tests, `ReachService` conditional `target_users` param, `ExpandService::recomputeReach`
    shrink-in-place backfill) — gated by `RIPPLE_EXTENT_ENABLED=false`.
  - **Built, live**: reply-saturation stop (≥5 repliers), stop-on-claim/terminal-outcome,
    30-min geometry ceiling, `messages_likes.pageview` genuine-open vs scroll-impression
    split (schema + read side).
  - **Built, unwired/stub**: self-tuning loop (`RippleTuneService`, `rippling_params`,
    `rippling_hotspots`) — scheduled command missing, core delta method stubbed, no
    consumer of its output; `messages_likes.source` schema exists but no producer appends
    `?src=ripple_notify` to any real notification link yet.
  - **Planned, not built at all**: per-post people-cap, per-member 24h fatigue cap,
    explosion-detector circuit breaker, per-post group-insertion cap, home-first group
    chunking, RU-class-stratified `target_users`, eyeball/view-based `N_t0` (v2, needs
    trustworthy `r`), the full reach-dose experiment (post-level or ADD/user-level
    redesign) and its schema (`messages.experiment_arm`, `rippling_experiment_assignments`,
    etc.) — none of this exists in `iznik-batch`/`iznik-server-go` as of this read.
