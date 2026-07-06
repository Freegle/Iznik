# Adversary 3 — Engineer/Operator Attack on the Rippling Reach Synthesis

**Target document**: `SYNTHESIS.md` ("Rippling Reach: Audience-Normalized Extent, with Anti-Spiral
Guardrails and Catchment-Map Co-Location"), cross-checked against `1-mechanics.md` through
`6-external-anchors.md` and the live codebase (`iznik-batch/app/Services/Ripple/*.php`,
`iznik-routing-go/ripple.go`, `config/freegle.php`).

**Lens**: buildability and safety only. Does the design really need only what's already persisted?
What breaks operationally — routing server down, schedule JSON missing, new groups, boundary
changes, midnight parameter flips, explosion scenarios, compute cost at 1,525 posts/day, interaction
with reply-holding and the notified-ledger, the 6 named exemplar groups' real geometries (Hull
estuary)?

**Method**: every claim below was checked against the actual PHP/Go source in this working tree, not
just the design prose. File:line references are given so each finding is falsifiable.

---

## FATAL

### F1. The core mechanism the synthesis is "completing" cannot express its own parameter — `target_users` has no per-post/per-group input path anywhere in the code

**Claim under attack** (SYNTHESIS §1, §3): `N*(post) = clip(k × active_members(home_group), 1000,
4000)` — a value computed **per post**, from that post's home group's live active-member count.
SYNTHESIS's central buildability argument (§2, "why D1 wins") is: *"D1 needs zero new schema, zero
new services, zero new experiment apparatus — it supplies a missing number to code that already runs
the full pipeline in shadow."*

**What the code actually does**: `ReachService::__construct` reads `target_users` **once**, from a
single flat Laravel config value, at service-construction time:

```php
// ReachService.php:46-48
$this->targetUsers = config('freegle.ripple.extent.enabled')
    ? max(0, (int) config('freegle.ripple.extent.target_users', 0))
    : 0;
```

This is a **process-lifetime scalar**, not a per-post value. `scheduleParams()` (line 158-160) sends
this one number to the routing server for every origin in the batch, indiscriminately. The Go side
(`ripple.go:289`, `effectiveScheduleTotal`, line 250-255) also takes a single `targetUsers int` per
HTTP request — there is no `home_group_id` parameter in the `/v1/ripple-schedule` request at all
(confirmed: `scheduleParams()` sends `lat, lng, mode, ticks, max_minutes, curve, target_users` only —
no group identifier of any kind).

To compute `N*(post) = clip(k × active_members(home_group), ...)` as specified, someone has to:
1. Resolve each post's home group (a join that doesn't happen anywhere in `initialiseNew`'s current
   hot path — home-group resolution happens *after* rippling, in downstream tables, not before it).
2. Query that group's live `active_members` count (a `memberships` × `users.lastaccess` join — not
   cached, not currently computed in the ripple pipeline at all; §6 of `5-demand.md` is the *only*
   place in the entire evidence base this query is run, as an ad-hoc analysis query, not production
   code).
3. Look up that group's current density band's `k` (a value that, per §11, lives in `rippling_params`
   — a table confirmed to have **zero readers anywhere in the codebase**: `grep -rn rippling_params
   iznik-batch/app/Services/Ripple/*.php` returns only `RippleTuneService.php`'s own writer).
4. Compute and clip `N*`, then pass it through to `ReachService` as a **per-call**, not
   per-construction, parameter.

None of steps 1-4 exist in code today. This is not "supply a missing number" — it is "add a new
per-post computation stage to a service that is currently architected around a single static
config value," plus wire a genuinely new reader for a genuinely dead table, plus change
`ReachService`'s constructor-time parameter into a call-time parameter (a real, if modest,
refactor touching `computeSchedule`, `computeSchedulesBatch`, and `scheduleParams`). SYNTHESIS's
central "completes shipped infrastructure, zero new services" claim (§2, bullet 2) is **false as
stated** — what's shipped is a single-global-scalar audience cap; what's proposed is a per-group,
periodically-refreshed function, and the gap between those two is a real, non-trivial engineering
task, not a config flip.

**Verdict: FATAL.** The single strongest sentence in the whole synthesis's case for D1 ("zero new
schema, zero new services... supplies a missing number") does not survive contact with
`ReachService.php`.

**Concrete fix**: rewrite §2/§3 honestly — this design requires (a) a home-group-active-members
lookup added to `initialiseNew`'s per-post loop (cheap: one indexed query per distinct home group
per batch run, not per post — group membership counts change slowly, can be cached for the run),
(b) `ReachService::computeSchedule`/`computeSchedulesBatch` changed to accept `target_users` as a
per-call argument instead of a constructor-time field, (c) an actual reader for `rippling_params`
wired into step (a)'s lookup. None of this is large — it is a real, scoped, one-to-two-day PHP
change — but it must be scoped and estimated, not waved away as "zero new services."

---

### F2. The origin-blur reuse cache will silently serve the WRONG N* once target_users varies per post — a real, deterministic data-corruption bug, not a hypothetical

**Claim under attack**: implicit throughout — the design assumes `computeSchedule` can be called
per-post with a per-post `target_users` and everything downstream continues to work as today.

**What the code actually does**: `ExpandService::initialiseNew` deduplicates routing calls by
**blurred origin only** (`ExpandService.php:707-728`), and — critically — reuses any existing
`rippling_reach` row's stored schedule for the same blurred `(lat,lng)` key without re-querying the
routing server at all (`ExpandService.php:740-772`, the "Reuse" block). The code's own comment states
the precondition explicitly:

```php
// ExpandService.php:732-739
// Reuse: a reach schedule is a deterministic function of the blurred origin (+ global ripple
// config - see the Phase-1 note above), so if another live post at the SAME blurred origin
// already has a real computed reach, copy its schedule rather than hit the routing server
// again. ... Exact because blurOrigin quantises to 4dp and only the blurred origin + global
// config feed the schedule. If that config (curve/max_minutes/extent) ever changes, set
// freegle.ripple.reuse_reach=false for one full recompute so stale reaches are not reused.
```

The entire correctness argument for this cache is **"only the blurred origin + GLOBAL config feed
the schedule."** That precondition is true today (one global `target_users` for the whole network)
and becomes **false** the moment `target_users` is computed per-post from `active_members(home
group)`, because two posts can share a blurred origin (~400m quantisation — the code's own comment
notes this is measured at "~2.6x" reuse rate on prod, i.e. it fires constantly, not rarely) while
belonging to **different home groups with different `active_members`**, which is common near any
group boundary (exactly the geography the Discourse complaints are about — dense, overlapping,
adjacent London boroughs). The second post posted from that shared blurred origin would silently
receive the *first* post's `N*`-derived schedule, not its own — an invisible, un-logged, wrong-cap
bug that would fire routinely in exactly the highest-value target area (inner London), not at the
edges.

This is not a one-off migration hazard (the "set `reuse_reach=false` for one recompute" comment
addresses global config changes, e.g. changing `k` at a quarterly re-fit — a temporary, known, planned
event). It is a **structural, permanent** violation of the cache's invariant for as long as `k`/`N*`
varies below the level of "global config," which is the design's entire premise.

**Verdict: FATAL.** The reuse-cache optimization this codebase already relies on for throughput
(§F5 below covers the cost consequence of losing it) is invalidated by the design's own core idea,
and nothing in SYNTHESIS or its constant-derivation table (§3) mentions this at all — it is a genuine
blind spot, not a deprioritized risk.

**Concrete fix**: either (a) extend the reuse-cache key to include a coarse discriminator (e.g.
`(blurred_lat, blurred_lng, density_band)` instead of just `(blurred_lat, blurred_lng)` — cheap,
since density band changes quarterly not per-post, so cache hit rate stays high within a quarter), or
(b) disable `reuse_reach` entirely once per-group `target_users` ships and accept the compute-cost
hit (quantify against F5 before deciding). Either way this must be an explicit line item in the
implementation plan, not an afterthought.

---

## MAJOR

### M1. `recomputeReach` can only SHRINK an already-scheduled post, never grow it — the quarterly re-fit is one-directional for the ~24,000 posts already mid-flight at any given moment, contradicting the "self-maintaining" framing

**Claim under attack** (SYNTHESIS §11, step 4): *"Write the new values to `rippling_params`... and
have `ReachService.php` read `k`/`N*_min`/`N*_max` from there... This is the first real consumer of
the stubbed `RippleTuneService::categoryVolumeDeltas()`."* The design frames the quarterly re-fit as
symmetric maintenance — values can move up or down as evidence dictates (§4's anti-spiral guardrails
are explicitly two-sided in spirit: "no quarterly re-fit may move `k`... by more than 15%... in
either direction" is the natural reading, and §12 con #1 explicitly discusses `k` needing to possibly
be *raised* for the sparse tercile if the untested assumption turns out wrong).

**What the code actually does**: the only mechanism that applies a changed `target_users` value to a
post that has *already* been scheduled is `ExpandService::recomputeReach()`
(`ExpandService.php:162-246`), and it is **hard-coded shrink-only**:

```php
// ExpandService.php:166-168
$target = (int) config('freegle.ripple.extent.target_users', 0);
if (!config('freegle.ripple.extent.enabled') || $target <= 0) {
    return $stats; // cap not active — there is nothing smaller to shrink to
}
...
// ExpandService.php:175
->where('total_freeglers', '>', $target);      // only rows that can exceed the cap
...
// ExpandService.php:196-200
// Only proceed if the recomputed reach really is smaller than the pool
// (i.e. the cap actually bound for this origin).
if ($newMax <= 0 || $newMax >= (int) $row->total_freeglers) {
    $stats['skipped']++;
    continue;
}
```

There is no symmetric "grow" path. If a quarterly re-fit *raises* `k` for a density band (e.g.
because the anti-spiral backtest catches an over-tightening, or genuinely because a sparse tercile's
`k` needed correcting upward per con #1's own admission), every post that is currently `expanding` or
was already `done` under the old, smaller `N*` **stays capped at the old, smaller value** — because
`advanceDue` (`ExpandService.php:897+`) re-plays the schedule that was frozen into the row's `schedule`
JSON at creation time (`tickForElapsedHours`, `entryForTick` — never a fresh routing call), and
`recomputeReach` explicitly refuses to act unless the *new* target is *smaller* than the post's
existing `total_freeglers`.

This means: **the quarterly re-fit only ever tightens the world it can already see, and can never
widen an already-emitted post even when the design's own backtest says it should.** For the roughly
24,000 posts in-flight at any moment (per `4-audience-curves.md` §0: 12,219 `expanding` +
some fraction of `done` still within the 7-day notification window), a re-fit that raises `N*`
mid-quarter has **zero effect** on them — only future, not-yet-created posts benefit. This directly
undercuts §9's failure-mode table entry "Self-reinforcing tightening spiral... Mitigation: the §4
graft" — the graft (backtest before shrinking) governs new posts going forward; it does nothing to
*reverse* an over-tightening already baked into thousands of live rows, because there is no live
"widen" path to reverse into.

**Verdict: MAJOR.** This is a real, verifiable asymmetry in the existing code that the design's
"self-maintaining, symmetric" framing does not acknowledge and the anti-spiral guardrails do not
address (they gate the *decision* to shrink, not the *irreversibility* once applied to a cohort of
live posts).

**Concrete fix**: either (a) build a symmetric "grow" path in `recomputeReach` (drop the `>` filter,
re-fetch schedule unconditionally for a bounded sample when `k` moves, apply if `newMax` differs from
`total_freeglers` in either direction — this doubles the query volume of an already-manual, unscheduled
command, so needs its own cost/scheduling plan), or (b) state explicitly and simply in the design that
quarterly re-fits are "forward-looking only, in either direction" — i.e. a raised `k` only benefits
posts created after the re-fit, exactly mirroring how a lowered `k` currently only benefits posts
recomputed via the *manual, unscheduled* `ripple:recompute` command (see M2). Document which of (a)/(b)
is chosen; SYNTHESIS currently implies (a)'s behaviour while the code only supports (b), and does not
say so.

---

### M2. The activation path this design depends on (`ripple:recompute`) is not scheduled, so "Stage 1 canary" and the quarterly re-fit both require someone to remember to run a manual command — no automatic linkage exists between "new `k` written" and "already-live posts adjusted"

**Claim under attack** (SYNTHESIS §10, Stage 1): *"Enable `RIPPLE_EXTENT_ENABLED=true` with the
derived formula for a small number of dense-tercile test groups"* — phrased as a flag flip.

**What the code actually does**: `grep -rn recomputeReach|ripple:recompute
iznik-batch/routes/console.php` returns **nothing** — `RecomputeReachCommand` exists
(`app/Console/Commands/Ripple/RecomputeReachCommand.php`) but is not wired into the Laravel
scheduler, same unscheduled-command pattern already flagged for `ripple:tune` in `1-mechanics.md`
§5.3 ("`ripple:tune` is coded but **not scheduled**... pending decision on production rollout"). This
is a second, independent instance of the same "coded but not wired to run" gap, not covered by the
mechanics report because it wasn't asked about in that pass.

Practically: turning `RIPPLE_EXTENT_ENABLED` on changes behaviour **only for posts not yet given a
schedule** (new posts, from that moment). Every post already `expanding` (12,219 rows at last count)
or recently `done` continues on its already-baked, uncapped schedule until it separately falls out of
the 7-day notification window — there is no automatic "apply the newly-enabled cap to the current
in-flight cohort" step; that requires someone to separately invoke `ripple:recompute` (manually, since
it's unscheduled) immediately after the flag flip, and to re-invoke it after every subsequent
quarterly re-fit for the "shrink" direction specifically (M1 shows "grow" isn't even supported).

**Verdict: MAJOR.** Not fatal — the flag-flip-then-forget behaviour is at worst a slow rollout
(new posts adopt the new value within hours, all posts within 7 days), which for a *quarterly*
cadence is a tolerable lag. But SYNTHESIS's "Stage 1... enable `RIPPLE_EXTENT_ENABLED=true`" language,
and the self-maintenance loop's step 4-5 in §11, describe a system that "writes the new values" as
if that alone changes live behaviour. It does not, for the in-flight cohort, without a second manual
step this design never names.

**Concrete fix**: name `ripple:recompute` explicitly in the rollout plan (§10) and the self-maintenance
loop (§11) as a required step after every `k`/`N*` change, decide whether it should be scheduled
(cron, run once per re-fit) or remain a manually-triggered, ops-runbook step, and state the expected
lag this introduces (new posts: immediate; in-flight posts: only if `recompute` is run and only in
the shrink direction per M1; already-`done`-and-past-notification posts: never, by design, since
there's nothing left to adjust).

---

### M3. No explosion detector, no per-post notification cap, no fatigue cap exist anywhere in `ExpandService` today — the design inherits, unmodified, a documented absence of the one safety mechanism every prior design doc calls a hard precondition for any governor change

**Claim under attack**: SYNTHESIS's failure-mode table (§9) and kill-criteria (§10) present the
design as operationally safe, with rollback ("one config flag") as the stated safety net.

**What the mechanics report already established** (not re-derived here, cited as load-bearing):
`1-mechanics.md` row 23 confirms, by direct grep, that the reach-mail caps named as required
blockers in the experiment spec (3,000-member notification cap, 24h fatigue cap, 5,000-threshold
explosion detector, per-post group-insertion cap of 5) are **"Not present in `ExpandService.php` at
all."** §7 item 5 restates this as an open decision explicitly: *"The explosion-detector / auto-pause
circuit breaker — repeatedly named across all docs as the thing that must exist and be wired before
re-enabling/scaling any governor change... Confirmed absent from `ExpandService`."*

SYNTHESIS does not mention this gap anywhere in its own text (§9's failure-mode table has no row for
"routing/compute output produces an anomalously large N\* or schedule and nothing catches it before
it mails" — the closest is the anti-spiral 15%-per-period cap in §4, which governs the *quarterly
re-fit's proposed constant*, not a **per-post runtime anomaly** such as a bad `active_members` count
for one group on one day, a group merge that suddenly doubles a group's membership overnight, or a
routing-server bug that returns a wrong `cumulative_users` for one origin). The 15%-per-quarter cap on
`k` cannot catch a same-day anomaly, because it only fires at the 90-day re-fit boundary — a bad
`active_members` read for one group is live and mailing immediately, for up to a full quarter, before
the guardrail even evaluates it.

This matters specifically because the design's *own mechanism* (`active_members(group)`, "a live
query recomputed on every post" — §9's cold-start/seasonality row) is precisely the kind of
per-request, unvalidated, ungated input an explosion detector exists to catch: if `active_members`
returns a corrupted or anomalously large number for one group on one day (e.g. a membership-import
bug, a bot signup wave counted as "active," or a JOIN bug after a schema change), `N*` for every post
from that group that day inherits the bad number, uncapped except by the static `N*_max=4,000` ceiling
— which itself only bounds the *target*, not the notification volume actually sent (per `5-demand.md`
§5, notification volume is separately throttled and only loosely correlated with `total_freeglers`
today; this design does not change that separately-throttled layer at all).

**Verdict: MAJOR**, not FATAL, because the *existing* system (before this design) already has this
gap and has been running in production since go-live without an explosion detector — so this design
does not introduce a *new* class of failure, it just fails to close a pre-existing one while adding a
new, more variable input (`active_members`, refreshed quarterly, per-group) into the same ungated
pipeline. Rated MAJOR rather than MINOR because the design's own worked examples (§7) show `N*` for
dense London groups near the top of the 1,000-4,000 band, i.e. this design specifically increases how
much day-to-day variance a bad `active_members` read could inject relative to today's flat, single,
manually-set 4,000 constant (a bad per-group input has a blast radius today's flat config cannot have).

**Concrete fix**: state explicitly, as an explicit rollout precondition (not an open question buried
in §13), that Stage 1 should not begin until at minimum a same-day sanity check exists — e.g.
"`active_members(group)` read at schedule-compute time must be within some bounded ratio (say 2x) of
that group's `active_members` value from the last successful quarterly re-fit, else fall back to the
static default and alert" — a cheap, code-level guard that does not require the full experiment-spec
explosion detector to be built, but closes the specific new failure mode this design introduces.

---

### M4. Hull's real geometry is an estuary/toll-avoidance case with existing water-crossing logic — the synthesis's "audience-normalization self-corrects coastal geometry" claim (§9) is asserted, not verified against how Hull's polygon is actually shaped

**Claim under attack** (SYNTHESIS §9, failure-mode table, row 1): *"Coastal / estuary geometry... A
half-circle catchment (coastal town) could behave oddly under a fixed-radius rule... Self-corrects
structurally: N\* is defined on active-member count, not distance or area, so a coastal town simply
takes longer to accumulate the same N\* than an inland town of equal density. A genuine strength of
audience-normalization over any fixed-time or fixed-distance design."**

**What the evidence base actually shows about Hull specifically**:
- `2-discourse.md` §2 (#389/390) and §4 confirm the live, named Hull complaint is not "coastal
  geometry produces a slow-filling half-circle" (the benign case SYNTHESIS's mitigation addresses) —
  it is **"over the water"**: a mod objecting that Castleford reaches Hull's area via Howden, "just
  inside Hull's area," 29 minutes by car, which Edward confirms is legitimately within the drive-time
  isochrone. This is exactly the opposite failure mode from the one SYNTHESIS's mitigation discusses:
  the complaint is that the **estuary-adjacent isochrone reaches too far around the water via a land
  detour**, not that it fills too slowly. Water/toll avoidance logic already exists and was
  specifically added for this class of complaint (#159-165, "avoid rippling across water/via tolls
  unless there's a real bridge/tunnel").
- `4-audience-curves.md` §3/§3c confirms Hull (group 21473, n=51 posts) has **audience p50 = 659 at
  the 30-min ceiling**, and is **100% never-reach even at N\*=2,000** — i.e. under this design, Hull
  is entirely `T_max`-bound (unchanged from today), exactly as SYNTHESIS's own §7 worked-example table
  states. On this specific numeric point SYNTHESIS is internally consistent with the evidence (Hull
  does stay at the floor, unaffected) — but that is a *different* claim from "audience normalization
  self-corrects the estuary geometry," which is not demonstrated anywhere. Hull being N\*-inert says
  nothing about whether its *isochrone shape* is well-behaved; it only says the design's audience
  target never binds there, so the isochrone shape is exactly as good or bad as it is **today**,
  unchanged, ceiling-bound, water-avoidance-gated exactly as now. The "self-corrects structurally"
  language in §9 asserts a *causal mechanism* (audience-normalization fixes weird coastal shapes) that
  the evidence does not test and the Hull numbers do not demonstrate — the correct, narrower claim is
  "this design does not make Hull's existing water-avoidance-gated shape any better or worse, because
  it never binds there."

**Verdict: MAJOR.** Not because the design breaks Hull (it doesn't — it's correctly inert there per
the dark-compute), but because §9's stated *mechanism* ("N* self-corrects coastal geometry") is an
unverified causal claim dressed as a demonstrated property, sitting in the one place (the failure-mode
table) a reader would trust as rigorously checked. The actual, defensible claim is much narrower and
should replace it.

**Concrete fix**: reword §9's coastal/estuary row to the narrower, evidence-backed claim: "Coastal
and estuary groups (Hull confirmed via dark-compute) sit below `N*_min` at essentially all observed
audience sizes and remain entirely governed by `T_max` and the existing water/toll-avoidance isochrone
logic (#159-165), unchanged by this design. This design neither fixes nor worsens estuary-shaped
reach; it simply doesn't engage there." Drop the "self-corrects" framing, which implies a mechanism
this design does not actually exercise for the one named exemplar with real coastal/estuary geometry.

---

### M5. Compute-cost claim ("dark-compute... zero new pipeline") is understated once the reuse-cache breaks (F2) — Stage 0's own re-validation sweep (5 values of `k` × 10,742 posts) was cheap only because it replayed *stored* schedules, not because live per-post computation at this scale is cheap

**Claim under attack** (SYNTHESIS §10, Stage 0): *"Pure SQL against existing tables"* — true for
Stage 0's *validation* sweep, which replays already-persisted `rippling_reach.schedule` JSON. But §10
and §3's constant-derivation table imply the live production mechanism is equally cheap ("`k` ...
re-fit quarterly," "`active_members(group)`... a live query... zero new data pipeline").

**What the actual cost structure is**: today, `initialiseNew` dedupes routing calls by blurred origin
at a **measured 2.6x reuse rate** on prod (`ExpandService.php:710`, comment: "measured ~2.6x on
prod"). At 1,525 offers/day, that means roughly 1,525 / 2.6 ≈ **587 actual routing-server Dijkstra
calls/day** today, not 1,525. Per F2, once `target_users` varies per home group, the reuse-cache's
correctness precondition breaks for any two co-located posts from different groups — meaning either
(a) the cache silently serves wrong answers (F2's fatal finding), or (b) the fix is to disable/narrow
`reuse_reach`, which **restores routing-call volume toward the full 1,525/day** (or higher, since
`recomputeReach` drains — M1/M2 — add a second, separate wave of routing calls per re-fit cycle,
sized by however many of the ~24,000 in-flight rows are candidates). This is roughly a **2.6x
increase in routing-server load** at minimum, concentrated in exactly the London/dense-tercile
geography (§7's worked examples) where posts are already highest-volume and the reuse rate is
presumably even higher than the network average (dense areas → more co-located posts → more cache
hits today → more cache invalidation cost once per-group `target_users` breaks the key).

**Verdict: MAJOR.** Not FATAL — 1,525-4,000 routing calls/day is very likely still well within the
`compute_concurrency=8` Dijkstra-per-request routing host's headroom (no load-test data exists either
way in the evidence base to confirm or deny this, which is itself the gap). But the design's own
framing ("zero new pipeline," "already computes... zero new data pipeline") describes the *validation*
step's cost, not the *production* mechanism's cost once F2's cache-key fix is applied, and nowhere in
SYNTHESIS is this quantified or flagged as a thing to load-test before Stage 1.

**Concrete fix**: before Stage 1, measure actual routing-server request volume/latency under
`reuse_reach=false` (or the F2-fixed cache key) at current 1,525/day production volume, not just at
Stage-0's already-persisted-data replay cost. If headroom is fine, say so with a number; if it isn't,
the compute_concurrency knob (already a config value, `ripple.compute_concurrency=8`) is the lever,
and that trade-off belongs in this design, not left implicit.

---

## MINOR

### N1. `reply_saturation_stop` dead-code claim is correct but the "was 5, now 3" language in §3/§13 undersells that this is a *separate*, already-identified, trivially-shippable fix the design bundles in without its own validation gate

`5-demand.md` §1 and `1-mechanics.md` row 10 independently confirm `reply_saturation_stop=5` has
never fired (0/10,742 posts). SYNTHESIS §3 recalibrates it to 3 as part of "the complete constant
inventory" and §13 correctly flags it as "not the evidence base's primary target, deserves its own
small validation pass." This is honestly caveated already — noted here only because §1's one-sentence
formal rule states `reply_saturation_stop = 3` as if already decided, which is inconsistent with §13
correctly treating it as still open; tighten §1's phrasing to "3 (proposed, pending its own
validation pass — see §13)" so the two sections agree.

### N2. The motorway-corridor artifact (#373, Portobello→Livingston) is not fixed and could be made LESS visible, not more, by audience-normalization

Discourse #373 (`2-discourse.md` §4 item 5) describes an isochrone that stretches narrowly along the
M8 rather than covering an area — a real, named, unaddressed display/shape problem. Audience-count-based
`N*` does not fix this (a thin fast corridor accumulates `cumulative_users` at whatever rate the
addressable population along that corridor implies, same distortion, different stopping rule) and
arguably makes it *harder to spot* for a mod glancing at the catchment map, because the map now shows
"stopped because it hit its people-target," which sounds authoritative/deliberate, rather than
"stopped at a fixed time," which more obviously invites "why does this weird shape reach that far."
SYNTHESIS's §5 catchment-map graft does not mention this interaction. Worth one sentence in §5 or §9
noting the corridor-artifact problem is orthogonal to and not addressed by this design, matching the
honesty already applied to mod-load (§9's explicit "not fixed by this design" entries).

### N3. Sample schedule data shows tick-4-through-tick-9 sharing an *identical* WKT polygon while `cumulative_users` climbs 3,250→4,000 — a resolution artifact in the underlying routing output that a tight per-post `N*` will interpolate across, producing spuriously precise drive-time numbers

Inspecting `sample_schedule.txt` (a real persisted `rippling_reach.schedule` row) shows ticks 4-9 all
report `drive_min=16.295733642578124` with the byte-identical polygon WKT, while `cumulative_users`
still increases each tick (3250, 3400, 3550, 3700, 3850, 4000). This means the underlying routing
isochrone snapped to the same node/resolution boundary for six consecutive ticks while the audience
count kept incrementing on synthetic/estimated grounds (a modelling artifact, not a road-network
change). `4-audience-curves.md`'s N\*-crossing JS (§0) linearly interpolates `drive_min` between ticks
to report "time to cross N\*" — for a post like this one, interpolating between two ticks that share a
polygon produces a `drive_min` estimate that looks precise (e.g. "13.2 minutes") but is actually
reporting a resolution ambiguity as if it were a real number. This does not invalidate the aggregate
tables (large-N statistics average this out), but it means any *single-post* or *single-group*
worked-example drive-time figure quoted to a moderator (per §5/§8's map graft, which shows a live
per-group number) could show spurious precision for posts landing in one of these resolution
plateaus. Worth a rounding/precision caveat in the mod-facing copy (§8) or the map implementation.

---

## Summary table

| # | Severity | One-line finding |
|---|---|---|
| F1 | FATAL | `target_users` has no per-post/per-group code path anywhere; "zero new services" claim is false |
| F2 | FATAL | Origin-blur reuse cache silently serves wrong `N*` once target varies per group (same postcode, different home groups) |
| M1 | MAJOR | `recomputeReach` is shrink-only; a raised `k` never reaches the ~24,000 in-flight posts |
| M2 | MAJOR | `ripple:recompute` is unscheduled/manual; flag-flip alone does not touch in-flight posts |
| M3 | MAJOR | No explosion detector/notification cap exists; a bad per-group `active_members` read is now a live, ungated input with a bigger blast radius than today's flat constant |
| M4 | MAJOR | "Audience-normalization self-corrects coastal geometry" is an unverified causal claim; Hull's real complaint (estuary detour, water/toll-avoidance) is untouched by this design, not fixed by it |
| M5 | MAJOR | Compute-cost claim describes Stage-0's cheap SQL replay, not production cost once the reuse-cache is fixed/disabled (~2.6x routing-call increase, unquantified, concentrated in dense areas) |
| N1 | MINOR | §1's formal rule states `reply_saturation_stop=3` as settled; §13 correctly treats it as still-open — align the two |
| N2 | MINOR | Motorway-corridor artifact (#373) not addressed, possibly obscured by audience-normalization's authoritative-sounding stop reason |
| N3 | MINOR | Sample schedule data shows resolution-plateau ticks; interpolated single-post drive-time figures can be spuriously precise |

## What would need to change before this design is buildable as specified

1. Rewrite §2's "zero new services" claim to name the real scope: a new per-post
   home-group/active-members lookup, a `ReachService` call-time (not construction-time) parameter
   change, and an actual `rippling_params` reader (F1).
2. Fix or explicitly disable the origin-blur reuse cache before any per-group `target_users` ships,
   and re-measure routing-server load under the fixed/disabled cache (F2, M5).
3. Decide and document whether quarterly re-fits are forward-only (cheapest, but weakens the
   "self-maintaining, corrects both ways" framing) or build a symmetric grow path in
   `recomputeReach`, and either way name `ripple:recompute` explicitly as a required step with an
   owner and a schedule (M1, M2).
4. Add a minimal same-day sanity guard on `active_members(group)` before Stage 1 — this design's
   quarterly-refreshed, per-group input is a new class of blast-radius risk the existing flat
   config never had, and no explosion detector exists anywhere in `ExpandService` to catch it (M3).
5. Reword the coastal/estuary failure-mode entry to the evidence-backed claim (N\*-inert, not
   "self-correcting"), and explicitly state Hull's real Discourse complaint (estuary detour via
   Howden) is untouched by this design (M4).

None of these five is a reason to abandon D1/the synthesis's overall direction — the audience-target
philosophy, the within-tercile confound-check, and the dark-compute worked examples remain the
strongest evidence-backed part of the whole exercise. But as specified, §2's buildability claim is
overstated, and Stage 1 should not begin until F1/F2 are scoped as real engineering work and M1-M3
have explicit answers, not implicit assumptions.
