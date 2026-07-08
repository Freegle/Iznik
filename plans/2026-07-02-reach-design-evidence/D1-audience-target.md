# Design D1 — Audience Normalization: reach governed by expected-active-audience, not drive-time

**Philosophy 1 of 4.** The tuning parameter is an audience size, N\* (expected active members reached),
not a time. Equal drive-time across areas is the bug — it produces a 14-80x spread in realized audience
for identical "30 minutes" depending on where you live (§ Evidence). Equal-*audience* is the fix: every
post expands, area-appropriately, until it has reached a calibrated number of people, and drive-time
becomes an *output* of that process (and a safety envelope), not the control variable.

Author's stance up front: this is not a new invention. It is the existing, already-coded, currently-dark
Stage-A extent governor (`RIPPLE_EXTENT_ENABLED`, `RIPPLE_EXTENT_TARGET_USERS=4000` in
`config/freegle.php`) — this design's job is to answer the question the MVP plan explicitly left open:
**how is N\* derived, stratified, and kept correct**, not whether the audience-budget mechanism is the
right shape (the mechanism already exists end-to-end in `iznik-routing-go/ripple.go` and
`ReachService.php`; see Mechanics report §1 rows 14-15, §5 point 2).

---

## 1. The rule, precisely

### 1.1 Core mechanic (unchanged from the existing MVP — this is the "adopt" part)

Expansion proceeds tick-by-tick exactly as today (the live 9-tick hazard schedule
`[1,3,6,12,24,48,72,120,168]` hours, step-70 curve — Mechanics report §2). At every tick, stop expanding
a post's reach polygon as soon as **either**:

- `cumulative_users >= N*(post)` (the audience-budget stop — currently coded, dark), **or**
- `drive_min >= T_max` (the geometry ceiling — currently 30 min, live), **or**
- distinct Interested repliers `>= reply_saturation_stop` (currently 5, live but never fires in
  practice — see §1.4 for why this parameter is itself miscalibrated and should move in lockstep).

`T_min` (floor) sets a minimum drive-time below which expansion cannot stop even if `N*` is already
exceeded at tick 1 (protects very dense areas from a single-tick, sub-10-minute reach that would feel
arbitrarily small and defeat the "give it a fair local area" intent of rippling at all).

This is precisely the MVP plan's Stage-A mechanism. What's new in this design is §1.2-1.3: **how N\*
itself is set, per area, and kept correct** — the piece the MVP plan explicitly punted on ("needs a
product decision").

### 1.2 Deriving N\* — three converging lines of evidence, triangulated to one number

**This is the crux of the audience-normalization philosophy: N\* is not chosen, it is measured.** Three
independent analyses in the evidence base all point to the same order of magnitude, and each is a
non-arbitrary estimation procedure, not a guess:

**(a) Demand-sufficiency flattening point** (Demand report §1, §6 — the primary evidence). Reply
probability P(≥1 reply) and mean distinct repliers, plotted against realized audience, are **flat to
gently declining** across the entire observed range (131 to 17,656 members), with no clean knee. But the
within-density-tercile breakdown (Demand §6, the strongest single result in the whole evidence base)
shows a real, reproducible peak-then-decline shape *within* each density class:

| Density class (home-group active members) | Audience at peak P(≥1 reply) | P(≥1) at peak | Audience where it's visibly worse |
|---|---|---|---|
| Sparse (<1,000 active) | flat throughout (~250-3,400 observed) | ~31% | not reached in data |
| Medium (1,000-1,726 active) | ~1,000 | 41.3% | ~7,700 → 24.7% |
| Dense (1,726-3,469 active) | ~1,700 | 38.8% | ~13,000 → 21.8% |

The peak sits at **roughly 1x the home group's own active-member count**, for both the medium and dense
terciles (the only two where a peak is actually observed within range — sparse groups never generate
enough audience to see the decline). This is the estimation procedure:

> **N\*_demand(group) ≈ active_members(group)** — a group's own active membership, refreshed by the
> existing membership/`lastaccess` machinery, is itself the best predictor of the audience size at which
> reply probability peaks for that specific local reply-density.

This is elegant because it requires no new stratification variable at all: it falls directly out of a
number Freegle already computes per group.

**(b) Catch-rate / collection-coverage curves** (Behaviour report, cap-to-coverage table + density
split). The realistic, revealed-preference target for "how many people is enough to reliably produce a
collector" is where taker-distance coverage saturates. Network-wide, 20km captures 95.6% of all
collections and 30km captures 98.2% — i.e. returns beyond a certain radius are minimal. Translated to
audience terms via the exemplar groups (Behaviour §, density split): TowerHamlets reaches 95% collection
coverage at ~5-8km (a dense-area radius corresponding to an audience in the low thousands at TowerHamlets
density), while Swindon is still climbing at 20km+. This independently corroborates (a): the "enough"
point scales with local density, not with a fixed radius or a fixed population number.

**(c) Reply-saturation-stop precedent** (Discourse #8415, cited in Mechanics as "the sole cited basis"
for `reply_saturation_stop=5`; corroborated as a real community-derived number). Five distinct repliers
was the community's own answer to "how many replies do you need before an Offer has enough interest".
Converting that reply target to an equivalent audience via the measured reply rate `r_reply,active ≈
1/1,403` (1 reply per 1,403 active members reached — Mechanics §6, from historical calibration) gives
`N_t0 ≈ 5 × 1,403 ≈ 7,015` active members. This is materially higher than (a)'s ~1,000-2,000 peak because
the community's "5 replies" bar was set without knowledge that reply *rate* declines, not just plateaus,
past ~2,000; 7,015 is best read as an **upper anchor / sanity ceiling** on N\*, not the target itself —
the demand data (a) says you'd be well past the peak and into the decline zone by 7,015 for medium/dense
groups.

**Triangulation.** (a) and (b) agree tightly (low-thousands, scaled by local density); (c) sits above
both, consistent with a community expectation formed before rippling existed (when reaching 5 repliers
required going far outside a small home group, so more reach genuinely helped). Since (a) is the most
direct, freshest, causally-clean signal (with the exposure confound explicitly ruled out — Demand §6),
**(a) is the primary derivation, (b) is the cross-check, and (c) is the outer sanity bound.**

### 1.3 The formula

```
N*(post) = clip( k × active_members(home_group),  N*_min,  N*_max )
```

where:

- **`active_members(home_group)`** — count of memberships with `collection='Approved'` and
  `lastaccess` within the last 90 days on the post's home group. This is a metric Freegle already
  computes (it is literally the denominator used to build the density terciles in Demand §6). No new
  data pipeline is required.

- **`k`** (the multiplier) **= 1.0**, derived directly from Demand §6: the observed reply-probability
  peak sits at approximately 1x each tercile's own active-member scale (medium tercile active-member
  midpoint ~1,363, peak audience ~1,000; dense tercile active-member midpoint ~2,598, peak audience
  ~1,700 — both are 0.65-1.0x, so k=1.0 is a defensible, round, slightly-conservative (errs toward more
  reach, not less) fit rather than an overfit to two noisy points). **Re-fit each quarter** from fresh
  reply-decile data (§5) — k is exactly the kind of single scalar a periodic re-fit job can safely
  adjust without a product conversation each time, because it's derived the same way every time.

- **`N*_min` = 1,000.** Floor. Derived from Demand §1's fine bucket table: the 500-1,000 bucket already
  shows P(≥1)=34.2%, materially better than the 0-250 bucket (27.0%) and close to the observed peak
  (~35-41%) — going below ~1,000 measurably costs reply probability even in the sparsest observed data,
  so 1,000 is the point below which N\* should never be allowed to shrink regardless of how small a
  group's active-member count is. This also matches the "usable N\* range" lower bound independently
  identified by the Audience-curves dark-compute (§2 there: N\*=1,000 is the smallest value that
  discriminates at all — below it, the schedule's own tick granularity dominates, not the target).

- **`N*_max` = 4,000.** Ceiling on the multiplier's output, *not* a promise that every post reaches
  4,000 — it exists to stop `k × active_members` from over-extending very large-membership groups (e.g.
  a 15,000-member urban group would otherwise target 15,000, deep into the demonstrated decline zone).
  Derived directly: Demand §6 shows the dense tercile's decline is unambiguous by audience ~8,600+ and
  severe by ~13,000; 4,000 sits comfortably below both, and not coincidentally is the **existing coded
  default** (`RIPPLE_EXTENT_TARGET_USERS=4000`) — this design keeps that value rather than silently
  changing it, since the evidence base independently justifies it as "past peak, short of decline" (Demand
  bottom-line quote). If a future re-fit (§5) finds the decline threshold has moved, `N*_max` moves with
  it — it is not hand-picked twice, only once, and thereafter tracked.

- **`T_min` = 10 minutes.** Floor on drive-time regardless of how fast N\* is reached. Derived from the
  external-anchors evidence: DfT NTS0403f national average one-way shopping-trip duration is 17 minutes
  (flat across urban/rural settlement bands — Anchors report), and retail "primary trade area" convention
  is 5-10 minutes generating 50-80% of footfall. 10 minutes is the lower edge of that convention band —
  below it, a post has barely left its immediate postcode and the "give the local area first look"
  principle of rippling is defeated even if N\* is nominally satisfied by a single dense tick-1 burst.
  This also matches the existing MVP plan's stated floor (Mechanics report), so it is an adoption, not a
  change.

- **`T_max` = 30 minutes.** Ceiling, **kept at the current live value**, not raised to the previously
  floated 45. Two reasons: (1) the external anchor data suggests even 30 is already ~1.75x the national
  17-minute shopping-trip average, so raising it further has no supporting evidence and every complaint
  in Discourse #9808 is about reach being *too large*, not too small; (2) the audience-curves dark-compute
  (§2, Audience-curves report) shows T_max is already the *only* binding constraint for the 35%+ of groups
  that never reach even N\*=2,000 — raising T_max would only ever expand rural reach further (already
  intended and uncontroversial), while N\* is what reins in the complained-about dense-area cases. Under
  this design T_max stops being the *primary* stop condition for dense/urban areas (N\* binds first there)
  and remains the sole practical stop condition for the rural majority — exactly matching the "rural
  posts riding to the ceiling is intended, not a bug" decision already made (Mechanics §6).

### 1.4 A companion fix this design surfaces (not this design's primary deliverable, but a direct implication)

`reply_saturation_stop=5` has never fired in 10,742 genuinely-rippled posts (Demand §1). Under audience
normalization, a stop that literally cannot trigger is not a governor. Since N\* now does the
area-appropriate audience-limiting, `reply_saturation_stop` should either move to a genuinely-binding
value (Demand §1 fine table: P(≥3) is 4-8% across deciles vs P(≥5) at ~1%, so **3** would bind on a
non-trivial share of posts and is defensible as "the 5-target was probably meant as an early-success
signal, and 3 replies is where deciles start showing daylight") or be left as a rare early-exit
safety-valve for exceptional posts, not the primary demand-based stop (N\* is). This is flagged, not
specified in detail, because it is adjacent rather than core to the audience-normalization design.

---

## 2. Worked examples

All figures from the Audience-curves dark-compute (real persisted `rippling_reach.schedule` ticks,
2026-06-22 to 2026-07-02) and Demand report, unless marked "assumption" (evidence missing).

| Group | Active members (proxy: audience-curves exemplar `n`/density tercile inputs) | N\* = clip(1.0×active, 1000, 4000) | Today (T_max=30 fixed): audience @ 30min | Today: drive-time to reach it | Under D1: drive-time to reach N\* | Under D1: final audience |
|---|---|---|---|---|---|---|
| **NW-London Offers** (no exact DB match — proxied by inner-London borough cluster, e.g. Ealing/Brent) | ~12,000-14,000 active (Audience-curves §3c top-15 table, e.g. Ealing p50 audience 12,811 @ 30min implies comparably large active pool) | **4,000** (capped) | ~12,500-14,800 | ~30.0 min (rides ceiling like all top-15 London groups) | ~17-19 min (interpolated between the N\*=2000 row's London p50 of 13.0min and N\*=4000's ~17-23min band — Audience-curves §2/§3d) | ~4,000 (vs today's ~12,500+) — **this is the fix the Discourse complaint (#248/#250) is asking for: same principle-of-locality, ~3x smaller audience, ~13 fewer minutes of drive-time** |
| **Tower Hamlets** | ~3,469 (top of the "dense" tercile band, Demand §6) — consistent with it already self-gating via reply-stop | **N\* = 3,469×1.0 = 3,469**, within [1000,4000], no clipping | 13,336 @ p50 17.8min (already self-gated by reply-stop today, ahead of the 30min ceiling — Audience-curves §3) | 17.8 min | ~13-15 min (between the N\*=2000 row's 11.3min and N\*=4000 row's 17.8min, interpolated toward N\*≈3,469 — Audience-curves §3) | ~3,469 (vs today's already-reduced 13,336 — a further ~4-5min, ~4x audience tightening) |
| **Oxford** | ~3,829 audience @ ceiling reported (Audience-curves §3); active-member proxy assumed comparable, ~3,000-3,800 (ASSUMPTION: no direct active-member count pulled for Oxford in evidence; audience-curves' own `total_freeglers` at full ceiling is used as an upper-bound proxy since Oxford rarely rides all the way to 30min-only-if-dense, per §3 p10=28.4) | **N\* ≈ 3,600** (within band) | 3,829 @ p50 30.0 min (p10=28.4 — Oxford is near-ceiling-bound already) | 30.0 min | ~28-29 min (barely changed — N\* and the current audience are already close, consistent with Oxford being "barely moves" under N\*=4000 in Audience-curves §3) | ~3,600 (vs today's 3,829 — a small, appropriate tightening; Oxford was never the complaint case) |
| **Swindon** | ~672 audience @ ceiling (Audience-curves §3) — **never reaches even N\*=1,000** (Audience-curves §2: N\*=1000 has 18.9% never-reach nationally, and Swindon/Hull/Ribble Valley are named as 100% never-reach even at N\*=2000) | **N\* = clip(k×active, 1000, 4000) → floor of 1,000 applies since active members here are well under 1,000×k** (ASSUMPTION: Swindon's active-member count itself, not just audience-at-ceiling, is likely in the 600-1,000 range — not directly measured; audience-at-ceiling of 672 is a lower bound since it's already rate-limited by density not by N\*) | 672 @ 30.0 min | 30.0 min | **30.0 min — unchanged.** Swindon never reaches N\*, so `T_max` alone governs, exactly as today. | 672 (unchanged) — **this is the design working as intended: Swindon was never the problem, and D1 leaves it untouched** |
| **Hull** | ~659 audience @ ceiling (Audience-curves §3) — 100% never-reach at N\*=2,000 or 4,000 | Floor 1,000 applies (active members plausibly near or under this) | 659 @ 30.0 min | 30.0 min | 30.0 min — unchanged | 659 (unchanged) — same as Swindon, ceiling-bound, D1-neutral |
| **Ribble Valley** | ~1,446 audience @ ceiling (Audience-curves §3); 80% never-reach at N\*=2,000 | Floor likely near 1,000-1,446 (ASSUMPTION: active-member count not directly measured, audience-at-ceiling used as a proxy) | 1,446 @ 30.0 min | 30.0 min | ~27-29 min for the ~20% of posts that do cross N\*≈1,000-1,400 early; ~30 min (unchanged) for the 80% majority that don't | ~1,000-1,446 (essentially unchanged — a very small tightening for a minority of posts only) |

**Pattern the worked examples demonstrate**: D1 is *inert* for Hull, Swindon, and (mostly) Ribble
Valley — exactly the areas nobody is complaining about — and *materially binding* for Tower Hamlets and
the wider inner-London cluster that generated every quantified Discourse complaint (#248 Swindon-vs-
Islington, #250 Chilterns-to-East-London). Oxford sits in between, appropriately barely moved. This
directly falsifies the "a people-count cap that decreases with density is wrong" concern flagged in
Mechanics §6 — D1's cap does not decrease with density in absolute terms; because it's proportional to
each group's own active-member base (not a fixed number, and not inversely related to density), dense
groups still get large N\* values (just capped below their full uncapped potential), while sparse groups
get the same protective floor as today.

---

## 3. Failure modes and mitigations

**Coastal / estuary geometry.** N\* is defined on active-member *count*, not distance or area, so a
half-circle catchment (e.g. a coastal town) simply takes longer (more drive-time) to accumulate the same
N\* than a full-circle inland town of equal density — the mechanism self-corrects for shape automatically,
unlike a fixed-radius design. Mitigation is structural, not a patch: **this is a genuine strength of the
audience-normalization philosophy over a fixed-time or fixed-distance design.** Residual risk: extremely
elongated coastal corridors could still hit `T_max` before N\* if the *linear* population density along
the coast is too low even though the town itself is dense — acceptable, because this reduces to the
"rural riding the ceiling is intended" case, not a new failure mode.

**Group-boundary gerrymandering.** Because N\* is derived from `active_members(home_group)`, a group
could in principle inflate its own N\* by encouraging low-engagement sign-ups that count toward
`active_members` (defined via `lastaccess`, so this requires genuine 90-day activity, not just
membership — a meaningfully higher bar than raw membercount). Mitigation: (a) use `active_members`
(lastaccess-gated), never raw membercount, exactly as this design already specifies — raw membercount
control (which a bad-faith admin *can* trivially inflate by mass-inviting) is explicitly rejected as the
input; (b) cap via `N*_max=4,000` bounds the maximum possible gain from gaming even a huge group; (c) the
periodic re-fit (§5) uses *reply outcomes*, not `active_members` itself, as the fitness signal — if a
group's inflated `active_members` doesn't produce more replies, the re-fit's k multiplier trends down
for that density band over time, self-correcting. This is a slower mitigation than a hard rule, flagged
honestly as a residual risk in §6.

**Seasonal actives (student towns, holiday areas).** `active_members` with a 90-day lastaccess window
already partially self-corrects (a university town's active count naturally drops over summer, N\*
drops with it, matching genuinely lower local reply capacity). Risk: a step change (start of term) could
cause an abrupt N\* jump mid-quarter, before the next scheduled re-fit of `k`. Mitigation: `active_members`
is recomputed on every post (it's a live query, not a cached quarterly figure) — only the multiplier `k`
and the min/max clips are on a slower refresh cycle (§5); the *count* itself tracks seasonality in
real time already, by construction. No further mitigation needed beyond documenting this behaviour is
intentional.

**TrashNothing / cross-posted members.** If a meaningful fraction of "active members" are actually
TrashNothing-side users whose reply behaviour differs structurally (different app, different reply
conventions) this design's `active_members` input and its reply-rate calibration (`r_reply,active`,
Mechanics §6) could be systematically biased for groups with high TrashNothing crossover. **Not resolved
by the current evidence base** — no report in this batch measured TrashNothing member share or
reply-rate differential. Flagged as an explicit gap: before rollout, run one query segmenting
`active_members` and reply rate by primary access channel; if TrashNothing-heavy groups show a
materially different reply-rate-per-audience curve, they need their own `k`, not a shared one.

**Data drift (network growth, rippling's own second-order effects).** Two distinct drift risks: (1)
overall membership grows over time, shifting the density terciles the peak was calibrated on; (2)
rippling itself changes membership patterns (rippled-in users sometimes convert to full members — the
"rejoin-suppression" and "Rippled" marker mechanisms already exist per prior work), so the very
`active_members` input is not static under this policy, it's partly a *consequence* of the policy.
Mitigation: this is precisely why §5 specifies a **periodic re-fit**, not a one-time calibration — k must
be re-derived from fresh reply-decile data on a fixed cadence, not set once and left. The circularity
(reach changes membership changes N\* changes reach) is real but bounded: `active_members` changes slowly
(months) relative to the re-fit cadence (quarterly, §5), so each re-fit sees a settled input, not a
same-cycle feedback loop.

**Cold start (new/tiny groups).** A brand-new group has an `active_members` count near zero — under the
raw formula N\* would floor-clip to 1,000 immediately (§1.3's `N*_min`), which is exactly the desired
behaviour: a new group gets the same protective floor as any small/sparse group, not a broken near-zero
target. No special-casing required; the floor *is* the cold-start handling. Verify at rollout: confirm
the floor applies before a group has accumulated 90 days of `lastaccess` history (i.e. a group younger
than 90 days should not have `active_members` silently read as zero due to a lastaccess-window artifact —
this needs a one-line "if group age < 90 days, use raw membercount as the active_members proxy" guard,
noted in the implementation sketch, §5).

**Scotland / Northern Ireland (thin data generally).** Audience-curves §3d shows Scotland at 61%
never-reach-N\*2000 and NI at 100% (n=51, small sample) — i.e. under this design, NI and most of Scotland
are **entirely T_max-bound, D1-inert**, same as Hull/Swindon. This is not a failure mode requiring
mitigation — it's the correct outcome (nobody there is complaining, and the design should not touch
areas it has no evidence about). The only actual risk is the *re-fit* (§5): with n=51 for NI, any
density-banded k re-fit computed from NI's own data would be dangerously noisy. Mitigation: the re-fit
must be done on **density bands, not individual regions/nations** — NI's few dense pockets (Belfast)
should be re-fit as part of a shared "small dense" band with similarly-sized dense clusters elsewhere in
the UK, not fit on NI's n=51 alone. This is specified explicitly in §5.

---

## 4. Mod-facing explanation

The exact sentence(s) a moderator would see, in-product (e.g. on a "why did this reach so far" or
group-settings explainer page), written to survive a hostile audience per the Discourse evidence
(direct rebuttal of the "equal time ≠ equal fairness" complaint, and pre-empting "why can't I set my
own number"):

> **How far your group's posts ripple**
>
> Every Offer expands outward until it has reached roughly as many active Freeglers as your own group
> normally has active — capped between 1,000 and 4,000 people, whichever this group's own membership
> works out to. That means a small rural group and a large London borough both get a "fair local
> audience" for their area, rather than both being forced to travel the same number of minutes down the
> road. In a dense area, that audience is reached in a few minutes; in a sparse area, it can take up to
> 30 minutes' drive to find that many people at all, and the post keeps expanding that far because there
> simply aren't more people any closer.
>
> This number isn't set by us, and it isn't a dial groups can turn — it's recalculated automatically from
> how many of your own members are actually replying to posts at different reach sizes, checked afresh
> every three months. If it's consistently missing genuine local interest, or reaching a lot further than
> it needs to, that shows up in the data and the number moves on its own next review — tell us on
> Discourse and we'll check the numbers behind your group specifically.

This directly answers the two structurally distinct hostile-audience objections seen in the evidence:
(1) "why does my post go as far as someone in a totally different density area" (#248/#250) — answered
by "equal audience, not equal time", the design's core claim; (2) "why can't I just set this myself" (the
rejected-slider precedent, and the in-thread manual-dial proposals in #289/#304/#373/#397) — answered by
making clear the number is derived from evidence and re-measured, not arbitrary, and specifically not a
per-group toggle.

---

## 5. Rollout: dark-compute → scoped → network-wide

**Stage 0 — Dark-compute validation (no live change).** This design's core claim (N\*≈active_members
peaks reply probability) is already directly testable against the *existing* Demand report data without
touching config: re-run the Demand §6 density-tercile analysis with `k` swept over {0.5, 0.75, 1.0, 1.25,
1.5} and confirm k=1.0 remains at or near the empirical peak, not just plausible. This is pure SQL against
already-collected data — do this before flipping `RIPPLE_EXTENT_ENABLED` anywhere. Deliverable: a
one-page confirmation (or correction) of the k=1.0 fit before any code path changes behaviour.

**Stage 1 — Scoped canary (single density band, small blast radius).** Enable
`RIPPLE_EXTENT_ENABLED=true` with the derived N\* formula for a small number of **dense-tercile** test
groups only (the population where D1 is expected to bind — Tower Hamlets-like groups; per §2, ~5-10
groups covering the top-15 London-cluster + a couple of mid-density comparators like Oxford, to see both
a strongly-binding and a barely-binding case). Rural/sparse groups are automatically unaffected by
definition (they're T_max-bound regardless), so there is no need to separately canary them — they are the
built-in control group.

**What to measure during canary** (all metrics already instrumented per Mechanics report, zero new
pipeline needed):
- Reply rate (P(≥1), mean repliers) for canary posts vs. matched historical baseline (same groups,
  pre-canary window) — the primary success metric, must not regress below baseline.
- Time-to-first-reply (Demand §3 metric) — must not regress materially; D1 intentionally trades some
  audience (and the speed that audience buys) for less mod-load/complaint, so a *small* speed regression
  in the canary group is expected and acceptable, a *large* one is not (define threshold: no more than
  the deceleration already observed between deciles 8-10 in Demand §3, i.e. don't regress speed below
  where audience 4,000-6,000 already sits today).
- Notified-count and notifications-per-replier (Demand §5 metric) — expected to *improve* (fall) for
  canary groups, this is the mechanism's direct cost saving.
- Taker-distance / collection-coverage (Behaviour report methodology) — must not regress; this is the
  "did we accidentally miss real collectors" check the evidence base explicitly flags as the necessary
  validation (Mechanics §6: "primary test... must be the taker-distance/collection-catch validation, not
  just a volume-reduction number").
- Discourse/mod-complaint volume from canary-group moderators specifically — qualitative but the actual
  target outcome.

**Duration**: minimum 3 weeks (matches the existing reach-experiment's own stated timescale for
dose-response to resolve — Demand/Mechanics reference the ~3-week figure directly) to get past the
7-day hazard schedule's full tail and accumulate enough Taken-outcome lag (Demand §2 notes real
Taken-rate signal needs ~14+ days from post arrival).

**Stage 2 — Network-wide rollout, banded.** Roll `RIPPLE_EXTENT_ENABLED=true` out region/density-band by
band, in descending order of expected impact (matches Audience-curves §3d's region rollup — London first,
since it's both the most-affected and the best-measured; then South East/Yorkshire &
Humber/East/mid-density bands; sparse/rural bands last, since they are D1-inert and rollout order there
is a formality, not a risk). At each band, re-check the same canary metrics before proceeding to the next.

**Kill criteria** (any one triggers an immediate revert to `RIPPLE_EXTENT_ENABLED=false`, i.e. back to
pure `T_max`, for the affected band):
- Reply rate (P(≥1)) drops by more than 15% relative to the pre-D1 baseline for that band, sustained
  over >1 week (not a single noisy day).
- Collection-coverage (taker-distance capture rate at the group's *previous* effective radius) drops by
  more than 5 percentage points — i.e. D1 is provably causing real missed collectors, not just
  hypothetically.
- Any single canary/band group's moderator escalates a reach-related complaint of the same severity
  class as the resignation-triggering complaints already seen in Discourse #9808 (a qualitative but
  real, board-visible trigger given two moderators already resigned over rippling and two more nearly
  did — Discourse report).
- The self-tuning re-fit (below) produces a `k` outside a sane band (e.g. <0.3 or >3.0) — this indicates
  the input data or method has broken, not that the true optimum moved that far, and should halt
  auto-application pending manual review, not be applied blindly.

**Self-maintenance — periodic re-fit.** Every quarter, re-run the Demand §6 style analysis
(density-tercile × audience-quintile reply-rate table) on the trailing 90 days of live data, and:
1. Re-derive `k` per density band (not globally — see the Scotland/NI mitigation in §3: re-fit on
   density bands with adequate n, pooling small/thin regions into a shared band rather than fitting
   nation-by-nation).
2. Re-derive `N*_min`/`N*_max` only if the fine-bucket reply-rate table (Demand §1 style) shows the
   flattening/decline points have moved by more than a defined tolerance (e.g. 20%) from the values
   used to set today's 1,000/4,000 — small quarter-to-quarter noise should not move the clips.
3. Write the new values to `rippling_params` (the existing, currently-unwired scaffold table — Mechanics
   report notes this exists but nothing reads it back into the pipeline; this design is the first
   concrete consumer of it) and have `ReachService.php` read `k`/`N*_min`/`N*_max` from there instead of
   the static config constant, with the static config value retained only as the fallback default.
4. This directly replaces the currently-stubbed `RippleTuneService::categoryVolumeDeltas()` — instead of
   an unconditional empty array, it should compute exactly the density-tercile reply-decile table this
   design's derivation already specifies, making the "self-tuning loop" scaffold finally do real work,
   using a method this design has already validated rather than inventing a new one at implementation
   time.

**Relationship to the existing extent-governor MVP**: **adopt and complete it.** The MVP's mechanism
(audience-budget stop, already coded in `ripple.go`/`ExpandService.php`) is not replaced by this design —
it is exactly what this design turns on. The only change is supplying the previously-missing derivation
for `target_users` (this design's §1.2-1.3) in place of the flat, uncalibrated placeholder 4,000, and
wiring the dark self-tuning scaffold (`RippleTuneService`/`rippling_params`) to actually maintain it.

**Relationship to the planned reach experiment**: **complementary, sequenced after where possible, in
parallel where not.** The reach experiment's stated purpose (Mechanics/Demand: "benefit of extra reach is
structurally unobservable from history — everyone extra reach would add is currently unexposed") answers
a different question than this design: the experiment measures whether reach *beyond* today's ceiling
would help (an upper-bound/expansion question); this design answers whether today's reach is *already
enough, unevenly distributed* (a reallocation/efficiency question) — and the demand-flattening evidence
(Demand §1, §6) suggests the answer to "would more help" is *already visible in-sample* as "no, mostly,
and in dense areas it actively hurts" without needing the randomized experiment to find that out. Given
that, this design's canary (Stage 1) can and should run concurrently with the reach experiment's
user-level randomization, provided the two are **statistically disentangled** (e.g. the reach experiment
excludes canary-band groups from its treatment arms, or the canary is deferred to start after the
experiment's ~3-week read-out if analytical interference is a concern). Recommend a brief coordination
check before Stage 1 begins to confirm no overlap in the specific groups/posts each touches.

---

## 6. Honest cons — where this philosophy is weakest

**1. The core derivation (§1.2a, k=1.0) rests on exactly two data points.** The within-tercile peak was
observed cleanly in the medium and dense terciles only (two ticks of evidence), not three — the sparse
tercile never showed a decline in the observed range, so `k` for sparse areas is *assumed* to generalize
from the same ratio rather than independently confirmed. If sparse-area demand behaves qualitatively
differently (e.g. never truly saturates, unlike medium/dense), `k=1.0` there is unverified, though it's
also functionally inert for those groups (§3's Hull/Swindon/Ribble-Valley worked examples) since they're
floor/ceiling-bound regardless — so the risk is contained, but the derivation confidence is genuinely
asymmetric across density bands, and this should be stated plainly rather than hidden behind a single
clean-looking formula.

**2. Audience is a worse-measured, one-step-removed proxy for what mods and the product actually care
about (mod load, member irritation, "did the item get collected"), not the thing itself.** The evidence
base is explicit that mod-load is governed by group-*count*, not audience-size, under the current
"ripple into every intersecting group" engine (Mechanics §6: "capping members alone does not shrink group
count... chunking is required for that"). This means **D1, on its own, does not fix the mod-facing
complaint's most literal mechanism** (moderators are annoyed partly because posts appear in many
groups they oversee, not only because of raw audience size) — it fixes the audience-fairness dimension of
the complaint cleanly, but leaves the group-chunking dimension (T2.1b in the MVP plan, "not started" per
Mechanics §7 point 7) as a separate, unimplemented dependency this design does not itself deliver. Any
rollout communication should not overclaim "this fixes mod overload" — it fixes "equal-time is unfair",
which is the specific, quantified, most-cited complaint (#248/#250), but is a narrower claim than "fixes
rippling complaints" broadly.

**3. Reply-rate-as-optimization-target may itself be gameable/mis-specified over the long run.** By
design, this method treats "reply probability peaks near 1x local active members" as ground truth to
optimize toward — but replies are influenced by many things this design doesn't model (post quality,
photo presence, time of day, category desirability), and the periodic re-fit (§5) implicitly assumes any
drift in the *reply-vs-audience* relationship is due to the audience parameter being wrong, when it could
equally be due to a shift in post mix, seasonality in item categories, or the deliverability feedback loop
explicitly flagged as unresolved in Mechanics §7 point 10 ("high volume → spam-foldering → measured r
looks artificially low → bigger N_t0 computed → more volume", a genuine self-reinforcing risk this design
inherits from the MVP plan rather than solving).

**4. The "equal audience" framing has its own fairness critique, symmetric to the one it's replacing.**
Just as equal-time was criticized as producing wildly unequal audiences, equal-audience (capped at 4,000)
means a genuinely huge, dense group's posts get *proportionally* less exposure relative to their own
membership than a small group's posts do (a 15,000-active-member group is capped at 4,000 = ~27% of its
own base; a 1,000-active-member group gets the full 1,000 = 100% of its base). This is the entire point
for the density complaint, but it is a real, different-axis inequality this design deliberately trades
in — worth naming explicitly rather than presenting N\* normalization as a free lunch.

**5. Depends on data that is itself only 10 days old (Audience-curves report §0) and pre-dates full-volume
rippling maturity (Demand report notes home-group reply share already drifted from ~98% to 82.5% in just
9 days).** A derivation built on 10 days of post-launch data, in a system whose own behavior is still
visibly settling, carries meaningfully more re-fit risk in its first year than the "quarterly re-fit"
cadence in §5 assumes — the first 2-3 quarterly re-fits should be treated as provisional/high-variance,
and the design should say so rather than presenting quarter-1's `k` as equally trustworthy as quarter-5's.

---

## Evidence sources cited

- `/tmp/claude-1000/-home-edward-FreegleDockerWSL/32f74873-7429-488e-b2f3-7ded306eaba4/scratchpad/reach-design/1-mechanics.md`
- `/tmp/claude-1000/-home-edward-FreegleDockerWSL/32f74873-7429-488e-b2f3-7ded306eaba4/scratchpad/reach-design/2-discourse.md`
- `/tmp/claude-1000/-home-edward-FreegleDockerWSL/32f74873-7429-488e-b2f3-7ded306eaba4/scratchpad/reach-design/3-behaviour.md`
- `/tmp/claude-1000/-home-edward-FreegleDockerWSL/32f74873-7429-488e-b2f3-7ded306eaba4/scratchpad/reach-design/4-audience-curves.md`
- `/tmp/claude-1000/-home-edward-FreegleDockerWSL/32f74873-7429-488e-b2f3-7ded306eaba4/scratchpad/reach-design/5-demand.md`
- `/tmp/claude-1000/-home-edward-FreegleDockerWSL/32f74873-7429-488e-b2f3-7ded306eaba4/scratchpad/reach-design/6-external-anchors.md`
