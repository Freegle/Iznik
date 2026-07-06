# Design D4 — The Parameter Should Not Exist As A Constant

**Philosophy: reach is the OUTPUT of a per-post feedback/stopping rule plus a
continuously-estimated model, never a hand-set number.** Humans set only meta-parameters
(cost/benefit weights, safety floor/ceiling). Everything else — including "N\*" itself — is
estimated from data and re-estimated forever.

Date: 2026-07-02. Design only, per instructions. Grounded in the six evidence reports in this
directory (`1-mechanics.md` … `6-external-anchors.md`); every constant below cites its source.

---

## 0. The one-sentence pitch

Stop asking "what should N\* be?" — that is exactly the per-group-slider question in a
statistics costume, just answered by an algorithm instead of a mod. Ask instead: **"for this
post, right now, is the next marginal notification worth more than it costs?"** — a question
answerable per-post, per-tick, from data the system already has (evidence: `rippling_reach.schedule`
already stores `cumulative_users` per tick, zero schema change — `1-mechanics.md` §3) and data
one extra experiment gives it (the already-scoped user-level ADD reach experiment). N\* is not
eliminated; it becomes a **derived quantity**, recomputed continuously per stratum, not a value
anyone — human or algorithm — picks once and freezes.

---

## 1. The rule, precisely

### 1.1 Core decision at every tick

At routing-schedule tick `k` for post `p`, before committing to expand from `cumulative_users[k-1]`
to `cumulative_users[k]`, evaluate:

```
marginal_users(k)   = cumulative_users[k] - cumulative_users[k-1]
marginal_benefit(k) = marginal_users(k) × r_hat(stratum, k) × V
marginal_cost(k)    = marginal_users(k) × C
CONTINUE tick k  iff  marginal_benefit(k) / marginal_cost(k) >= θ
                       AND drive_min[k] <= T_ceiling
                       AND distinct_repliers < reply_stop
                       AND status not terminal (claimed/withdrawn)
```

Where:

- **`r_hat(stratum, k)`** — the estimated probability that one additional notified member
  produces one unit of benefit (reply, or reply-weighted-by-speed-value — see §1.4), for the
  post's **stratum** (density band × tick-position — not "the group", see §1.3) and **tick
  position** (early ticks buy speed, evidence: `5-demand.md` §3, median time-to-first-reply
  drops 513min→141-174min decile1→decile10 — the speed benefit is front-loaded and decays with
  tick, exactly mirroring the feed-forward/closed-loop split in §2).
- **`V`** — value of one unit of benefit, in the same currency as `C`. A **meta-parameter**
  (human-set once, see §1.5), not re-derived per post.
- **`C`** — cost of one notification (mod-load-adjacent nuisance cost + literal send cost +
  externality of moderator trust erosion). A **meta-parameter** (human-set once, see §1.5).
- **`θ`** — the stop threshold on the benefit/cost ratio. Default **θ=1** (stop when marginal
  benefit no longer exceeds marginal cost) unless a safety margin is deliberately chosen (see
  §7, cons: θ itself needs to be *fitted*, not simply asserted at 1 — flagged honestly there).
- **`T_ceiling`** — the drive-time safety ceiling. Fixed at **30 min**, i.e. the currently-live
  value (evidence: `1-mechanics.md` — confirmed no 45 exists in code; `4-audience-curves.md`
  confirms max_drive_min caps at exactly 30.0 across 24,213 real schedules). This is a
  **meta-parameter (floor/ceiling), not derived** — it exists purely as a backstop so a
  mis-estimated `r_hat` cannot run away geographically. Not touched by this design at launch;
  raising or lowering it is a separate, later decision once the control loop has run long
  enough to show whether it ever actually binds (§5).
- **`reply_stop`** — retained as-is at **5** (Discourse community consensus, `1-mechanics.md`
  #10), but demoted from "the governor" to "an absolute backstop" (see §1.6 — it currently
  never fires, `5-demand.md`: P(≥5 replies) never exceeds 1.4% at any audience size, 0.83% of
  posts overall reach it — so it is not doing governing work today; the marginal-benefit rule
  is what actually governs, this stays only as a circuit breaker for the rare post that goes
  viral).

**This IS the extent-governor MVP's "expand until N\*" idea (`1-mechanics.md` #14/15,
`effectiveScheduleTotal`), reframed.** N\* is not gone — it is what falls out of solving
`marginal_benefit(N*) = marginal_cost(N*)` for the implied audience size. The MVP's mistake
(per this design) is not the mechanism, it's treating N\* as a single number to be "decided"
rather than a root of an equation whose inputs are estimated continuously. **Relationship to
the MVP: ADOPT the mechanism (Stage-A budget cap, already coded end-to-end per `1-mechanics.md`
§5.2), REPLACE the input** (a fixed `target_users=4000` placeholder becomes
`solve_for_N(r_hat, V, C, θ)`, recomputed, not typed into config).

### 1.2 How `r_hat` is estimated — the permanent calibration engine

This is where Philosophy 4's actual content lives: **`r_hat` is never fitted once and frozen.**
It is maintained by an always-on small holdout, structurally identical to the already-scoped
user-level ADD reach experiment (per task brief: "benefit of extra reach is structurally
unobservable from history — everyone extra reach would add is currently unexposed").

- **Holdout design**: for every post, a small, randomly-assigned slice of the *marginal*
  audience at each tick (the members who would be newly notified at tick k) is **held out**
  (not notified this tick) with probability `p_holdout` (start **5%**, see §1.2.1 for why).
  Their later behaviour (do they ever reply/collect via organic browse, i.e. would they have
  converted anyway without the notification) is compared against the treated group's
  conversion rate. This is the ADD (average dose-response derivative) design already specced —
  this rule does not invent a new experiment, it makes the existing planned experiment
  **permanent infrastructure** instead of a one-off 3-week study.
- **Estimator**: for stratum `s` (see §1.3) and tick-position `k`,
  `r_hat(s,k) = (conversion_rate_treated(s,k) - conversion_rate_holdout(s,k))`, i.e. the
  causal lift attributable to notification, not raw conversion rate (raw rate conflates
  "would have replied anyway via browse" with "replied because notified" — the holdout isolates
  the causal increment, which is the number that actually belongs in a marginal-benefit
  calculation).
- **Shrinkage / partial pooling**: `r_hat(s,k)` is an empirical-Bayes shrinkage estimate toward
  a network-wide prior (see §1.2.2), not a raw per-stratum sample mean — this is what makes
  cold-start and sparse strata behave sanely (§3, cold start).
- **Update cadence**: nightly batch re-estimation (matches existing `RippleTuneService`
  scaffold's intended cadence, `1-mechanics.md` #24 — this design **reactivates and completes**
  that scaffold rather than building a new one). `categoryVolumeDeltas()` today returns `[]`
  unconditionally (stub) — this design specifies what it should actually compute (§8,
  implementation sketch).

#### 1.2.1 Why 5% holdout, not smaller/larger — an explicit estimation procedure, not a guess

Sizing rule: the holdout fraction must be large enough that `r_hat(s,k)` has an acceptable
standard error within one week for the **median-volume stratum**, not the largest. Using the
observed baseline conversion rate (`5-demand.md`: P(≥1 reply) ≈ 22-39% across deciles, use
30% as central) and the median post volume for a mid-density stratum (Yorkshire & Humber,
n=1,456 posts over the 10-day measurement window per `4-audience-curves.md` §3d ≈ 145/day),
a 5% holdout yields ~7 held-out posts/day/stratum with a marginal-audience of order hundreds
each — sufficient for a two-proportion z-test to detect a 3-5 percentage-point lift at 80%
power within roughly a week of pooled data. This is a **procedure** (re-run the power
calculation against real per-stratum volume once strata are defined, §1.3), not an arbitrary
5% — the number is a first cut to be checked against live volume in the dark-compute phase
(§5) before any holdout traffic is real.

#### 1.2.2 The network-wide prior (feed-forward) and its role in the blind-burst problem

This is the design's most load-bearing mechanical fact, and it is why a pure closed loop
cannot work: **~70% of a post's eventual audience is committed by tick 1, roughly 1 hour after
posting** (evidence: `1-mechanics.md` #12, "step-70" hazard curve; tick 1 fires at t=1h not
literally t=0). At that moment, **zero holdout signal exists for this specific post** — there
has been no time for anyone to reply, let alone for a treated-vs-holdout gap to be measurable.
The tick-1 decision is necessarily **feed-forward**: it must use the *prior* `r_hat`
(yesterday's shrinkage estimate for this stratum/tick-position), not a within-post live signal.

- **The prior is sized, not asserted**: it is the shrinkage-estimated `r_hat(s, tick=1)` from
  the rolling 14-day window of holdout data for stratum `s`, using an empirical-Bayes beta-
  binomial pool (stratum sample blended with the network-wide tick-1 rate in proportion to
  stratum sample size — standard partial pooling, weight on the stratum mean =
  `n_s / (n_s + k_pool)` where `k_pool` is fitted from the observed between-stratum variance,
  not hand-picked).
- **The prior updates nightly** on the trailing 14-day holdout window (14 days chosen as the
  shortest window that: (a) spans day-of-week effects — offers arrive at different rates
  weekday vs weekend — and (b) is short enough that a real shift in stratum behaviour (e.g. a
  group gaining members, a season change) is reflected within two weeks rather than being
  diluted by months of stale data. This is a meta-parameter set once at design time, revisited
  if the loop is observed to be too slow/fast to react in the rollout phase, §5).
- **What this means concretely**: tick 1's burst size is governed by *yesterday's* estimate of
  "how much good does a tick-1 notification do in this stratum", not by anything this specific
  post's own repliers have done yet. Only ticks 2-9 (the trailing 30%, spread over hours 3
  through 168 — `1-mechanics.md` #12) can incorporate this post's *own* early signal (its own
  reply count so far) as an additional, post-specific adjustment layered on top of the prior
  (§1.4). This is explicitly **not** a design flaw to be engineered away — it is a structural
  fact about how fast human replies arrive relative to how fast tick 1 fires, and the design
  must be honest that governance of the dominant burst is necessarily a (frequently-updated)
  feed-forward prior, not real-time control. (This matches the existing design docs' own
  conclusion, `1-mechanics.md` #12 "givens" — this design does not relitigate it, it specifies
  exactly how the prior is estimated and refreshed, which the prior designs left open.)

### 1.3 Strata — the per-area axis, derived not assumed

The MVP plan assumed ONS Rural-Urban class as the stratification key (`target_by_ru`,
`1-mechanics.md` #21/#22) but that field is **not populated in the live path at all** — only
used offline by the simulator. Rather than wait for that plumbing, this design strata on a
quantity **already available for free from each post's own first tick**:

```
stratum(post) = decile of cumulative_users[tick=1] across all live posts in a trailing 14-day window
```

i.e. **the post's own early realized audience is the density proxy**, not a static group
attribute. This is explicitly recommended by `4-audience-curves.md`'s bottom line ("N\* scaled
by home-group's own audience-at-10-min... cheap to compute from each post's own early ticks
with zero extra instrumentation") and sidesteps two problems the RU-class approach has:

1. **RU-class is a static, group-level label; density is a post-level, seasonally-live
   quantity.** A group's actual reachable population changes (membership growth, seasonal
   actives, new housing) — a stratum recomputed from live tick-1 data tracks that automatically
   (self-maintenance, §4), whereas an ONS lookup from a 2011 census table does not.
2. **RU-class is not populated in the live pipeline** (confirmed absent, `1-mechanics.md` #22)
   — using it would require new plumbing this design's philosophy explicitly avoids leaning on
   (a static external table is itself a frozen assumption, in tension with "continuously
   estimated").

Ten deciles is itself a meta-parameter (chosen for enough within-stratum sample for the
holdout power calculation, §1.2.1, while keeping the density gradient reasonably fine — halving
to 5 or doubling to 20 changes statistical power per stratum but not the mechanism; revisit in
rollout, §5). A special always-separate top stratum is carved out for the **12 boroughs at
p10=602 → max=17,776 audience spread** (`4-audience-curves.md` §1 — the 15 highest-audience
groups, all inner/west London, `4-audience-curves.md` §3c) because that population is where the
Discourse complaints concentrate and deserves faster-updating, tighter confidence intervals —
practically this falls out automatically as "decile 10", no special-casing needed beyond making
sure decile 10 gets adequate holdout volume (it does: London = 3,652 of ~24,213 posts in the
10-day window, `4-audience-curves.md` §3d, ample volume).

### 1.4 What `V` (benefit) actually measures — reply-lift AND speed

`5-demand.md` §3 is the single most important empirical finding for this design: **eventual
reply COUNT is flat-to-declining with audience (P(≥1 reply) 22-39% across a 35x audience range,
no clean knee), but TIME-TO-FIRST-REPLY improves monotonically and substantially (513min →
141-174min, ~3x, decile 1 → decile 10).** A rule that only counts eventual replies as `V` would
correctly conclude "audience beyond ~1,000-2,000 members buys almost nothing" (matching
`5-demand.md`'s bottom line) and would starve the one benefit that DOES scale: speed.

`V` is therefore not a single scalar but a **two-term benefit function**, fit from the same
holdout data:

```
V(marginal_users) = w_reply × ΔP(reply)  +  w_speed × Δ(1/time_to_first_reply)
```

`w_reply` and `w_speed` are **meta-parameters** (relative worth of "gets a reply at all" vs
"gets it faster") — genuinely a product/values decision, not a data-derivable ratio (see §1.5,
these are the honest human-set knobs). The *shapes* `ΔP(reply)` and `Δ(1/ttfr)` are both
estimated from the holdout, per stratum/tick — this is what makes the design's claim "buys
speed not more eventual success" (matching `5-demand.md`'s own conclusion) something the
control loop can act on rather than something a human has to remember to account for.

### 1.5 The meta-parameters (the ONLY human-set values) — and how they get sane defaults

| Meta-parameter | Role | Suggested default | Basis |
|---|---|---|---|
| `θ` | stop threshold, ratio of benefit/cost | 1.0 (initially), see §7 for why this is the weakest link | Definitional — "stop when marginal cost exceeds marginal benefit" is the textbook rule; whether 1.0 is the right operating point given estimation noise is exactly the open question flagged in §7 |
| `V` decomposition weights `w_reply`, `w_speed` | relative value of eventual-reply-probability vs speed-to-reply | `w_reply=1, w_speed` calibrated so a 3x speed gain (the observed decile1→10 delta) is worth roughly the same as the reply-probability gain the same audience jump buys at low deciles (empirically, decile1→3 in `5-demand.md`: P(≥1) 31.5%→35.8%, +4.3pp, alongside 513→347min speed gain) — **an explicit starting ratio derived from matching the two effect sizes observed in the SAME data range where both still move together**, not asserted from nowhere | `5-demand.md` §1, §3 |
| `C` (notification cost) | cost per marginal notification | Set so `C` reflects the **overshoot-cost curve already measured**: `5-demand.md` §5 shows cost-per-replier triples from 36 (decile 1) to 112 (decile 10) — `C` should be picked such that the rule naturally stops around decile 2-3 (audience ≈1,000-1,500) for a typical stratum where reply-lift has flattened, matching `5-demand.md`'s own stated "somewhere in the ~1,000-2,000 member range captures essentially all of the achievable reply probability" | `5-demand.md` bottom line |
| `T_ceiling` | absolute drive-time safety cap | 30 min (unchanged from today) | `1-mechanics.md`, `4-audience-curves.md` — matches live system, no regression |
| `reply_stop` | absolute circuit-breaker on distinct repliers | 5 (unchanged) | Discourse #8415 precedent, `1-mechanics.md` #10 |
| `p_holdout` | fraction of marginal audience withheld per tick for calibration | 5% | §1.2.1 power calculation |
| `prior_window_days` | trailing window for nightly `r_hat` re-estimation | 14 days | §1.2.2 — day-of-week coverage vs staleness tradeoff |

**Calibrating `C` and the `w_reply`/`w_speed` ratio is itself done by a documented, repeatable
back-solve against the evidence tables above** (shown in the table), not by intuition — but they
remain the two places genuine human judgement enters (how much do we value speed vs eventual
success; how costly is a notification really, including the soft cost of moderator trust —
`2-discourse.md`'s finding that 2 mods resigned and 2 more nearly did is a real input into `C`
that no amount of reply-rate data can supply on its own). This is the honest boundary: **data
derives the shape, humans set how much the shape matters.**

### 1.6 Backstops, not governors

`reply_stop=5` and `T_ceiling=30min` remain in the rule but are demoted to **circuit
breakers**, not the primary mechanism (today they effectively ARE the mechanism — reply_stop
never fires per `5-demand.md`, so in practice today reach is governed by nothing except the
flat 30-min ceiling, which is exactly the complaint). Under this design, the marginal-
benefit/cost stop does the actual work; the backstops exist only to bound worst-case behaviour
if `r_hat` is badly wrong (stale prior, data pipeline break, adversarial gaming) — see §3 and
§6 (anti-runaway guards).

---

## 2. The control loop, precisely (feed-forward + closed-loop, explicit)

```
NIGHTLY (batch, all strata):
  1. Pull yesterday's holdout-vs-treated conversion + time-to-reply data, by (stratum, tick).
  2. Update r_hat(stratum, tick) via empirical-Bayes shrinkage against 14-day trailing window.
  3. Update V(stratum, tick) shape (ΔP(reply), Δ(1/ttfr)) same way.
  4. Recompute implied N*(stratum) = smallest audience at which
     marginal_benefit/marginal_cost crosses θ, for tick=1 (feed-forward prior) and
     for each subsequent tick (informs the closed-loop layer).
  5. Guard-rail checks (§6) before promoting new estimates to "live":
     - Max per-tick change in N*(stratum) capped (anti-oscillation, §6.1).
     - Minimum holdout sample size per stratum before trusting a new estimate (else keep
       prior night's value — cold-start / thin-stratum fallback, §3).
     - Explosion-detector: if network-wide notification volume for the day is >X% above
       trailing 7-day average, freeze all N* updates and alert (§6.2).
  6. Write updated (stratum, tick) -> N*, r_hat, V table. This IS `rippling_params`,
     repurposed (§8) — the table already exists; it just needs a real writer and a reader.

PER-POST, PER-TICK (real-time, ExpandService::advanceDue / initialiseNew):
  1. tick=1: use last night's N*(stratum, tick=1) as the target_users cap for THIS tick
     (feed-forward — no live signal exists yet for this specific post, §1.2.2).
  2. tick>=2: blend the feed-forward prior with this SPECIFIC post's own observed signal
     so far (its own reply count, its own time-to-first-reply if any) via a simple
     posterior update — a post that already has 2 replies at tick 2 gets a smaller
     marginal-benefit boost from further expansion (matches its own realized r far
     exceeding the stratum prior -> converges faster to a stop); a post with zero replies
     by tick 4 gets no penalty (absence of a reply this early is not yet informative,
     evidence: 5-demand.md decile1 median-time-to-reply is 513 minutes -- ~8.5h -- so "no
     reply by tick 2 (3h)" is not yet a signal of anything).
  3. Apply reply_stop / T_ceiling backstops (always checked, independent of the above).
  4. Route holdout: for the marginal_users computed for this tick, withhold p_holdout
     fraction from notification (still counted in polygon/browse-visible audience --
     NOT excluded from the post being findable, only from the PUSH notification -- this
     matters, see caveat below).
```

**Important scoping note on the holdout mechanism**: the holdout withholds the *push
notification*, not visibility. A held-out member can still find the post by browsing/searching
their group feed — this is what makes the ADD design measure the causal lift of *notification*
specifically (matching the already-scoped experiment's framing) without making held-out members
worse off than "a member who happens not to check their email that day" — a real, already-
existing state, not a new harm introduced by the experiment. This should be stated explicitly
in any GDPR/LIA review (still an open item per `1-mechanics.md` #29, not resolved by this
design).

### 2.1 Relationship to the existing extent-governor MVP and the reach experiment — explicit

- **Extent-governor MVP (`RIPPLE_EXTENT_ENABLED`, `target_users`, `effectiveScheduleTotal`)**:
  **ADOPTED AS THE MECHANISM, EXTENDED AS THE INPUT.** The Stage-A audience-budget cap plumbing
  (`1-mechanics.md` #14/15, coded end-to-end, currently dark) is exactly the enforcement point
  this design needs — `target_users` becomes a per-(stratum,tick) value read nightly from the
  updated `rippling_params` table (§8) instead of a single flat 4000 constant. No new
  enforcement code is needed; only the *source* of the number changes from "config constant" to
  "nightly-computed table lookup."
- **Reach experiment (user-level ADD design)**: **ADOPTED AS PERMANENT INFRASTRUCTURE, NOT A
  ONE-OFF STUDY.** The already-scoped ~3-week experiment is not run once to get an answer and
  then shut off — it becomes the always-on 5% holdout described in §1.2, running forever at low
  volume, because the dose-response `r_hat` this design depends on is a moving target (member
  churn, seasonal actives, group boundary changes — §4) that a one-off measurement cannot track.
  This is the central philosophical move of Design 4: an experiment that was scoped as a
  point-in-time measurement becomes the sensing organ of a permanent control loop.
- **Self-tuning-loop scaffold (`RippleTuneService`, `rippling_params`)**: **REACTIVATED, NOT
  REPLACED.** `1-mechanics.md` #24 found this scaffold already exists but is a stub
  (`categoryVolumeDeltas()` returns `[]`, `ripple:tune` unscheduled, no reader). This design is,
  concretely, "finish building what's already half-built" — §8 gives the implementation sketch.

---

## 3. Failure modes + mitigations

| Failure mode | Why it breaks a naive version | Mitigation in this design |
|---|---|---|
| **Coastal/estuary geometry** (e.g. Hull's catchment spanning a rural hinterland, `3-behaviour.md` density-proxy artefact) | A post near a coastline/estuary has a real drive-time isochrone that is asymmetric and low-density on one side; if strata were geographic (e.g. postcode-area based) this could misclassify a genuinely dense area as sparse because the polygon average includes empty sea/estuary | Stratifying on **the post's own realized tick-1 audience** (§1.3), not on a static geographic label, sidesteps this entirely — a coastal post's tick-1 audience already reflects its real (asymmetric) reachable population, no geometry-shape correction needed |
| **Group-boundary gerrymandering** (a mod redraws their group polygon to inflate/deflate density stratum) | If stratification used group polygon area (as the `3-behaviour.md` density proxy did, an artefact acknowledged in that report), a mod could game their stratum by redrawing boundaries | Stratifying on **post-level realized audience** (not group polygon) means redrawing a group boundary changes membership/routing inputs, not the stratum computation directly — gaming would require actually changing the reachable population, which has real-world consequences (fewer/more genuine members) rather than being free to manipulate. Residual risk: a mod could still influence membership counts through recruitment drives; this is treated as legitimate area-representation activity, not gaming, and is explicitly out of scope for this design to police |
| **Seasonal actives** (student towns emptying over summer, e.g. Oxford — `4-audience-curves.md` names Oxford explicitly; holiday-driven member activity swings) | A `target_users` constant calibrated once in term-time would be wrong in summer | The nightly re-estimation (§2) over a 14-day trailing window means `r_hat`/`N*` tracks seasonal member-activity swings automatically within about two weeks — this is a **structural** advantage of "recompute forever" over "set once," and is the single strongest argument for Philosophy 4 over a fixed-constant design |
| **TrashNothing members** (cross-posted members who may behave differently — inflated notification counts, different engagement patterns) | If TrashNothing-sourced members are counted in `cumulative_users` but behave differently (lower conversion), a stratum with many such members would get a systematically wrong `r_hat` if not distinguished | Add a covariate (not a separate stratum, to avoid combinatorial explosion of thin strata) to the empirical-Bayes model: proportion of TrashNothing-sourced members in the marginal audience, entered as a regression term when re-estimating `r_hat`. Deferred to rollout phase (§5) once volume is large enough to fit it reliably — flagged, not solved, in this design (see §7) |
| **Data drift** (BackfillReachCommand-style contamination — `5-demand.md` §0 found 56% of `rippling_reach` rows were backfill placeholders that would have silently corrupted any naive analysis) | A recurring batch job or schema change could silently poison the nightly re-estimation the same way it poisoned the one-off analysis | Nightly job **must** apply the same population-cleanliness filters documented in `5-demand.md` §0 (`NOT(status='stopped' AND tick=0)`, `messages.arrival` not `rippling_reach.arrival`) as a standing data-quality gate, not a one-off fix — and should alert (not silently proceed) if the daily volume of "clean" posts drops sharply below trailing average (this doubles as an early-warning signal for other pipeline breaks, not just backfill contamination) |
| **Cold start** (new group, or a stratum with too few posts for reliable holdout signal — Scotland/NI explicitly, `4-audience-curves.md` §3d: Northern Ireland n=51, 100% never-reach-2000; Wales n=523, 99% never-reach) | A brand-new group, or a genuinely thin stratum, has no holdout history to estimate `r_hat` from — naive per-stratum estimation would either refuse to serve (bad) or use a wildly noisy small-sample estimate (worse) | Empirical-Bayes shrinkage (§1.2) is specifically chosen for this: a thin stratum's estimate is pulled heavily toward the network-wide prior (weight `n_s/(n_s+k_pool)` → 0 as `n_s`→0), so a brand-new group or a 51-post NI stratum gets essentially the network average `r_hat`, not a wild extrapolation from a handful of posts and not a refusal to compute anything. As real volume accrues, the estimate smoothly specializes. This is also why sparse areas are *not disadvantaged*: `4-audience-curves.md` already shows sparse areas are overwhelmingly ceiling-bound (100% never-reach N\*=2000 in Wales/NI/Highlands) — meaning for these strata the marginal-benefit rule will almost always say "keep going" all the way to `T_ceiling` regardless of estimation noise, because at low absolute audience the marginal cost stays low too. The backstop ceiling (unchanged 30 min) ensures these areas are never worse off than today |

---

## 4. Self-maintenance — how the parameter stays correct over time

This is the core promise of Philosophy 4, stated explicitly rather than left implicit:

1. **No value is ever "set."** Every `N*(stratum, tick)` in the live `rippling_params` table
   is overwritten nightly by the batch job (§2), sourced from the trailing 14-day holdout.
   There is no code path where a human or a one-off script writes a permanent value — the only
   human inputs are the meta-parameters (§1.5), which are versioned and rarely touched.
2. **Drift is absorbed automatically, within the trailing-window lag** (~14 days for a full
   behavioural shift to be reflected, faster for partial shifts as the shrinkage weight moves).
   Membership growth, seasonal actives, a group's real density changing (new housing
   development, a group merging/splitting) all flow through "this stratum's posts now have
   different tick-1 audiences and different holdout conversion rates" without anyone touching
   config.
3. **The strata definition itself (decile boundaries) is recomputed nightly too** (§1.3 —
   deciles of yesterday's tick-1-audience distribution), so if the whole network's density
   distribution shifts (e.g. mass onboarding of a new large city), the strata boundaries move
   with it rather than becoming stale.
4. **What does NOT self-maintain, and must be reviewed periodically by a human**: the
   meta-parameters themselves (§1.5) — `θ`, `w_reply`/`w_speed`, `C`. These encode value
   judgements (how much do we value speed vs eventual reply; how costly is a notification) that
   data cannot supply on its own. Recommended cadence: quarterly review, informed by the
   same evidence tables this design was built from, refreshed with then-current data — this is
   explicit human governance of the *values*, while the *arithmetic* runs unattended.

---

## 5. Rollout: dark-compute → scoped → network-wide

### Phase 0 — Dark-compute (0 risk, no behaviour change)

- Reuse the exact method `4-audience-curves.md` already used: replay `rippling_reach.schedule`
  history against the proposed marginal-benefit rule, entirely offline, to see what N\* the rule
  *would have* chosen per post, per stratum, against real historical ticks.
  - **This has effectively already been done** in this evidence-gathering phase — `5-demand.md`
    §1/§3/§5/§6 IS this dark-compute, just not yet expressed as a live-updating table. The
    concrete next step is to turn those one-off SQL queries into the nightly batch job (§8).
- Also dark-run the holdout logic: retroactively simulate "what if 5% of each tick's marginal
  audience had been withheld" using existing `rippling_reach_notified` vs
  `messages_likes.pageview` data, to sanity check the estimator code before it touches real
  notification decisions.
- **Exit criterion**: nightly job runs cleanly for 2 full weeks against historical data,
  producing stable-looking `r_hat`/`N*` estimates per stratum that pass the guard-rail checks
  (§6) without ever tripping them on real history.

### Phase 1 — Scoped (real holdout traffic, single stratum, reversible)

- Turn on the real 5% holdout (§1.2) for **one stratum only** — recommend starting with the
  **densest decile** (the 15 inner/west London boroughs, `4-audience-curves.md` §3c) because
  that is (a) where the complaint pressure is concentrated (`2-discourse.md`: 7 of 13 place-pair
  complaints are Greater London), (b) has ample daily volume for fast holdout convergence
  (3,652 posts/10 days, `4-audience-curves.md` §3d), and (c) is exactly the population where the
  marginal-benefit rule is expected to bind (evidence: reply probability actively HALVES from
  38.8%→21.8% as audience grows 1,700→13,000 within the densest tercile, `5-demand.md` §6 — the
  strongest single result in the whole evidence base, meaning this stratum is where getting the
  rule right matters most and where getting it wrong is most visible/costly).
- Run via the existing `RIPPLE_WITHIN_GROUPS` experiment-scoping mechanism
  (`1-mechanics.md` #3 — already built, currently unused), so this is fully reversible with a
  config flip, no code path shared with the rest of the network.
- **What to measure** (weekly review during this phase):
  - Realized `N*` trajectory for the stratum — does it converge and stabilize, or oscillate?
  - Reply probability, time-to-first-reply, Taken-rate for the scoped stratum vs a matched
    control stratum (e.g. the next-densest decile, left on the unchanged fixed-30-min rule) —
    is the marginal-benefit rule actually preserving/improving reply outcomes while cutting
    audience, or just cutting audience?
  - Mod sentiment for the scoped group(s) specifically — direct outreach (not just Discourse
    monitoring) to the mods of the piloted boroughs, given `2-discourse.md`'s finding that
    2 mods resigned and 2 more nearly did over this exact issue; this is a case where a
    qualitative check matters as much as the quantitative one.
  - Guard-rail trip rate (§6) — any anti-oscillation or explosion-detector trips during the
    pilot are treated as a hard stop-and-investigate, not noise.
- **Kill criteria** (any one triggers immediate rollback to fixed-30-min for the pilot
  stratum): (a) Taken-rate for the pilot stratum drops >10% vs matched control over a
  2-week window; (b) `N*` for any (stratum,tick) cell oscillates by >2x from one night to the
  next more than twice in a rolling 4-week window (§6.1 should already prevent this
  algorithmically, so a real occurrence indicates the guard itself is miscalibrated); (c) any
  single explosion-detector trip (§6.2) that isn't explained by a genuine viral post; (d) mod
  complaint volume for the piloted group(s) does not measurably improve within 6 weeks (this
  is the actual product goal — if the data-derived rule doesn't reduce the "feels too large"
  complaints in the group where it's most aggressively applied, the design has failed on its
  own terms regardless of what the reply-rate numbers say).
- **Duration**: minimum 6 weeks (long enough to clear the 14-day prior window twice over and
  see whether the rule is stable, plus time for mod sentiment to actually shift and be
  reported/observed).

### Phase 2 — Network-wide

- Only after Phase 1's exit criteria are clearly met. Roll stratum-by-stratum (densest → least
  dense, since the effect is concentrated in dense strata per `4-audience-curves.md` — sparse
  strata are largely unaffected by any N\* choice in the plausible range, so there is little
  urgency or risk in extending to them, but they should still go through the same holdout
  mechanism for the self-maintenance property to hold everywhere).
- **What to measure ongoing** (permanent dashboard, not just rollout): per-stratum `N*`
  trajectory over time (is it stable? trending?), guard-rail trip frequency, reply/Taken-rate
  trend vs pre-rollout baseline, notification-cost-per-replier trend (should trend down or
  flatten, per `5-demand.md` §5's overshoot-cost finding), mod-facing complaint volume
  (Discourse + direct) as the ultimate product-level success metric.
- **Kill criteria (network-wide)**: same categories as Phase 1, evaluated per-stratum — a
  problem in one stratum triggers rollback of that stratum only (the `RIPPLE_WITHIN_GROUPS`
  scoping mechanism makes per-stratum kill switches cheap), not a network-wide revert, unless
  the guard-rails (§6) themselves are shown to be inadequate, in which case the master
  `RIPPLE_EXTENT_ENABLED` flag reverts everyone to the flat 30-min ceiling (the existing,
  known-safe fallback).

---

## 6. Anti-oscillation / runaway guards

The two failure modes unique to a self-tuning system (that a fixed constant cannot have):

### 6.1 Anti-oscillation

**Risk**: a naive feedback loop can hunt — widen reach because yesterday's `r_hat` looked good,
narrow it because the wider reach diluted per-notification conversion (exactly the pattern
already observed in real data, `5-demand.md` §6: dense-tercile reply probability HALVES as
audience grows — a naive loop could over-correct downward, then under-shoot, then correct back
up, indefinitely.

**Guards**:
- **Rate-of-change cap**: `N*(stratum,tick)` may not change by more than ±20% from one night's
  value to the next (a meta-parameter; 20% chosen as roughly the smallest step that still lets
  the loop converge within the 6-week pilot window from a cold-start guess to the
  `5-demand.md`-implied ~1,000-2,000 range, without single-night estimation noise causing a
  visible swing in live reach from one day to the next — mods should never see reach jump
  wildly day to day, §7 mod-facing stability).
- **Smoothing**: the nightly estimate feeding the rate-of-change cap is itself an exponential
  moving average (not the raw latest night's holdout result) — half-life matched to the
  14-day prior window (§1.2.2), so a single noisy night cannot whipsaw the live value.
- **Hysteresis on the stop decision itself**: within a single post's own tick progression
  (§2, per-post per-tick step 2), the blend between prior and this-post's-own-signal is a
  smoothed posterior update, not a hard threshold crossing — avoids a post flip-flopping
  between "expand" and "stop" from tick to tick due to a single new reply arriving right at a
  decision boundary.

### 6.2 Runaway / explosion guards

**Risk 1 — geometric runaway**: a bad `r_hat` estimate (data pipeline bug, adversarial gaming,
genuine black-swan event) could push `N*` for a stratum far above sane bounds, effectively
notifying far more people than intended.
**Guard**: absolute ceiling meta-parameters independent of the estimation loop — `T_ceiling`
(30min, unchanged) is never overridden by the marginal-benefit rule regardless of what `r_hat`
says (§1.1, `CONTINUE` requires `drive_min[k] <= T_ceiling` as a hard AND, not something the
benefit/cost ratio can override). This is the single most important guard: **the marginal-
benefit rule can only ever narrow reach relative to today's ceiling-bound default, never widen
it past 30 minutes.** This is a deliberate, conservative design choice for launch — it means
the system literally cannot make the "feels too large" problem worse than it already is, only
better, which matters enormously for a hostile mod audience (§7 cons: this also means the
design cannot yet fix genuine UNDER-reach in sparse areas, a real limitation, stated honestly).

**Risk 2 — the deliverability feedback trap** (flagged as unsolved in `1-mechanics.md` #10: high
volume → spam-foldering → measured `r_hat` looks artificially low → system computes a bigger
`N*` to compensate → even more volume → worse deliverability — a genuine positive-feedback
failure mode specific to a self-tuning design). **Guard**: the explosion-detector checks
*absolute* daily notification volume network-wide (and per major mail provider/domain where
bounce/complaint data is available) against a trailing 7-day average; if volume is >X% above
trend (X is a meta-parameter, suggest starting at 50%, the same order of magnitude as the
existing self-tuning scaffold's ±50% "widen" band, `1-mechanics.md` #24), all `N*` updates
freeze network-wide (not just the anomalous stratum, because deliverability degradation is a
domain-level, not stratum-level, phenomenon) and an alert fires for human review. This does not
fully solve the deliverability trap (a genuinely slow-building degradation under the 50%/day
threshold could still occur) — flagged honestly as a residual risk requiring a separate,
dedicated deliverability-metrics feed (bounce rate, spam-complaint rate) as a second guard input
once that data is available; not solved by this design alone.

**Risk 3 — gaming**: a moderator or member group could attempt to influence their own stratum's
`r_hat` (e.g. coordinated fake replies to inflate apparent demand and win a bigger N\*, or
conversely to shrink it). **Guard**: `r_hat` is estimated from the causal LIFT (treated vs
holdout), not raw reply count — inflating raw replies without also inflating the *counterfactual*
holdout replies does not move the lift estimate, because both groups would see the same
coordinated activity. Residual risk (not fully closed by this design): a sufficiently
sophisticated actor coordinating only within the "would be notified" group and not the holdout
group could still bias things; treated as low-probability/low-impact for a reuse platform at
Freegle's stakes level, not engineered against further at launch.

---

## 7. Mod-facing explanation

Given the Discourse evidence (`2-discourse.md`: no moderator proposed a data-derived mechanism;
every in-thread proposal was a manual dial; 2 mods resigned, 2 more nearly did, over exactly
this issue), **stability and legibility of the explanation matter as much as the mechanism
itself.** A mod who sees "the number changes every night for reasons I can't see" will trust
this LESS than a fixed 30-minute rule, even if the adaptive rule objectively performs better.

**What a mod sees, on their group's dashboard/settings page:**

> **Your group's typical reach today: about [N] members, [T] minutes' drive.**
> This isn't a fixed setting — it's calculated automatically from how people in your area
> actually respond to posts, and re-checked every night. Denser areas reach fewer minutes
> because there are more people per minute of drive-time; sparse areas reach further because
> there are fewer. The system stops expanding a post's reach once, on average, sending it to
> one more person stops meaningfully increasing replies or speeding up collection for people in
> areas like yours.
>
> Over the last 4 weeks, your group's typical reach has [gone up / stayed about the same / come
> down] by about [X]%. [If flagged as an outlier: This is noticeably [wider/narrower] than
> similar-sized areas — we're keeping an eye on it.]
>
> This never reaches further than a 30-minute drive, and always stops once a post already has
> 5 people interested.

**Design intent behind this exact wording** (traced to evidence):

- **"calculated automatically... re-checked every night"** — pre-empts the "why is this
  different from last month" question before it's asked; directly answers the self-maintenance
  property (§4) in plain language.
- **"denser areas reach fewer minutes... sparse areas reach further"** — states the actual
  mechanism (audience-based, not distance-based) in the vocabulary a mod already uses
  (minutes of drive-time is what they see today), rather than introducing new jargon like "N\*"
  or "marginal benefit" — matches `2-discourse.md`'s finding that mods think and complain in
  terms of minutes and place-names (Chilterns, Islington), not statistics.
- **"stops... once sending it to one more person stops meaningfully increasing replies or
  speeding up collection"** — states the actual decision rule in a sentence a non-technical mod
  can verify against their own experience, rather than hiding it as an opaque "the algorithm
  decided." This is a deliberate choice to be MORE explainable than the current system (which
  has no explanation at all beyond "30 minutes, always") — turning the adaptive design's
  complexity into a selling point ("smarter, not just fixed") rather than a liability
  ("mysterious black box"), which only works if the explanation is genuinely this concrete.
- **"over the last 4 weeks... gone up/down by X%"** — gives the mod a trend they can sanity
  check against their own sense of the group, and — critically — makes drift *visible* rather
  than silent, which is the antidote to "why did this change without telling me" complaints.
- **The two hard-ceiling sentences at the end** ("never reaches further than 30 minutes",
  "always stops once 5 people interested") — deliberately restates the backstops (§1.6) in
  plain language, because these are the guarantees that make the system feel bounded and safe
  to a mod who does not trust "the algorithm decided" on its own — this directly answers
  `2-discourse.md`'s core finding that trust, not just outcomes, is what's at stake.

**What is deliberately NOT shown to mods**: the internal `r_hat`, `θ`, per-tick marginal
benefit/cost numbers, or the holdout mechanism's existence. `2-discourse.md`'s evidence
(30 vocal participants, self-selected, technical sophistication varies widely) argues strongly
for a plain-language summary over a dashboard of statistics — a mod who wants more detail can
be pointed to a (separate, opt-in) "how reach is calculated" explainer page, but the default
view should be the four sentences above, no more.

---

## 8. Implementation sketch (what changes where — no code)

This section names the concrete surfaces; it does not write code, per the design-only
instruction.

1. **`iznik-batch/app/Services/Ripple/RippleTuneService.php`**: complete the stubbed
   `categoryVolumeDeltas()` (currently returns `[]` unconditionally, `1-mechanics.md` #24) to
   instead compute, per stratum (§1.3, deciles of yesterday's tick-1 `cumulative_users`, not
   the current `ons_category` — this is a change to the stratification key, see below), the
   holdout-vs-treated lift `r_hat` and the speed-benefit `Δ(1/ttfr)`, replacing the current
   flat ±10%/+50% volume-delta-band logic entirely (that logic answers a different, weaker
   question — "did volume change" — not "did the marginal value of an extra notification
   change").
2. **New holdout-assignment step in `ExpandService::advanceDue` / `initialiseNew`**: for each
   tick's marginal audience, randomly split by `p_holdout` before calling
   `mailNewlyReachedForPost` — held-out members still get the `messages_groups`/polygon
   inclusion (so they can browse-find the post) but are excluded from
   `rippling_reach_notified` writes for that tick specifically (needs a new column or a
   companion table, e.g. `rippling_reach_holdout(msgid, userid, tick)`, to record who was
   held out for later cohort comparison — this is the one schema addition this design
   requires beyond what already exists).
3. **`rippling_params` table (`1-mechanics.md` #24, migration `2026_06_18_000010`)**:
   repurpose its `ons_category` column's role to hold the post-audience-decile stratum key
   instead of (or alongside) ONS RU-class — needs either a migration to add a `density_decile`
   column or a redefinition of what's written to the existing key. This table already has the
   right shape (`curve`, `max_minutes`, `target_density`, `hazard_schedule` per category) —
   this design adds `r_hat`, `V_shape` (or its two components), and reads `target_users` from
   it as the per-(stratum,tick) `N*` rather than the flat config constant.
4. **`ReachService::scheduleParams()`**: currently conditionally sends `target_users` only when
   `RIPPLE_EXTENT_ENABLED=true`, sourced from the flat config constant
   (`1-mechanics.md` #15). Change the source to a lookup against the (now-populated)
   `rippling_params` table keyed by the post's own predicted stratum (needs a fast, cheap
   proxy at *initialiseNew* time, before tick-1 audience is known — recommend using the
   group's own trailing-30-day median `total_freeglers` as the stratum-assignment proxy for
   tick 1 specifically, since the true stratum key requires tick-1 data that doesn't exist yet
   at decision time — a chicken-and-egg resolved by "yesterday's typical audience for this
   group" as the entry-point proxy, refined by the post's own realized tick-1 number for all
   subsequent ticks, consistent with §2's per-post per-tick blending).
5. **`iznik-routing-go/ripple.go` `effectiveScheduleTotal()`**: already accepts a
   `target_users` parameter end-to-end and is unit-tested (`ripple_extent_test.go`,
   `1-mechanics.md` #14) — **no change needed here**, this is the part of the MVP plumbing this
   design adopts as-is.
6. **`routes/console.php`**: schedule `ripple:tune` (currently explicitly commented-out /
   unscheduled, `1-mechanics.md` #24) to run nightly, after the completion of item 1 above.
7. **`messages_likes.source` producer gap** (`1-mechanics.md` §5.1 — schema exists, nothing
   writes `?src=ripple_notify`): needs fixing for clean channel-attributed eyeball data,
   feeding the speed-benefit (`Δ(1/ttfr)`) estimate cleanly — currently `ttfr` can be computed
   from `chat_messages` timestamps without this (used in `5-demand.md` §3 already), so this is
   not a hard blocker for launch, but should be fixed to make the eyeball-vs-reply distinction
   available for a future refinement of `V` (an explicit "later" item, not required for v1).
8. **New: guard-rail service** (rate-of-change cap §6.1, explosion-detector §6.2) — a genuinely
   new component, no existing scaffold to build on; should sit alongside `RippleTuneService`
   as the gatekeeper between "nightly-computed new estimates" and "live-served
   `rippling_params` values" (i.e. compute-then-validate-then-promote, not compute-and-write
   directly).
9. **Mod-facing dashboard copy** (§7): a new, small UI surface (ModTools group settings or
   sysadmin/reach page — the existing rippling killswitch/sysadmin page per memory
   `project_rippling_killswitch_and_sysadmin_page.md` is a natural home) showing the four
   sentences plus the trend percentage; reads from `rippling_live_metrics` (`1-mechanics.md`
   #24 — already exists, currently dormant, would become live under this design).

---

## 9. Honest cons — where this philosophy is weakest

1. **`θ=1` is asserted, not derived — and this is the single weakest point in the whole
   design.** The rule says "stop when marginal benefit no longer exceeds marginal cost," which
   sounds principled, but `V` and `C` are meta-parameters chosen partly by back-solving to match
   the observed data (§1.5) — there is a real risk of circularity (choosing `C` so the rule
   produces the answer `5-demand.md` already suggested, then presenting that as "derived").
   Being honest about this: the *shape* of the marginal-benefit curve is genuinely derived from
   data (the flat-then-declining reply curve, the speed curve); the *operating point* on that
   curve (exactly where θ crosses 1) is a value judgement dressed in the same units as the data,
   not itself free of human choice. A hostile reading of this design is "you've just moved the
   arbitrary constant from N\*=4000 to θ=1 plus two weight parameters" — which is not entirely
   wrong. The genuine improvement is that θ/weights are far fewer, far more stable, and far more
   *reusable across strata* than a per-area N\* would be — but it is not constant-free, it is
   constant-minimized and constant-relocated to a place where the constants are meta-level
   (apply everywhere, rarely revisited) rather than per-area (apply differently everywhere, must
   be revisited per area).
2. **Complexity is a real cost against a hostile/technical-skeptic mod audience.**
   `2-discourse.md` shows the actual moderator population is not statistically sophisticated and
   already distrusts "the algorithm decided" framing generally (multiple posts about auto-join
   clutter and loss of local control, beyond just reach). A system whose actual mechanism is "a
   nightly empirical-Bayes shrinkage estimator computing a causal lift from a live experimental
   holdout" is genuinely harder to explain honestly than "we picked 2,000 members based on where
   your city's density puts it" (Design 2/3's likely framing, by comparison). §7's mod-facing
   copy is an attempt to bridge this, but there is real risk the plain-language version reads as
   evasive ("just tell me the number") to exactly the skeptical audience this needs to win over.
3. **The permanent holdout has a real, ongoing cost that a static rule does not.** Every post,
   forever, has some members deliberately not notified who otherwise would be, in the name of
   calibration. This is a genuine tradeoff (lower expected reply-conversion in aggregate, in
   exchange for a system that stays correct over time) that a fixed-constant design does not
   pay. At 5% holdout this is likely small in absolute terms, but it is non-zero and permanent,
   and it means a small number of real transactions are, by design, made slightly less likely
   to happen (a held-out member who would have replied to a direct notification, but doesn't
   browse-find the post organically) purely for the system's own benefit. This is an ethically
   non-trivial tradeoff to make permanently rather than for a bounded 3-week experiment, and
   deserves explicit sign-off (tied to the still-open GDPR/LIA item, `1-mechanics.md` #29) that
   goes beyond the original one-off experiment's likely approval scope.
4. **Feed-forward dominance limits how "adaptive" tick-1 actually is.** Because ~70% of the
   audience is committed at tick 1, ~1h post-arrival, before any of THIS post's own signal
   exists (§1.2.2), the headline claim "reach adapts per-post" is materially weaker than it
   sounds for the majority of the committed audience — the dominant lever is still a
   feed-forward, stratum-level prior updated once a day, not a live per-post read of what's
   actually happening. A skeptical reviewer could fairly say "most of the adaptivity you're
   claiming is actually just a slower-refreshing, better-derived constant" — which is true, and
   should be stated plainly rather than oversold. The genuinely live, closed-loop part of this
   design only really governs the trailing 30% of the audience (ticks 2-9), which is smaller in
   volume but is exactly the part most amenable to true per-post adaptation.
5. **Cold-start shrinkage means genuinely-different-from-network-average areas take time to be
   recognized as such.** A real, unusual area (say, a group with an atypically high genuine
   demand-per-member ratio for structural reasons — a retirement community with unusually high
   engagement, say) will initially be shrunk toward the network average and only slowly
   specialize as its own data accrues — meaning this design is systematically slower to serve
   genuinely-unusual-but-legitimate areas well than a human who could simply notice "this place
   is different" immediately. This is the standard bias-variance cost of shrinkage estimation,
   inherited deliberately (§3, cold start) but worth stating as a real, not hypothetical, cost.
6. **Operational fragility**: this design has materially more moving parts than a constant
   (nightly batch job, holdout routing, guard-rail service, a new schema table's worth of
   holdout bookkeeping) — more surface area for the exact kind of silent data-quality failure
   already observed once in this evidence base (`5-demand.md` §0, the 56%-of-rows backfill
   contamination). A bug in the nightly job that goes unnoticed for a week is a materially worse
   failure mode than a wrong constant sitting in a config file, because it is less visible and
   harder to reason about after the fact. The guard-rails (§6) mitigate but do not eliminate
   this; ongoing operational vigilance is a real, continuing cost this design incurs that a
   fixed-constant design (Designs 1-3, presumably) does not.
