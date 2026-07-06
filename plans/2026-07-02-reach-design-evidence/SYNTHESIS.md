# Rippling Reach: Audience-Normalized Extent, with Anti-Spiral Guardrails and Catchment-Map Co-Location

**Status: design only, no implementation.** Synthesized from four independently-authored designs
(D1-D4), scored by three judges on independent lenses (product/moderator-trust, statistical
validity, engineering/operations), and six evidence reports covering current mechanics, the
Discourse complaint thread, revealed collector behaviour, real dark-computed audience curves,
demand sufficiency, and external travel-time anchors. All three judges independently selected
**D1 — Audience Normalization** as the base philosophy (scores 63/63/60 of 70, versus 60/57/61,
53/54/56, and 51/52/54 for D2/D3/D4 respectively). This document is D1 as the spine, with three
concrete grafts the judges converged on, resolving the one open contradiction between them.

---

## 1. The rule, in one sentence

> **Every Offer expands, tick by tick, until it has reached roughly as many active local Freeglers
> as its own group normally has — not until it has driven a fixed number of minutes.**

Formally:

```
N*(post) = clip( k × active_members(home_group),  N*_min = 1,000,  N*_max = 4,000 )

At every tick, STOP expanding as soon as ANY of:
  cumulative_users  >= N*(post)                         (the audience-budget stop — NEW, this design)
  drive_min         >= T_max = 30 min                     (the geometry ceiling — EXISTING, unchanged)
  distinct repliers >= reply_saturation_stop = 3           (demand-exhaustion stop — RECALIBRATED, was 5)
  AND never stop before drive_min >= T_min = 10 min       (locality floor — EXISTING, unchanged)

k = 1.0, re-fit quarterly per density band, subject to two anti-spiral guardrails (§4)
```

Drive-time becomes an **output** of this process (and a safety envelope for the areas where
audience is genuinely scarce), not the control variable. This directly answers the flagship
Discourse complaint — "same slider position, ~1,000 members in Swindon judged 'pretty perfect',
~27,000 in Islington judged too far" (post #248) — by making the *audience*, not the *time*, the
thing that is held equal-ish across areas, within a floor and ceiling that keep sparse areas
protected.

This is not a new invention: it completes an already-coded, unit-tested, currently-dark feature.
`RIPPLE_EXTENT_ENABLED` and `RIPPLE_EXTENT_TARGET_USERS=4000` already exist end-to-end in
`iznik-routing-go/ripple.go` and `ReachService.php` — wired, tested, switched off, with a flat,
uncalibrated placeholder value. This design's entire job is to answer the question the original
MVP plan explicitly left open: **how is the target derived, stratified by area, and kept correct
over time** — not whether the audience-budget mechanism is the right shape.

---

## 2. Why this rule, and not the other three

All three judges converged on D1 despite scoring it through genuinely different lenses (product
trust, statistical rigor, engineering risk), which is itself evidence the choice is robust rather
than an artefact of one evaluation frame. The convergent reasoning:

- **It is the only design whose central claim was confound-checked, not just correlated.** The
  demand report's within-density-tercile analysis (stratifying by each group's own active-member
  scale, then looking at the reply-rate-vs-audience curve *inside* each stratum) rules out the
  obvious objection that "more audience just means more density, and density alone explains more
  replies." Holding density fixed and varying audience within it, reply probability still peaks
  near roughly 1x the tercile's own scale and *declines* past it (dense tercile: 38.8% at ~1,700
  members down to 21.8% at ~13,000). No other design's core number survives this kind of check.
- **It completes shipped infrastructure rather than adding new machinery.** D1 needs zero new
  schema, zero new services, zero new experiment apparatus — it supplies a missing number to code
  that already runs the full pipeline in shadow. D2 needs new monthly density-banding
  infrastructure; D3's literal formula needs an unbuilt UI primitive to be cheap and, worse, its
  own worked examples show the literal brief formulation (2x a London borough's own span) never
  binds in the complaint geography, forcing a pivot to a different rule the design didn't set out
  to build. D4 triples the constant count while claiming to eliminate constants, and stacks a
  permanent 5% notification holdout (open GDPR/LIA question), a new gatekeeper service, and nightly
  empirical-Bayes estimation onto a population that has already lost two moderators to resignation
  over a trust deficit around "the algorithm decided."
- **It is provably inert exactly where nobody is complaining, and provably binding exactly where
  everybody is.** Real dark-computed data (not simulation) shows Hull, Swindon and Ribble Valley
  never cross even N\*=2,000 — they stay 100% ceiling-bound, completely untouched by this design —
  while every group in the flagship complaint geography (inner/west London boroughs) sits at 3-5x
  the proposed cap today and would tighten materially. This directly falsifies the standing
  objection in the evidence base that "a cap which decreases with density is wrong": D1's cap
  does *not* decrease with density — it scales with each group's *own* active-member base, so
  dense groups still get large N\* values, just capped below their uncapped potential, while
  sparse groups keep today's protective floor.

D1 is not perfect, and this document does not pretend otherwise (§9). What it wins on is a
narrower, more defensible claim than "solves rippling complaints": it fixes the *audience-fairness*
dimension of the single most quantified, most cited complaint (equal time producing 14-80x
unequal audiences), using data and infrastructure that already exist, with a rollback path that
costs one config flag.

---

## 3. Deriving every constant

No number in this design is asserted; each has a stated evidence source and an explicit re-fit
mechanism (§7). This table is the complete constant inventory.

| Constant | Value | Derivation | Source |
|---|---|---|---|
| `k` (multiplier on active members) | **1.0** | Within-density-tercile reply-probability peak sits at ~0.65-1.0x each tercile's own active-member scale (medium tercile: active-member midpoint ~1,363, peak audience ~1,000 → 0.73x; dense tercile: midpoint ~2,598, peak audience ~1,700 → 0.65x). 1.0 is a round, slightly-conservative fit (errs toward more reach, not less) rather than an overfit to two noisy points. | `5-demand.md` §6 (confound-controlled, the strongest single result in the evidence base) |
| `N*_min` (floor on target) | **1,000** | Fine-bucket table: 500-1,000 members already shows P(≥1 reply)=34.2%, materially above the 0-250 bucket's 27.0% and close to the observed peak (35-41%); below ~1,000, reply probability measurably degrades even in the sparsest observed data. Independently, the audience-curves dark-compute finds 1,000 is the smallest N\* that discriminates at all — below it, the 9-tick schedule's own granularity dominates the number, not the target. | `5-demand.md` §1; `4-audience-curves.md` §2 |
| `N*_max` (ceiling on target) | **4,000** | Dense-tercile decline is unambiguous by audience ~8,600+ and severe by ~13,000; 4,000 sits comfortably below both ("past the reply-rate peak, short of the demonstrated decline"). Not coincidentally the existing coded default (`RIPPLE_EXTENT_TARGET_USERS=4000`) — kept, not silently changed, since the evidence independently re-justifies it. | `5-demand.md` §6 |
| `active_members(group)` | live query | Memberships with `collection='Approved'` and `lastaccess` within 90 days on the post's home group — a number Freegle already computes (it is literally the denominator behind the density terciles). Zero new data pipeline. | `5-demand.md` §6 methodology |
| `T_min` (floor, drive-time) | **10 min** | DfT NTS0403f: national one-way shopping-trip duration averages 17 min, flat across urban/rural bands. Retail "primary trade area" convention: 5-10 min generates 50-80% of footfall. 10 min is the lower edge of that convention band — below it, "give the local area first look" is defeated even if N\* is nominally already met by a single dense tick-1 burst. Matches the existing MVP floor (adoption, not a change). | `6-external-anchors.md` §NTS0403f, §retail convention |
| `T_max` (ceiling, drive-time) | **30 min, unchanged** | Already ~1.75x the 17-min national shopping-trip average; every Discourse complaint is about reach being too *large*, none about too small; T_max is already the *only* binding constraint for the 35%+ of groups that never reach even N\*=2,000 (Wales, Scottish Highlands, Cornwall, Northumberland, rural East Anglia) — raising it would only expand rural reach further (uncontroversial), while N\* is what tightens dense areas. No case exists in the evidence to raise it to the previously-floated 45. | `4-audience-curves.md` §3d; `2-discourse.md` (zero requests to increase reach) |
| `reply_saturation_stop` | **3** (was 5) | The live value of 5 has fired **zero times** in 10,742 genuinely-rippled posts — it is not a governor, it is dead code. P(≥3 replies) is 4-8% across audience deciles vs P(≥5) at ~1% — 3 is where deciles start showing daylight, i.e. the smallest threshold that actually discriminates between posts. Flagged as a companion fix, not this design's primary lever (N\* is). | `5-demand.md` §1 |
| Quarterly re-fit cadence | **90 days, density-banded not region-banded** | Balances tracking real drift (membership growth, seasonal actives) against noise from Scotland/NI's small samples (NI n=51 at N\*=2,000 sweep) — re-fit must pool thin regions into shared density bands, never fit nation-by-nation on small n. | `4-audience-curves.md` §3d; graft from D2's monthly-banding practice, cadence kept quarterly per D1's slower/more-stable posture |
| Anti-spiral rate cap | **±15% per re-fit period on `k`**, plus **collection-catch backtest ≥90%** | Graft from D2 (§4 below) — a computed, mechanical circuit-breaker against the "tighter cap → thinner data → tighter cap" spiral this class of self-tuning design is structurally exposed to. | `D2-behavioural-percentile.md` §1.3 (adopted wholesale, adapted from T_max to N*/k) |

**What is explicitly NOT re-derived per group:** `α`-style multipliers, per-group manual dials, or
region-by-region hand-picked constants. The only per-group-varying input is `active_members`,
which is a fact Freegle already tracks about every group, not a judgment call made about it.

---

## 4. The graft: anti-spiral guardrails (from D2)

D1's honest cons (§9) name a real risk: the periodic re-fit could enter a self-reinforcing
tightening spiral if a shrunk N\* mechanically produces a lower measured reply rate next quarter
(because fewer people were ever notified, not because fewer people would have replied), and the
re-fit reads that as "confirmation" that N\* should shrink further. All three judges flagged this
independently and all three named the same fix, taken directly from D2's percentile-cap design,
which had to solve an equivalent problem for its own `T_max` parameter.

Two mechanical guardrails, both computed, neither a manual override:

**(a) Hard per-period rate-of-change cap.** No quarterly re-fit may move `k` (or the derived
`N*_min`/`N*_max` clips) by more than **15% from the previous quarter's value**, per density
band. A re-fit that would move `k` further than that is not applied automatically — it is a
signal something has broken (input data, methodology, or a genuine but implausibly large real
shift) and routes to manual review before any live change.

**(b) Collection-catch backtest.** Before any re-fit is allowed to *shrink* `N*` for a density
band, replay last quarter's actual collections (Taken/Received outcomes with a resolved taker
location, exactly the methodology already used in the behaviour report) against the *proposed*
smaller N\*. The proposed value may only go live if it would still have captured **≥90% of those
real collections** — i.e. the new, tighter number is shown to serve what already happened, not
just satisfy a percentile computed on an already-shrunk population. This is D2's exact mechanism
(§1.3 of that design), ported from a distance percentile to an audience target: same logic, same
threshold, different unit.

Together these convert D1's soft "halt on out-of-band k, review manually" trigger (§8 of the
original design) into something with a computed pass/fail gate *before* any live change, not a
qualitative judgment call made after the fact. This is the single highest-value graft: it is cheap
(no new infrastructure, reuses data both designs already needed), and it directly answers the
statistical-validity lens's strongest objection to self-tuning designs of this shape (Judge 2:
"today's realized audiences are already capped at 30min, so the observed 'peak' could be curvature
within a truncated range" — the exact chicken-and-egg risk these guardrails exist to catch).

---

## 5. The graft: catchment-map co-location (from D3)

D3's single strongest property, independently praised by all three judges, is that its parameter
and its mod-facing explanation are provably the same fact — a mod sees a number on a map, and that
number *is* the parameter, not a separately-asserted claim about it. This directly answers the
Discourse evidence's core warning: an invisible-but-well-derived parameter still needs a **visible
"why this number" artefact**, because the moderator population is non-technical, already
distrustful of "the algorithm decided" framing, and has already lost members over exactly this
trust gap.

Freegle already has the artefact this needs. The **Group Catchment map / Rippling Explorer tab**
(modtools task list #12-#24 in this codebase; component files `RipplingExplanation.vue`,
`RipplingExplanationGeneral.vue`, `RipplingHelpModal.vue`, `PostMap.vue` already exist) shows a
group's catchment heat-shaded by ripple-in time, alongside the group's own internal road span
("widest span" box — task #21/#22, distance + postcodes + road-distance in miles) — built and
awaiting release.

**The graft, concretely:** surface `N*(group)` and the resulting drive-time on that same map, not
in separate prose. Specifically:

- The catchment heat-shading (already built, time-banded) gets one more overlay ring: the drive-time
  at which this group's posts *actually* stop today under the audience-budget rule, computed live
  from the same `active_members` count the rule uses.
- The "widest span" box (task #21/#22, already scheduled, not yet built) sits directly alongside
  the audience-normalized ripple boundary, so a mod can visually compare "how far my own group
  reaches internally" against "how far my group's posts currently ripple" on one screen, without
  reading a paragraph of justification first.
- The mod-facing copy (§6 below) becomes a caption *underneath* that map, not a freestanding
  explainer page — the same discipline D3 argued for, applied to D1's parameter instead of D3's.

This costs nothing new to compute (the map and the N\* value are both already-planned or
already-built independently) — it is a UI sequencing decision, not new engineering: **ship the
Rippling Explorer tab and the audience-normalized N\* value together, on the same screen**, rather
than the map landing first and the parameter explanation landing later as a separate feature.

---

## 6. The graft: feed-forward honesty (from D4)

D4's single most defensible technical point, again independently flagged by all three judges, is
mechanical rather than philosophical: **~70% of a post's eventual audience is committed by tick 1,
roughly one hour after posting** (the "step-70" hazard curve — tick 1 fires at t=1h, not t=0, and
the remaining 30% spreads linearly across 8 more ticks out to 168 hours). This means any design
that talks about the reach parameter "adapting" is, for the large majority of the audience, really
describing a **periodically-refreshed prior**, not live per-post responsiveness.

D1's rule is exactly this shape (tick-by-tick expansion governed by a quarterly-refreshed `k`,
not a live per-post feedback loop), and this design should say so plainly rather than let the
"self-tuning" framing in §7 imply more real-time adaptiveness than actually exists. Concretely,
this synthesis's own documentation and mod-facing copy state explicitly:

> "The reach target is recalculated from real data every three months, not live for each post. For
> most of a post's audience — the majority reached within the first hour — the number in effect is
> the one set at the last quarterly review, not a fresh calculation for that specific post."

This is a one-paragraph honesty addition, not new engineering. It costs nothing to build and
directly forecloses a specific, technically well-founded objection ("you're claiming this adapts
per-post, but 70% of the audience was already locked in before your algorithm saw any signal from
this post") that a technically literate critic — or a future audit — could otherwise raise. D4's
own honest-cons section makes exactly this admission about its own more elaborate design; there is
no reason D1 should claim a cleaner story than the ground truth supports.

---

## 7. Per-area worked examples

All figures below are taken from the real dark-computed `rippling_reach.schedule` data (persisted
routing-server ticks, 2026-06-22 to 2026-07-02, no simulation), the demand report's
density-tercile table, and the audience-curves exemplar-group breakdown. Where a group's exact
`active_members` count was not directly pulled in the underlying reports, the audience-at-ceiling
figure is used as a conservative proxy and flagged as an assumption.

| Area | Active members (or proxy) | N* = clip(1.0×active, 1000, 4000) | Today: audience @ 30min ceiling | Today: drive-time to reach it | Under this design: drive-time to reach N* | Under this design: final audience | What changes |
|---|---|---|---|---|---|---|---|
| **NW-London Offers** (Discourse-cited, no exact DB match — proxied by the inner-London borough cluster: Ealing/Brent/Hammersmith&Fulham) | ~12,000-14,000 active | **4,000 (capped)** | ~12,500-14,800 | ~30.0 min (rides ceiling, like all top-15 London groups) | ~17-19 min (interpolated between the N\*=2,000 London p50 of 13.0min and the N\*=4,000 band) | **~4,000** (vs ~12,500+ today) | This is the flagship fix: same locality-first principle, ~3x smaller audience, ~11-13 fewer minutes of drive-time. Directly answers post #250 (Chilterns-to-East-London, "an hour's drive each way", 32 groups). |
| **Tower Hamlets** | ~3,469 (dense tercile top) | **3,469** (uncapped, within [1000,4000]) | 13,336 @ p50 17.8 min (already partially self-gated by the un-fixed reply-stop today) | 17.8 min | ~13-15 min (interpolated toward N\*≈3,469) | **~3,469** | A further ~3-5 min tightening on top of today's already-reduced figure — roughly a 4x audience reduction versus the raw 30-min ceiling case. |
| **Oxford** | ~3,600-3,800 (proxy: audience-at-ceiling) | **~3,600** | 3,829 @ p50 30.0 min (near-ceiling-bound already) | 30.0 min | ~28-29 min | **~3,600** | Small, appropriate tightening. Oxford was never named in any Discourse complaint — this design correctly leaves it close to today's behaviour. |
| **Swindon** | ~600-1,000 (proxy: audience-at-ceiling 672; never reaches even N\*=1,000) | **1,000 (floor)** | 672 @ 30.0 min | 30.0 min | **30.0 min — unchanged** | **672 (unchanged)** | Design working exactly as intended: Swindon was the "fine as-is" comparator in post #248 and stays untouched. |
| **Hull** | ~659 (proxy: audience-at-ceiling; 100% never-reach at N\*=2,000/4,000) | **1,000 (floor)** | 659 @ 30.0 min | 30.0 min | 30.0 min — unchanged | **659 (unchanged)** | Same as Swindon — ceiling-bound, design-inert. Answers Group-Mod-J's "over the water" (Hull vs East Riding) complaint by *not* pretending to fix it with this lever (a purely cultural-boundary complaint this design cannot and should not try to solve; see §9). |
| **Ribble Valley** | ~1,000-1,446 (proxy: audience-at-ceiling 1,446; 80% never-reach at N\*=2,000) | **~1,000-1,446** | 1,446 @ 30.0 min | 30.0 min | ~27-29 min for the ~20% of posts that do cross the floor early; 30 min (unchanged) for the 80% majority | **~1,000-1,446** | Essentially unchanged; a very small tightening for a minority of posts only. Rural riding to the ceiling remains the intended, undisturbed behaviour. |

**The pattern this table demonstrates**: the design is inert for Hull, Swindon, and (mostly)
Ribble Valley — exactly the areas nobody is complaining about — and materially binding for Tower
Hamlets and the wider inner-London cluster that generated every quantified Discourse complaint.
Oxford sits appropriately in between. This is not an assertion; it is what the real persisted tick
data already shows would happen, computed without touching any code.

---

## 8. Mod-facing explanation, tied to the catchment map

Per §5, this text is designed to sit as a caption directly under the Rippling Explorer / Group
Catchment map, not as a standalone page:

> **How far your group's posts ripple**
>
> Every Offer expands outward until it has reached roughly as many active Freeglers as your own
> group normally has — shown on the map above, capped between 1,000 and 4,000 people depending on
> your group's own size. That means a small rural group and a large London borough both get a fair
> local audience for their area, instead of both being forced to travel the same number of minutes
> down the road regardless of how many people that covers.
>
> In a dense area, that audience is reached in a few minutes. In a sparse area, it can take up to
> 30 minutes' drive to find that many people at all — and the post keeps expanding that far because
> there simply aren't more people any closer. The shaded map above shows exactly where that
> boundary sits for your group right now.
>
> This number isn't set by us, and it isn't a dial groups can turn. It's recalculated automatically
> every three months from how many of your own members are actually replying to posts at different
> reach sizes — for most posts, that means the number in effect was set at the last quarterly
> review, not freshly calculated for that specific post. If it's consistently missing genuine local
> interest, or reaching further than it needs to, that shows up in the data and the number moves on
> its own at the next review. Tell us on Discourse and we'll check the numbers behind your group
> specifically.

This directly answers the two structurally distinct hostile-audience objections on record: (1)
"why does my post go as far as someone in a totally different density area" (posts #248, #250) —
answered by "equal audience, not equal time", shown visually on the map, not just asserted in
prose; (2) "why can't I just set this myself" (the rejected-slider precedent, and the in-thread
manual-dial proposals at #289, #304, #373, #397) — answered by making explicit the number is
derived from evidence and re-measured, specifically not a per-group toggle, while being honest
(§6's graft) about what "automatically recalculated" actually means in practice.

---

## 9. Failure modes and mitigations

| Failure mode | Risk | Mitigation |
|---|---|---|
| **Coastal / estuary geometry** | A half-circle catchment (coastal town) could behave oddly under a fixed-radius rule. | Self-corrects structurally: N\* is defined on active-member count, not distance or area, so a coastal town simply takes longer to accumulate the same N\* than an inland town of equal density. A genuine strength of audience-normalization over any fixed-time or fixed-distance design. |
| **Group-boundary gerrymandering** | A group could inflate its own N\* by encouraging low-engagement sign-ups that count toward membership. | `active_members` is `lastaccess`-gated (90-day genuine activity), not raw membercount — a materially higher bar than mass-inviting. `N*_max=4,000` bounds the maximum possible gain even for a huge group. The re-fit (§4) uses *reply outcomes*, not `active_members` itself, as the fitness signal — an inflated count that doesn't produce more replies drags that density band's `k` down over time. |
| **Seasonal actives (student towns, holiday areas)** | A university town's active count swings termly; a coastal town's swings with tourist season. | `active_members` is a live query recomputed on every post, not a cached quarterly figure — the count itself already tracks seasonality in real time. Only `k` and the min/max clips are on the slower quarterly cycle. |
| **TrashNothing / cross-posted members** | If a meaningful share of "active members" are TrashNothing-side users with structurally different reply behaviour, the input and its reply-rate calibration could be systematically biased for high-crossover groups. | **Not resolved by the current evidence base** — no report measured TrashNothing member share or reply-rate differential. Explicit gap: before rollout, segment `active_members` and reply rate by primary access channel; if TrashNothing-heavy groups show a materially different curve, they need their own `k`. |
| **Data drift / policy-induced circularity** | Rippling itself changes membership patterns (rippled-in users sometimes convert to full members), so `active_members` is partly a *consequence* of the very policy being tuned by it. | This is precisely why the re-fit is periodic, not one-time. `active_members` changes on a scale of months, materially slower than the quarterly re-fit cadence, so each re-fit sees a settled input rather than a same-cycle feedback loop. The anti-spiral guardrails (§4) are the concrete backstop against this risk compounding unnoticed. |
| **Cold start (new/tiny groups)** | A brand-new group has `active_members` near zero. | The floor (`N*_min=1,000`) handles this directly — a new group gets the same protective floor as any small/sparse group. One implementation guard needed: a group younger than 90 days should use raw membercount as the `active_members` proxy, not read a `lastaccess`-windowed zero. |
| **Scotland / Northern Ireland (thin data)** | Small samples (NI n=51) make a nation-specific re-fit dangerously noisy. | NI and most of Scotland are already entirely T_max-bound under this design (same as Hull/Swindon) — not a failure mode requiring mitigation for the *rule* itself. The re-fit must be done on density bands pooled across regions, never fit nation-by-nation on thin data — specified explicitly in §3/§4. |
| **Self-reinforcing tightening spiral** | Shrunk N\* → fewer people notified → lower measured reply rate next quarter → re-fit reads that as confirmation → shrinks further. | The §4 graft: hard 15%-per-period rate cap plus the ≥90% collection-catch backtest, both computed, both gating any *shrinking* re-fit before it goes live. |
| **Mod-load (group-count) — the complaint's most literal mechanism** | Moderators are also annoyed because posts appear in many groups they oversee, not only because of raw audience size. Mod-load is governed by group-*count* under the current "ripple into every intersecting group" engine, and capping audience alone does not shrink group count. | **Explicitly not fixed by this design.** Requires separate, unbuilt group-chunking work (the MVP plan's T2.1b, "not started"). Stated honestly here rather than oversold: this design fixes "equal time is unfair" (the specific, quantified, most-cited complaint), not "rippling causes mod overload" broadly. |

---

## 10. Rollout: dark-compute first, then scoped, then network-wide

**Stage 0 — Dark-compute re-validation (no live change, days).** Before flipping any flag, re-run
the demand report's density-tercile analysis with `k` swept over {0.5, 0.75, 1.0, 1.25, 1.5} on
already-collected data, confirming `k=1.0` remains at or near the empirical peak. Pure SQL against
existing tables. Deliverable: a one-page confirmation (or correction) before any code path changes
behaviour.

**Stage 1 — Scoped canary, dense-tercile only (~5-10 groups, 3+ weeks).** Enable
`RIPPLE_EXTENT_ENABLED=true` with the derived formula for a small number of dense-tercile test
groups (the population where this design is expected to bind — the top-15 London-cluster plus a
mid-density comparator like Oxford, to see both a strongly-binding and a barely-binding case).
Rural/sparse groups are automatically the built-in control group — they are unaffected by
definition, so no separate canary is needed for them.

Metrics tracked (all already instrumented, zero new pipeline):
- Reply rate (P(≥1), mean repliers) vs. matched historical baseline — must not regress.
- Time-to-first-reply — a *small* regression is expected and acceptable (this design intentionally
  trades some audience, and the speed it buys, for less mod-load/complaint); a large one is not.
- Notified-count and notifications-per-replier — expected to *fall* for canary groups; this is the
  mechanism's direct cost saving.
- Taker-distance / collection-coverage — must not regress; this is the "did we accidentally miss
  real collectors" check.
- Discourse/mod-complaint volume from canary-group moderators specifically.

Duration: minimum 3 weeks, to clear the 7-day hazard schedule's tail and accumulate enough
Taken-outcome lag (real signal needs ~14+ days from post arrival).

**Stage 2 — Network-wide rollout, density-band by density-band, London first.** Roll out in
descending order of expected impact: London (most-affected, best-measured) → South East/Yorkshire
& Humber/East mid-density bands → sparse/rural bands last (design-inert there, so rollout order is
a formality, not a risk). Re-check the same canary metrics at each band before proceeding.

**Kill criteria (any one triggers immediate revert to `RIPPLE_EXTENT_ENABLED=false` for the
affected band):**
- Reply rate (P(≥1)) drops >15% relative to pre-rollout baseline, sustained over more than one
  week (not a single noisy day).
- Collection-coverage (taker-distance capture rate at the group's previous effective radius) drops
  by more than 5 percentage points — provable, not hypothetical, missed collectors.
- Any canary/band group's moderator escalates a complaint of the same severity class as the
  resignation-triggering complaints already on record (a qualitative but real, board-visible
  trigger, given two moderators already resigned over rippling and two more nearly did).
- A quarterly re-fit is blocked by the §4 anti-spiral guardrails more than twice in a row for the
  same density band — this indicates the input data or method has degraded, not that the true
  optimum is genuinely moving, and should halt automated re-fitting pending manual review.

---

## 11. Self-maintenance loop

Every quarter (90 days):

1. **Re-derive `k` per density band** (not per region — Scotland/NI's thin samples get pooled into
   a shared "small dense" or "small sparse" band with similarly-sized clusters elsewhere, never
   fit alone) from the trailing 90 days of live reply-decile data, using exactly the demand
   report's §6 methodology.
2. **Check both anti-spiral guardrails (§4)** before applying any proposed change: the 15%
   rate-of-change cap, and the ≥90% collection-catch backtest for any shrinking move. A re-fit
   that fails either gate routes to manual review, not automatic application.
3. **Re-derive `N*_min`/`N*_max` only if the fine-bucket reply-rate table shows the
   flattening/decline points have moved by more than 20%** from the values that set today's
   1,000/4,000 — small quarter-to-quarter noise should not move the clips.
4. **Write the new values to `rippling_params`** — the existing, currently-unwired scaffold table
   — and have `ReachService.php` read `k`/`N*_min`/`N*_max` from there instead of the static config
   constant, with the static value retained only as a fallback default.
5. **This is the first real consumer of the stubbed `RippleTuneService::categoryVolumeDeltas()`**,
   which today unconditionally returns an empty array. Instead of inventing new self-tuning logic
   at implementation time, it computes exactly the density-tercile reply-decile table this design
   already specifies and has already validated.

**Relationship to the existing extent-governor MVP:** adopt and complete it. The MVP's mechanism
(audience-budget stop, already coded) is not replaced — it is exactly what this design turns on.
The only change is supplying the previously-missing derivation for the target in place of the
flat, uncalibrated placeholder, and wiring the dark self-tuning scaffold to actually maintain it.

**Relationship to the planned reach experiment:** complementary, not competing, and answers a
different question. The reach experiment (user-level ADD randomization, ~3 weeks) measures whether
reach *beyond* today's ceiling would help — an expansion/upper-bound question, structurally
unobservable from history because everyone extra reach would add is currently unexposed. This
design answers whether today's reach is *already enough, unevenly distributed* — a
reallocation/efficiency question, and the demand-flattening evidence already visible in-sample
suggests the answer is "mostly yes, and in dense areas more reach actively hurts." The two can run
concurrently provided they are statistically disentangled (the experiment should exclude
canary-band groups from its treatment arms, or the canary should be sequenced to start after the
experiment's ~3-week readout if analytical interference is a concern) — a brief coordination check
before Stage 1 begins is recommended.

---

## 12. Honest cons

**1. The core derivation rests on exactly two clean data points.** The within-tercile peak was
observed cleanly in the medium and dense terciles only — the sparse tercile never showed a decline
in the observed range, so `k` for sparse areas is assumed to generalize from the same ratio rather
than independently confirmed. This risk is *contained* (sparse groups are floor/ceiling-bound
regardless, so an unverified `k` there is functionally inert per §7's worked examples) but the
derivation confidence is genuinely asymmetric across density bands, and that should be stated
plainly rather than hidden behind one clean-looking formula.

**2. Mod-load (group-count) is not fixed by this design.** Covered fully in §9's failure-mode
table — worth repeating here because it is the single most important scope boundary. This design
fixes the audience-fairness dimension of the flagship complaint cleanly; it does not fix "posts
appear in too many groups a moderator oversees", which needs separate, unbuilt group-chunking work.

**3. Reply-rate-as-optimization-target is itself gameable/mis-specified over the long run.**
Replies are influenced by many things this design doesn't model (post quality, photo presence, time
of day, category desirability), and the periodic re-fit implicitly assumes any drift in the
reply-vs-audience relationship is due to the audience parameter being wrong, when it could equally
be a shift in post mix, seasonality, or the deliverability feedback loop flagged elsewhere in the
evidence base as unresolved (high volume → spam-foldering → measured reply rate looks artificially
low → an over-correction in the wrong direction). The §4 anti-spiral guardrails are a partial
defence against this, not a complete one.

**4. Equal-audience has its own fairness critique, symmetric to the one it replaces.** A genuinely
huge, dense group's posts get proportionally less exposure relative to their own membership than a
small group's posts do (a 15,000-active-member group capped at 4,000 = ~27% of its own base; a
1,000-active-member group gets the full 1,000 = 100% of its own base). This is the entire point for
the density complaint, but it is a real, different-axis inequality this design deliberately trades
in — worth naming explicitly rather than presenting audience-normalization as a free lunch.

**5. Depends on data that is itself only 10 days old and pre-dates full-volume rippling maturity**
(home-group reply share already drifted from ~98% to 82.5% in nine days). A derivation built on 10
days of post-launch data, in a system whose own behaviour is still visibly settling, carries
meaningfully more re-fit risk in its first year than the quarterly cadence assumes. The first 2-3
quarterly re-fits should be treated as provisional and higher-variance than quarter-5's will be,
and communicated as such internally.

**6. Feed-forward dominance limits how "adaptive" this design actually is for most of the audience**
(the §6 graft's own honest admission) — for the ~70% of a post's audience committed within the
first hour, the number in effect is a quarterly-refreshed prior, not a live per-post calculation.
This is stated plainly in the mod-facing copy (§8) precisely so the design does not overclaim
real-time responsiveness it does not have.

---

## 13. Open questions

- **TrashNothing crossover** — does a materially different reply-rate curve exist for
  high-crossover groups, requiring a separate `k`? No report in the evidence base measured this;
  it is a one-query check that should happen before Stage 1, not a blocker to Stage 0.
- **Deliverability feedback loop** — is high-volume rippling itself suppressing measured reply rate
  via spam-foldering, in a way that could bias the quarterly re-fit toward an incorrectly larger
  (not smaller) `N*` in exactly the areas already generating the most volume? Flagged, not
  resolved, in the underlying mechanics evidence; worth a dedicated check before the re-fit is
  first allowed to run unattended.
- **Exact sequencing with the Rippling Explorer / Group Catchment map release** — §5's graft
  assumes the map and the audience-normalized N\* land together on the same screen; if the map
  ships materially before this design's Stage 1, is there value in a stopgap that at least surfaces
  today's flat 30-min ceiling on the map before the audience-normalized replacement is ready, or is
  it better to hold the map release until both can land together?
- **Exact sequencing with the reach experiment** — §11 recommends a coordination check before
  Stage 1 to avoid statistical interference between the two; the exact mechanism (excluding
  canary-band groups from experiment treatment arms, versus sequencing one after the other) has not
  been decided and needs an owner.
- **Group-chunking (mod-load fix)** — this design explicitly does not address it (§9, §12). Is
  there appetite to scope that as a distinct, parallel effort, given it is the one dimension of the
  flagship complaint this design leaves untouched?
- **Should `reply_saturation_stop` move from 5 to 3 as a standalone change, ahead of the main N\*
  rollout?** It is dead code today (never fired in 10,742 posts) and the fix is a one-line config
  change with no dependency on anything else in this design — but it was not the evidence base's
  primary target and deserves its own small validation pass rather than being bundled silently into
  Stage 1.
- **First-year re-fit variance (con #5)** — should the first 2-3 quarterly re-fits use a tighter
  rate-of-change cap than 15% (e.g. 10%), given the acknowledged higher noise in the founding
  dataset, reverting to 15% once the system has a full year of settled data?

---

## Evidence and design sources

- `/tmp/claude-1000/-home-edward-FreegleDockerWSL/32f74873-7429-488e-b2f3-7ded306eaba4/scratchpad/reach-design/1-mechanics.md`
- `/tmp/claude-1000/-home-edward-FreegleDockerWSL/32f74873-7429-488e-b2f3-7ded306eaba4/scratchpad/reach-design/2-discourse.md`
- `/tmp/claude-1000/-home-edward-FreegleDockerWSL/32f74873-7429-488e-b2f3-7ded306eaba4/scratchpad/reach-design/3-behaviour.md`
- `/tmp/claude-1000/-home-edward-FreegleDockerWSL/32f74873-7429-488e-b2f3-7ded306eaba4/scratchpad/reach-design/4-audience-curves.md`
- `/tmp/claude-1000/-home-edward-FreegleDockerWSL/32f74873-7429-488e-b2f3-7ded306eaba4/scratchpad/reach-design/5-demand.md`
- `/tmp/claude-1000/-home-edward-FreegleDockerWSL/32f74873-7429-488e-b2f3-7ded306eaba4/scratchpad/reach-design/6-external-anchors.md`
- `/tmp/claude-1000/-home-edward-FreegleDockerWSL/32f74873-7429-488e-b2f3-7ded306eaba4/scratchpad/reach-design/D1-audience-target.md` (base philosophy — winning design, all three judges)
- `/tmp/claude-1000/-home-edward-FreegleDockerWSL/32f74873-7429-488e-b2f3-7ded306eaba4/scratchpad/reach-design/D2-behavioural-percentile.md` (graft: anti-spiral guardrails, §4)
- `/tmp/claude-1000/-home-edward-FreegleDockerWSL/32f74873-7429-488e-b2f3-7ded306eaba4/scratchpad/reach-design/D3-community-relative.md` (graft: catchment-map co-location, §5)
- `/tmp/claude-1000/-home-edward-FreegleDockerWSL/32f74873-7429-488e-b2f3-7ded306eaba4/scratchpad/reach-design/D4-adaptive-control.md` (graft: feed-forward honesty, §6)
- `/tmp/claude-1000/-home-edward-FreegleDockerWSL/32f74873-7429-488e-b2f3-7ded306eaba4/scratchpad/reach-design/J1-judge.md`, `J2-judge.md`, `J3-judge.md` (three independent scoring passes)
- Live codebase: `RIPPLE_EXTENT_ENABLED`, `RIPPLE_EXTENT_TARGET_USERS` in `config/freegle.php`;
  `iznik-routing-go/ripple.go`; `ReachService.php`; `RippleTuneService`; `rippling_params` table;
  modtools components `RipplingExplanation.vue`, `RipplingExplanationGeneral.vue`,
  `RipplingHelpModal.vue`, `PostMap.vue` (Rippling Explorer / Group Catchment map, built, awaiting
  release — modtools task list items #12-#24 in this working tree)
