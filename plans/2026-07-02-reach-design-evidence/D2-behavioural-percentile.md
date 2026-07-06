# Design D2 — Revealed-Behaviour Percentile Cap

**Philosophy**: the reach ceiling should be a travel-*time* cap set from what freeglers
*demonstrably do* in that area's density class, not from an audience-count target, an
external planning norm, or a moderator's gut feel. Distance/time is the primitive people
actually experience and complain about ("an hour's drive each way" — Discourse #250); this
design derives the cap directly from Freegle's own collection-distance data, cross-braced
against DfT National Travel Survey norms so the number is externally defensible, not just
an artefact of Freegle's own exposure history.

Author's honesty note up front: this is Philosophy 2 of 4 competing designs. Where I think
another philosophy (e.g. audience-size, Philosophy-1-style) does something structurally
better, I say so in §6 rather than papering over it.

---

## 0. One-paragraph summary

Set each post's drive-time ceiling `T_max(area)` to the **P90 of revealed successful-collection
drive-time** for posts of similar local density, recomputed monthly from a trailing 90-day
window, with a **95% "safety" secondary check** that must also be satisfied (see §1.3), floored
at 10 minutes and capped at 30 minutes (today's ceiling — this design tightens, never loosens,
in v1), and defaulting new/thin-data groups to a **density-matched value from a small number of
density bands calibrated on established groups**, cross-checked against the DfT NTS ~17-20 min
national discretionary-trip anchor. The censoring problem (today's data is truncated at today's
30-min reach) is handled explicitly, not ignored: P90/P95 computed on censored data is a
*lower bound* on true willingness-to-travel, which is exactly the conservative direction we want
for a cap that mods currently think is too generous — so censoring biases this design *safe*,
not dangerous, and is fully decensored once the separate reach experiment reports.

---

## 1. The rule, precisely

### 1.1 Core formula

For a post originating in group *g*, in density band *d(g)*:

```
T_max(d) = clamp( P90_time( collections | density_band = d, trailing 90 days ), 10, 30 )   [minutes]
```

where `P90_time(...)` is the 90th-percentile **drive-time** (not crow-flies distance — see
§1.4 for the distance→time conversion) of successful collections (`messages_outcomes.outcome
IN ('Taken','Received')`, resolved via `messages_promises` taker location, same method as the
behaviour report) whose post originated in a group in density band `d`, over the trailing 90
days.

This T_max replaces `RIPPLE_MAX_MINUTES` (today a single global constant, 30) with a **density-
banded** value. It is a ceiling on the existing drive-time isochoring mechanism — nothing about
`step-70`, the 9-tick hazard schedule, or the reply-saturation-stop (≥5) changes. Only the outer
geometry bound becomes density-aware instead of flat.

### 1.2 Why P90, specifically

- **Not P50/median**: median collection distance (2.1km overall, per behaviour report §1) is
  where *most* collections happen, but a cap set at the median would exclude essentially half
  of today's real, already-happening collections. That is not tightening a too-generous reach,
  that is breaking the product for half of current users. A cap must sit *above* typical
  behaviour, not *at* it.
- **Not P99**: the behaviour report's overall P99 (37.6km / ~40+ min) is dominated by exactly
  the pattern moderators are complaining about — long-tail rural-corridor and motorway-artifact
  collections that make up a small fraction of transactions but a disproportionate fraction of
  mod irritation (Discourse: Chilterns-to-East-London, #250). Setting the cap at P99 would
  preserve almost every current collection but barely move the needle on the complaint.
- **P90 is the standard "cover the overwhelming majority, trim the true long tail" choice**,
  consistent with: (a) the behaviour report's own per-group coverage tables, which frame the
  question as "what cap captures 90-95% of collections" (behaviour report §3, explicit
  recommendation); (b) retail-geography convention, where "primary trade area" analysis
  typically reports the drive-time that captures the large majority of footfall while
  explicitly discounting the long low-density tail (anchors report §3c); (c) it leaves a
  visible, quantifiable 10% of currently-happening collections outside the new cap — small
  enough to not be a regression story, large enough that mods can see the parameter is doing
  real work, not just a token gesture.
- Reply-distance decay (behaviour report §2) is **smooth, no elbow** — there is no
  distance at which conversion rate cliffs to zero, so no percentile is "objectively correct."
  P90 is a defensible, standard, explainable choice among a continuum, not a uniquely provable
  optimum — stated honestly in §6.

### 1.3 The secondary "collection-catch" safety check

Because P90-of-collections is measured on **censored data** (see §1.5), a pure percentile rule
risks a slow, self-reinforcing tightening spiral: shrink the cap → fewer far collections happen
→ next month's P90 is smaller → shrink further. To break this, T_max is **not allowed to drop
by more than 15% month-over-month per density band**, and any proposed reduction must clear a
**collection-catch check**: re-running last month's actual collections against the *proposed*
new, smaller polygon must still capture ≥90% of them (i.e. the new cap must have been able to
serve 90% of what already happened, not just 90% of a percentile computed on a population that
may already reflect an earlier, tighter cap). This is a computed guardrail, not a manual override
— see §5 for the exact procedure.

### 1.4 Distance → drive-time: use the routing engine, not a flat km/h constant

The behaviour report's own km→minute conversion (§ Bottom line table) is explicitly flagged as
an illustrative placeholder ("no road-network data was used"). This design does **not** import
that placeholder. Instead: `iznik-routing-go` already computes real drive-time isochrones for
every rippling post (`/v1/ripple-schedule`) and every post's `rippling_reach.schedule` already
stores `{tick, drive_min, cumulative_users}`. The P90 collection-time computation should use
**the actual drive-time from the post's own tick schedule that contains the taker's location**
(interpolating between the two bracketing ticks by `cumulative_users`/geometry, the same
interpolation already used in the audience-curves dark-compute), not a haversine-km-to-minutes
conversion. For **pre-rippling posts** (no `rippling_reach` row — the March/April baseline used
for decensoring, see §1.5), fall back to a one-off batch routing-engine call per taker/post pair
(`iznik-routing-go` already exposes single-route drive-time; this is a batch job, not a live
dependency) — expensive to do live, cheap to do once as a calibration input.

### 1.5 Handling the exposure/censoring confound — the load-bearing part of this design

This is the crux of Philosophy 2 and the reason a naive "just take P90 of today's data" rule
would be circular: today's collections can only happen within today's ~30-min reach (or group
membership, pre-ripple), so the observed P90 is mechanically capped at whatever the current
ceiling already is. Three concrete de-censoring mechanisms, layered:

**(a) Use the pre-rippling baseline as the primary calibration input, not post-rippling data.**
Pre-rippling (Mar-Apr 2026) collections were bounded by *group membership geography*, not a
drive-time isochoring rule — group boundaries are typically drawn far larger than 30 minutes'
drive in many areas (Swindon-Freegle's polygon is 559 km², Hull's 1,157 km², per the behaviour
report's density table), so the pre-rippling P90 is **less censored** than anything measured
after rippling launched, for the sparse/large-boundary groups that matter most for setting a
*generous-enough* floor. (For dense groups with small polygons, membership itself is the
binding constraint pre-ripple, so this doesn't fully decensor them either — see (b).) Pre/post
comparison (behaviour report §4) already shows this is usable: pre-period P90=13.29km/P99=55.76km
vs post-period P90=12.30km/P99=36.30km — the *tail* is measurably more censored post-rippling
already, direct evidence the censoring effect is real and operating in the expected direction.

**(b) Treat the resulting P90 explicitly as a floor, and say so to the business.** Per the
behaviour report's own framing (repeated verbatim in its central caveat): revealed data is
"usable for one thing with confidence: what cap would preserve X% of collections we are
currently getting... not usable to conclude nobody would travel further." This design adopts
that framing exactly. T_max computed this way is a **conservative floor** — a mathematically
defensible statement of the form "cutting here would not have broken more than 10% of what's
already working," not a claim about optimal reach. This is stated in the mod-facing copy (§4)
and in the internal documentation, so nobody mistakes a censored floor for a demand ceiling.

**(c) De-censor fully once the reach experiment reports.** The separately-specced,
already-scoped reach experiment (user-level ADD randomization, ~3 weeks) is the only mechanism
that can observe genuinely uncensored demand (some users get materially more reach than today's
ceiling; their collection-distance distribution is not truncated by the current cap). This
design's relationship to that experiment (§ explicit, per constraints) is: **the percentile-cap
rule is the pre-experiment interim (what we can defensibly ship now, from data we already
have), and the experiment's treatment-arm collection-distance P90 becomes the new calibration
input the moment it reports — full replacement of the input distribution, same rule structure.**
Concretely: re-run this design's §2 worked-example numbers using treatment-arm-only collections
once the experiment concludes; if the experiment's P90 is meaningfully higher than the
pre-rippling-baseline P90 used at launch, T_max should be revised upward for the affected
density bands (this design does not assume the floor is exactly right — it assumes it is a
safe, defensible starting point, correctable in one direction with real evidence).

**(d) Monitor for the self-reinforcing spiral, mechanically, not just conceptually.** The
15%-per-period cap and collection-catch check in §1.3 are the concrete implementation of "handle
the exposure confound honestly" — they stop the rule from tightening past what current behaviour
actually supports, using computed thresholds rather than a human eyeballing a chart.

### 1.6 Density stratification: which axis, and why

**Axis chosen: the group's own trailing-90-day "collector density"**, defined as:

```
density(g) = (distinct takers within 10km of g's centroid, trailing 90d) / (area within 10km, km²)
```

This is **not** the behaviour report's `membercount / polygon_area_km2` proxy (flagged in that
report as an artefact of the *drawn boundary*, not real local population — Hull's low score
despite being a city is the report's own cited example). Instead it's a fixed-radius disc
around the post origin (10km, a round number inside the observed P90 collection range for
every density class in the behaviour report's table, so it's measuring genuine local activity
density rather than boundary-shape). This sidesteps the Hull artefact: Hull's fixed-radius urban
core density looks like a city because it's measured on a disc, not a hand-drawn polygon that
happens to include rural hinterland.

**Why not ONS Rural-Urban class** (the axis both existing design docs assume): RU-class is a
*residential settlement* classification (is this postcode's home area urban/rural), computed
from Census geography, not a *transaction* classification. It's a reasonable proxy but one step
removed from what actually matters here — how many people are actually transacting nearby. It
also, per the mechanics report, doesn't exist anywhere in the live pipeline today (only in an
offline extractor) — wiring a genuinely new axis (RU-class join) is more implementation surface
than reusing data already flowing through `rippling_reach`/`messages_promises`. This design's
density measure requires **zero new data sources** — it's computable entirely from
`messages_promises` + `users_approxlocs` + `messages`, all already used by the behaviour report's
SQL. (RU-class remains a reasonable **cold-start fallback key** — see §1.7 — since it's
available for every UK postcode from day one, unlike a rolling 90-day transaction density which
needs live history to exist.)

**Number of bands**: 5, chosen to match the behaviour report's exemplar spread (TowerHamlets
1,292/km² down to Hull's 2.9/km² is a ~450x range across 6 named exemplars; 5 log-spaced bands
gives each band a genuinely distinct P90 without over-fragmenting into bands too thin on data
to estimate reliably — see §3 cold-start thresholds). Bands are defined by **quintile of the
density distribution across all groups with ≥90 days of history**, recomputed at the same
monthly cadence as T_max itself (§5), so band boundaries drift with the network rather than
being hand-picked constants.

### 1.7 Cold start default: from the external anchor, not a guess

For a post whose group has <90 days of history, or fewer than the minimum sample size (§3), or
is entirely new: default `T_max = 20 minutes`. Derivation, not a guess:

- DfT NTS0403f (national, 2023-24, official): average shopping-trip duration 17 min; personal-
  business 19-20 min (anchors report §1a). This is the closest available proxy for "a normal
  discretionary local errand" with a citable, primary, government source.
- The anchors report's own conclusion (§ Bottom line): "reasonable discretionary local-errand
  travel time [is] roughly 15-20 minutes one-way... essentially flat across urban and rural
  England when measured in time" — i.e. the ONE number external evidence robustly supports is
  a **time band that does not need density-stratifying**, because DfT's own data shows time is
  the invariant, not distance, across settlement type (anchors report §1b, the single most
  load-bearing external fact in that report).
- 20 minutes sits at the upper end of the 17-20 min DfT band deliberately: a cold-start default
  should be intentionally slightly generous (new groups have the least data to detect if it's
  wrong, so err toward not choking off a brand-new area's early collections) while still being
  materially tighter than today's flat 30-min ceiling.
- This default is **not** a permanent per-group override (that would smuggle the rejected
  manual-slider pattern back in via "just for new groups") — it expires automatically the moment
  the group crosses the minimum-sample threshold in §3, at which point the group is assigned to
  its measured density band like every other group.

---

## 2. Worked examples

Method: use the behaviour report's per-exemplar taker-distance table (§3) as the distance input,
converted to time using the group's own `rippling_reach.schedule` where available (dense groups
have real post-rippling schedule data; sparse groups' schedule is thinner, flagged). Where the
report itself only has distance, I state the km figure and give an honest, flagged time estimate
rather than inventing false precision.

| Group | Density (members/km², report proxy — used only for framing, not the rule) | Taker distance P90 (km, from report §3) | **Estimated drive-time P90** | Density band (this design's disc-based measure, estimated) | **T_max (this design)** | Today's flat ceiling |
|---|---|---|---|---|---|---|
| TowerHamletsFreegle | 1,292 | 4.99 | ~13-15 min (urban stop-start, slow effective speed; TowerHamlets already self-gates to drive_min p50=17.8min per audience-curves report exemplar) | Band 5 (densest) | **~15 min** | 30 min |
| Oxford-Freegle | 243 | 9.69 | ~18-20 min (mixed urban/ring-road) | Band 4 | **~18-20 min** | 30 min |
| EdinburghFreegle | 118 | 8.56 | ~16-18 min | Band 4 | **~18-20 min** | 30 min |
| HullFreegle | 2.9 (artefact — see §1.6, this design's disc-measure would likely place Hull higher than this boundary-proxy suggests) | 12.95 | ~20-22 min | Band 3 (disc-based, not boundary-based — Hull's real urban core is denser than its polygon-average) | **~22-25 min** | 30 min |
| Swindon-Freegle | 16.2 | 21.97 | ~28-32 min (rural/A-road, higher effective speed per DfT rural-speed finding) | Band 2 | **~28-30 min (effectively unchanged from today)** | 30 min |
| Ribble Valley | 9.3 | 19.6 (**n=3, anecdotal only** — report's own flag) | Not estimable with confidence | Band 1 (sparsest) — **falls back to cold-start default (§1.7) or, once ≥90 days/min-n available, its own P90** | **20 min (cold-start) until real data qualifies it**, likely →~30 min once qualified given its rural profile | 30 min |
| "NW-London Offers" (Discourse #250 complaint) | Not a real current group name (behaviour report confirms; likely legacy Yahoo-era name) | N/A directly — proxied by the London-borough cluster (audience-curves report: top-15-audience groups are ALL inner/west London boroughs, 11,000-15,000 median audience) | Proxy via TowerHamlets/inner-London figures above | Band 5 | **~15 min**, i.e. this design would have capped the Chilterns-to-East-London (#250) complaint at roughly a third of the ~50+ min extent the complainant experienced | 30 min |

**Read of this table**: the design does exactly what the philosophy promises — it tightens
materially in London/dense areas (TowerHamlets/inner-London: 30→~15 min, roughly halving the
ceiling, directly answering the #250/#248 complaints) while leaving Swindon and Ribble Valley
essentially where they are today (rural areas are correctly *not* penalised, per the existing
design docs' explicit "rural riding to the ceiling is intended, not a bug" decision, which this
design inherits and agrees with). Oxford/Edinburgh/Hull sit in a middle band, tightened
moderately (~30→~20 min).

**Honesty flag**: the drive-time estimates in this table are derived from the behaviour report's
haversine-km figures via a rough conversion, exactly as flagged in that report — **this is a
worked-example illustration of the rule's shape, not production-ready numbers.** Before
implementation, §1.4's actual-routing-engine recomputation must replace every estimate in this
table with a real `iznik-routing-go` drive-time. I have not fabricated false precision beyond
what the source evidence supports.

---

## 3. Failure modes and mitigations

| Failure mode | Mechanism | Mitigation in this design |
|---|---|---|
| **Coastal/estuary geometry** | A drive-time isochrone from a coastal town has genuinely far collectors (bridge/ferry detours) that a naive distance-based rule would wrongly exclude, because the *road* distance for a legitimately-close collector (across an estuary) is much longer than crow-flies. | This design already uses **drive-time**, not crow-flies distance, as the primitive (§1.4) — this is exactly the failure mode drive-time isochoring exists to solve, and it's inherited correctly from the current mechanism. The P90 is computed on real routing-engine drive-times, so a coastal group's genuinely-necessary detour collectors are captured in its own P90, not penalised by a distance rule. |
| **Group-boundary gerrymandering** | A group draws an artificially large or small polygon to game its density band. | Density (§1.6) is computed from a **fixed 10km disc around the post origin and real transaction history**, not from the group's drawn polygon at all — there is nothing to gerrymander. A group cannot change its T_max by redrawing its boundary. |
| **Seasonal actives (e.g. student towns, holiday areas)** | A university town's active-member density swings termly; a coastal town's swings with tourist season. A yearly-cadence recompute would badly lag these swings. | Monthly recompute (§5) with a trailing 90-day window is short enough to track a typical term/season transition within roughly one cycle, while the 15%-per-period cap (§1.3) prevents a single anomalous month (e.g. university move-out week) from swinging T_max wildly. |
| **TrashNothing / cross-posted members** | Members who joined via TrashNothing or other federated import may have stale or low-quality `users_approxlocs` data, distorting distance measurement for their transactions. | This is an existing data-quality issue for *all* current reach measurement (not unique to this design) — the behaviour report already surfaces the >100km outlier tail as "likely stale-location or delivery-arrangement noise, not drive-and-collect" (§1). Recommend a **hard data-quality trim** at the P99.5 level before computing P90 (P90 itself is already robust to a small fraction of extreme outliers, but a documented trim step should be added to the actual query so this isn't left to chance). Does not require solving the underlying data-quality problem, just not letting it corrupt the percentile calculation. |
| **Data drift (network grows/changes over time)** | The whole point of "self-maintaining" — if T_max is set once and never revisited, the same staleness problem (that produced the original 30-min flat-constant complaint) recurs slowly. | §5's monthly re-estimation cadence with automatic band-boundary recompute IS the self-maintenance mechanism — no separate drift-detection layer needed beyond what's already in §1.3's guardrail (which catches both directions: too-fast tightening AND, implicitly, would show if collections are being missed if catch-rate degrades). |
| **Cold start (new/small groups, Scotland/NI sparse regions)** | A brand-new group, or a low-volume rural region, has too few transactions to compute a reliable P90 — a percentile on n<30 is noise, not signal. | Two-tier fallback, both derived (not guessed): (1) **minimum sample size gate**: require ≥50 collections in the trailing 90-day window before a group gets its own measured T_max (50 chosen as a standard-practice minimum for a stable percentile estimate — below this, standard error on P90 is too wide to trust, consistent with why the behaviour report itself flags n=3/n=15 rows as "anecdotal only, not data"); (2) below that threshold, fall back to the group's **density band's aggregate P90** (pooling across all qualifying groups in the same band gives enough n even for a thin single group); (3) if the group cannot even be density-banded yet (truly brand new, no transaction history at all), use the **§1.7 external-anchor cold-start default (20 min)** until enough history accrues to assign a band. Scotland/NI groups with genuinely low volume (not zero, just thin) sit at tier (2) — pooled by density band, which for most of Scotland/NI (per the audience-curves report's region rollup: rural/Highland areas cluster at the sparse end) means they inherit a wide, generous, rural-appropriate T_max from other sparse-band groups nationally, not a data-starved single-group estimate. |
| **Circular tightening spiral** (the central methodological risk of this whole philosophy) | P90 measured on censored (reach-capped) data → cap set slightly below true demand → next period's data is measured on the *new*, tighter cap → P90 shrinks again → repeat, converging toward zero. | This is the single most important failure mode for Philosophy 2 specifically, and is handled three ways, all already specified above rather than bolted on: (a) §1.3's hard 15%-per-period-per-band tightening limit; (b) §1.3's collection-catch check (a proposed new T_max must be shown to have caught ≥90% of *last month's actual* collections, not just satisfy a percentile computed on a possibly-already-shrunk population); (c) §1.5(c)'s explicit plan to swap in the reach experiment's uncensored treatment-arm data the moment it's available, which structurally breaks the spiral by introducing a genuinely uncensored input at least once. |

---

## 4. Mod-facing explanation

Two short sentences, shown wherever a moderator currently sees (or asks about) their group's
reach setting — deliberately not a slider, not a raw number without context, matching the
"explainable to a hostile moderator audience" constraint:

> **"Freegle sets how far an Offer ripples based on how far people in your area actually
> travel to collect free items — currently up to about [T_max] minutes' drive, covering 9 in
> 10 of the collections that already happen here. This is recalculated every month from real
> collections, not set by hand, so it adjusts automatically as your area changes."**

If a mod asks "why is my number different from [other group]": a second sentence, used only on
demand (e.g. an FAQ/tooltip, not the default copy, to avoid inviting district-level slider-style
comparisons):

> **"Denser areas naturally have shorter typical collection distances — city-centre collectors
> are usually a few minutes away, while collectors in a rural area often need 20-30 minutes'
> drive to reach the same number of options. We match the reach to what's normal for your
> area, so a dense group and a rural group can both cover 9 in 10 of their real collections
> without either one over- or under-reaching."**

This is deliberately framed around **the mod's own group's real collections** (something a mod
cannot dispute — it's their own transaction history) rather than an audience-count or a national
policy analogy, since Discourse evidence (report 2) shows mods respond to concrete,
locally-verifiable claims ("Swindon ~1,000 members... perfect", #248) far better than abstract
governance language.

---

## 5. Rollout: dark-compute -> scoped -> network-wide

**Stage 0 — Dark-compute (no product change, 1-2 weeks)**
- Compute the full density-band table and each band's P90/P95 from real `messages_promises` +
  `users_approxlocs` + `messages` history (pre-rippling baseline per §1.5(a), reusing exactly the
  behaviour report's SQL pattern) — this document's §2 already prototypes this read-only.
- Run the **collection-catch backtest**: for every band, simulate "what if T_max had been the
  computed value for the last 90 days" and measure what % of *actual* collections would have
  been outside the simulated polygon. This is the same check specified as an ongoing guardrail
  in §1.3, run once here as a pre-launch validation.
- Publish the dark-compute table (band boundaries, T_max per band, backtest catch-rate) for
  product sign-off before touching any live config.

**Stage 1 — Scoped (2-4 weeks, `RIPPLE_WITHIN_GROUPS` mechanism already exists per mechanics
report item 3)**
- Enable density-banded T_max for a deliberately chosen mixed set: 2-3 groups from the densest
  band (the complaint population — e.g. Tower Hamlets, an Islington-area group), 2-3 from the
  sparsest (Ribble Valley-like, to confirm no regression), 2-3 mid-band (Oxford/Edinburgh-like).
- **What to measure**: (a) collection rate (Taken/Received per post) before vs during, per group
  — must not measurably drop for any scoped group; (b) reply-saturation-stop firing rate
  (currently near-zero per demand report — should remain near-zero, a sudden change would
  indicate the new cap is interacting oddly with the existing stop); (c) mod-reported sentiment
  via a direct, short survey to the scoped groups' mods (not just Discourse-watching, since
  Discourse participants are a self-selected, vocal minority per report 2's own caveat);
  (d) notification volume per post (should drop for dense-band groups, roughly flat for
  sparse-band); (e) the collection-catch backtest re-run live, weekly, during this stage.
- **Duration**: minimum 4 weeks, to span at least one full trailing-90-day-window recompute
  cycle end-to-end (so Stage 1 validates not just the initial cap but the re-estimation
  mechanism itself, not just a one-off number).

**Stage 2 — Network-wide**
- Roll out density-banded T_max to all groups with ≥90 days history / ≥50 collections (§3);
  cold-start default (§1.7) for the remainder.
- Monthly automated recompute (§5's own cadence) becomes a scheduled job from day one of network
  rollout — not a manual re-run, to avoid the parameter drifting stale again (matching the
  "self-maintaining" constraint from the brief).

**Kill criteria** (any one triggers an immediate rollback to the flat 30-min ceiling for the
affected scope, investigated before re-attempting):
- Collection rate (Taken/Received ÷ Offers posted) drops >10% relative, week-over-week, for any
  scoped/rolled-out density band, sustained for >2 weeks (not a single noisy week).
- The collection-catch backtest (§1.3) shows actual catch-rate falling below 85% for two
  consecutive monthly recomputes in any band (signals the tightening spiral is winning despite
  the 15% clamp — investigate before allowing a third cycle).
- Reply-saturation-stop (≥5) firing rate rises materially (would indicate posts are now
  under-reached relative to real interest, contradicting the "expand until interest is served"
  logic the current system already encodes).
- Sustained, specific mod complaints (not general rippling sentiment, which is confounded by
  the unrelated per-member opt-down slider shipped 2026-07-01 per Discourse report — must
  isolate T_max-band complaints specifically) about *under*-reach in a band that was tightened.

---

## 6. Honest cons — where this philosophy is weakest

- **It answers "what does the current system already achieve" better than "what would the
  system achieve if freed from today's constraints."** This is the single biggest weakness,
  and it's structural, not fixable within this philosophy: because the primary calibration
  data is inherently a *behaviour-within-exposure* distribution (behaviour report's own central
  caveat, quoted verbatim in §1.5), this design is fundamentally better at defending a
  **conservative floor** than at discovering the *right* answer. If true demand is meaningfully
  higher than what's revealed today (plausible — rippling is only ~2.5 weeks old at time of
  writing, adoption/awareness is still low per the behaviour report's pre/post section), this
  design will under-shoot and require the experiment-driven correction in §1.5(c) to fix later —
  i.e. it is deliberately not a self-sufficient final answer, it is a defensible interim that
  needs the experiment to complete it. A philosophy anchored on *audience size* (what Freegle's
  own extent-governor MVP already sketches) doesn't have this specific circularity, because
  audience count isn't self-referentially capped by the very geometry it's trying to size —
  though it has its own calibration problem (§ the "what should N* be" question the demand report
  shows is *also* not fully solved by audience data alone).
- **P90 is a defensible convention, not a provably optimal choice** (§1.2 already says this
  honestly) — the reply-distance decay is smooth with no elbow, so any percentile choice is
  ultimately a judgment call dressed up as a formula. A hostile mod could reasonably ask "why
  90 and not 85 or 95" and the honest answer is "industry/statistical convention for
  'overwhelming majority, trim genuine tail'," not "because the data proves 90 is correct."
- **Density-by-transaction-disc (§1.6) requires enough live transaction history to compute at
  all** — which means this design's core input doesn't fully exist yet for brand-new areas
  (handled by cold-start fallback, §1.7/§3, but that fallback is external-anchor-derived, not
  Freegle-data-derived, so it inherits DfT's "general UK discretionary trip" number rather than
  anything Freegle-specific for exactly the groups where local specificity would matter most).
- **The collection-catch backtest (§1.3), which is this design's main defence against the
  tightening spiral, itself relies on continuing to have real collections happening in the
  wider (pre-tightening) area to backtest against** — once a band has been tightened for a full
  cycle, the "actual collections" used for the next backtest are already partially shaped by
  the tightened cap, so the guardrail's power to detect over-tightening weakens exactly as it
  becomes most needed. This is a smaller, second-order version of the same censoring problem
  the whole design is built to manage, and is only fully resolved by §1.5(c)'s experiment-data
  swap-in, not by anything internal to this design.
- **Doesn't natively address mod load** (the number of *groups* a post ripples into) — this
  design only changes the geometric ceiling, not the "ripple into every intersecting group"
  behaviour flagged in the mechanics report as unbuilt (`home-first chunking`). A smaller T_max
  does indirectly reduce group-count somewhat (smaller polygon intersects fewer group
  boundaries), but that's a side-effect, not a designed mechanism — a mod-load-focused design
  would need that separately regardless of which reach philosophy wins.
- **Does not use the schedule/hazard-tick infrastructure's audience data at all** (`cumulative_users`
  per tick, already persisted per the mechanics report, zero schema change) — a design that used
  that data (Philosophy 1-style, audience-sized burst) gets a more direct, more explainable
  read on "how many people will see this" per post, which is arguably closer to what actually
  drives mod-load and notification-fatigue complaints than travel-time is. This design is
  travel-time-first because that's the assigned philosophy and because it maps most directly
  onto the exact language moderators used in their complaints ("an hour's drive", #250) — but
  it is deliberately not claiming travel-time is the *only* right lens, just the best-evidenced
  one for *this* philosophy's brief.
