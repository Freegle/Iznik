# Judge 3 — Engineering, Operations & Safety Lens

Scoring the four rippling-reach designs against the eight criteria, from the perspective of:
"if I have to build this, run it in prod, be on call for it, and defend it when it breaks at 3am,
which one do I want to own?" Read in full: D1-audience-target.md, D2-behavioural-percentile.md,
D3-community-relative.md, D4-adaptive-control.md, plus the six evidence reports
(1-mechanics.md through 6-external-anchors.md) for grounding on what's actually coded/dark/stubbed
in prod today.

---

## Scoring matrix (1-10 per criterion)

| # | Criterion | D1 (audience-target) | D2 (behavioural-percentile) | D3 (community-relative) | D4 (adaptive-control) |
|---|---|---|---|---|---|
| C1 | Fixes dense-area complaint | 8 | 8 | 7 | 8 |
| C2 | Preserves rural utility | 9 | 9 | 9 | 9 |
| C3 | Principled derivation | 7 | 6 | 5 | 5 |
| C4 | Explainability & mod trust | 8 | 8 | 9 | 5 |
| C5 | Self-maintenance | 6 | 7 | 8 | 7 |
| C6 | Robustness (edge cases) | 7 | 7 | 6 | 6 |
| C7 | Buildability & fit | 9 | 7 | 6 | 4 |
| C8 | Scientific honesty (censoring) | 6 | 9 | 6 | 7 |
| | **Total (raw sum /80)** | **60** | **61** | **56** | **51** |

---

## Per-criterion rationale

### C1 — Fixes the dense-area complaint

- **D1 (8/10)**: Audience-curves dark-compute directly shows N*=2000-4000 cuts Tower Hamlets from
  17.8min/13,336 audience to ~11-15min/~3,469, and inner-London top-15 groups from ~30min/12-15k
  audience down to a ~4,000 cap — this is a *measured*, not hypothetical, tightening on exactly
  the complaint population. Docked two points because it fixes the audience-fairness axis of the
  complaint but explicitly does not touch mod-load (group-count), which is part of the same
  complaint's lived mechanism (posts appearing in too many groups a mod oversees).
- **D2 (8/10)**: Worked examples (flagged as illustrative, pending real routing data) show
  TowerHamlets/inner-London ~30min→~15min — a comparable, evidence-consistent tightening. Docked
  for the same mod-load gap as D1, plus the worked-example numbers are explicitly a placeholder
  conversion (km→min via assumed speed) rather than real routing-engine output, so as-specified
  today it is one step further from "would actually ship this number" than D1's numbers (which
  come from real persisted `rippling_reach.schedule` ticks).
- **D3 (7/10)**: The literal brief formulation (α×span) is proven by the design's own worked
  examples to NOT bind in the complaint geography at all — a genuine, admitted failure to solve
  the stated problem as specified. The design recovers by substituting a different formulation
  (adjacent-ring) that does bind (~15-18min for inner-London). Scored lower than D1/D2 specifically
  for engineering-process risk: shipping "the assigned rule doesn't work, here's a different one
  that does" is a legitimate design pivot, but it means the actual specified mechanism has less
  empirical runway behind it (the ring formulation's worked numbers are geometry estimates, not
  measured ticks) and a reviewer has less confidence the *next* edge case won't need another pivot.
- **D4 (8/10)**: Grounded in the single strongest evidence result (reply probability actively
  HALVES 38.8%→21.8% within the densest tercile as audience grows) and the mechanism (marginal
  benefit/cost) is designed to bind hardest exactly where that decline is steepest — dense London.
  Same mod-load caveat as D1/D2. Not scored higher than D1 because the actual bound achieved
  depends on meta-parameters (θ, C, w_reply/w_speed) that are back-solved to "roughly reproduce"
  the same ~1,000-2,000 answer D1 derives more directly — so the *outcome* is not obviously better
  than D1's, only reached by a more roundabout, harder-to-verify path.

### C2 — Preserves rural utility

All four designs explicitly verify (via the audience-curves dark-compute or equivalent) that
Hull/Swindon/Ribble Valley are ceiling-bound and therefore untouched by the new mechanism — this is
a shared strength across all four, reflecting that they all correctly read the same evidence.
- **D1 (9)**: Direct: floor/ceiling structure is unchanged, N* simply never binds for these groups
  (measured, not assumed).
- **D2 (9)**: Direct: P90-of-local-collections naturally sits near 28-30min for sparse/low-density
  bands; cold-start default (20min) is even a touch more generous than nothing. Slight residual
  risk noted in D2 itself: the 15%/period tightening clamp could, in a genuinely thin-data rural
  band, spuriously tighten if a bad month of data comes through — mitigated but not zero risk.
- **D3 (9)**: Ring formulation clamps at 30min for every rural exemplar (Hull's "over the water"
  case explicitly preserved) — geometrically guaranteed, not measured, which is arguably even
  safer (no data-dependency at all for this population).
- **D4 (9)**: Explicitly shown structurally immune — sparse strata almost always satisfy "keep
  going" under the marginal-benefit rule because both benefit and cost stay low, and the hard
  T_ceiling=30min backstop guarantees no regression regardless of estimator error.

### C3 — Principled derivation (no arbitrary constants)

- **D1 (7/10)**: k=1.0 is triangulated from three sources, one of which (Demand §6 within-tercile
  peak) is explicitly the strongest single result in the whole evidence base and has the exposure
  confound directly ruled out. But the design itself admits this rests on only two clean data
  points (medium/dense terciles) — a real, acknowledged weakness. N*_min/N*_max derivations are
  reasonable and traced to specific evidence rows.
- **D2 (6/10)**: P90 is honestly flagged, in the design's own words, as "a defensible convention,
  not a provably optimal cutoff" — the design is scientifically honest about this but that is
  itself an admission the choice is not fully principled, only reasonable. Cold-start default (20
  min) is well-anchored to DfT. Density-banding axis (10km transaction disc) is a genuinely
  cleaner, more principled choice than group polygon — a real strength within this criterion.
- **D3 (5/10)**: The headline number the brief asked for (α=2.0) is triangulated from three sources
  similarly to D1's k, but the design's own worked examples then show it doesn't produce the needed
  behavior — so the "principled" α is not actually what ships; the shipped rule (adjacent-ring, no
  multiplier) has no equivalent derivation at all beyond "one ring feels like the right topological
  unit" — explicitly conceded in the design's own cons section as a single global judgment call
  with no evidence-derived depth parameter. This is the most honest self-own of over-claiming
  principled-ness in any of the four designs, which is worth crediting under C8 but costs real
  points here under C3.
- **D4 (5/10)**: The functional form (marginal benefit vs marginal cost) is elegant and the shapes
  (ΔP(reply), Δ(1/ttfr)) are genuinely data-derived. But θ=1, C, and the w_reply/w_speed ratio are,
  by the design's own admission in §9.1, "meta-parameters... back-solved to roughly reproduce the
  answer the evidence already suggested" — i.e. the design does not eliminate an arbitrary constant,
  it relocates and multiplies it (four meta-parameters instead of one N*). The design's honesty
  here is commendable but the derivation itself is weaker than D1's on this specific axis.

### C4 — Explainability & mod trust

- **D1 (8/10)**: Clean one-sentence mental model ("equal audience, not equal time") that directly
  rebuts both classes of hostile Discourse objection. Slight complexity cost: mods must accept
  "your reach is capped by comparing to your OWN membership," an abstraction one level removed
  from something they can visually verify (unlike D3's map).
  D4's own critique of D1-style designs — that "your own group's active count" is somewhat harder
  to make concrete/inspectable than a place-based ring — is fair and costs D1 a point relative to
  D3.
- **D2 (8/10)**: Anchored on "your own group's real collection history" — concrete and
  locally-verifiable, matching the Discourse evidence's finding that mods respond best to
  concrete claims. Slightly abstract in explaining *why* P90 specifically (a mod could reasonably
  ask "why not P95").
- **D3 (9/10)**: The strongest of the four on this axis specifically: the ring-formulation
  explanation ("reach = to your immediate neighbouring groups") is the literal mental model at
  least four Discourse mods reached for unprompted (Jax, Jos, Group-Mod-J's "over the water"
  framing) — this is not a retrofit explanation, it's the actual vocabulary the complainants used.
  It also dovetails with an already-planned catchment-map UI (#21/#22), meaning the mod-facing
  number and a visual map become provably the same fact — the single strongest trust-building
  property among the four designs, because it eliminates "trust me, the algorithm is right" in
  favor of "look at the map, this is where your neighbours are."
- **D4 (5/10)**: The design's own honest cons section states the core risk plainly: a system whose
  actual mechanism is "nightly empirical-Bayes shrinkage estimator computing a causal lift from a
  live experimental holdout" is fundamentally harder to explain honestly to a non-technical,
  already-skeptical mod population than a fixed number tied to geography or their own group's
  data. The mod-facing copy in §7 is explicitly designed to hide the internals (θ, r_hat, holdout)
  rather than expose them — which the design itself flags as "risk it reads as evasive." Given
  that this evidence base shows two mods already resigned and two more nearly did over exactly a
  trust deficit, shipping the design with the least defensible trust story is a genuine
  engineering-operations risk, not just a communications nuisance.

### C5 — Self-maintenance

- **D1 (6/10)**: Quarterly re-fit of k per density band is specified, concretely tied to reusing
  the existing (currently dead) `RippleTuneService`/`rippling_params` scaffold. But quarterly is a
  slow cadence relative to the seasonal/drift risks named in the design's own §3 (seasonal actives,
  data drift, deliverability feedback loop) — the design acknowledges active_members itself updates
  live (good) but the *k multiplier* and clips only move every 3 months, and the design's own §6
  admits the first 2-3 quarterly re-fits should be treated as "provisional/high-variance" given
  only 10 days of underlying data at design time.
- **D2 (7/10)**: Monthly recompute (faster cadence than D1's quarterly) with a mechanical
  15%-per-period tightening clamp and a live collection-catch backtest guardrail — this is a
  genuinely concrete, computed anti-drift mechanism, not just a cadence promise. Slightly below D3
  because it still depends on live transaction volume for every band's recompute (thin bands are
  more fragile than D3's zero-data-dependency geometry).
- **D3 (8/10)**: The strongest self-maintenance story structurally: because span/adjacency are
  re-derived geometric facts (recomputed monthly + event-triggered on boundary edits/group status
  changes), "is this still correct" reduces to "is the map still accurate" — a categorically easier
  operational question than "is our statistical calibration still valid," and one with essentially
  zero risk of the kind of silent numerical drift the other three designs must guard against with
  explicit clamps/backtests. Docked two points because it is not "self-maintaining" in the sense of
  responding to demand signal at all — a group whose real local demand shifts (grows or shrinks)
  independent of its geography gets no adjustment; the design is honest about this in its own cons.
- **D4 (7/10)**: On paper the most sophisticated self-maintenance mechanism (nightly re-estimation,
  automatic drift absorption within ~14 days, strata that redefine themselves nightly) — genuinely
  the fastest-reacting of the four to real behavioural drift. But this is also the design's biggest
  operational liability: more moving parts (nightly batch, holdout routing, guard-rail service, new
  schema) means more ways for the self-maintenance mechanism ITSELF to silently break — and this
  evidence base already recorded one real incident of exactly that failure mode (56% of
  `rippling_reach` rows corrupted by an unnoticed backfill job). D4's own §9.6 names this risk
  candidly. Scored above D1 for reaction speed, below D3 for operational safety.

### C6 — Robustness (coastal, tiny groups, overlaps, gaming, cold start, TN members)

- **D1 (7/10)**: Coastal geometry self-corrects well (audience-based, not shape-based — a genuine
  strength). Cold start handled by the N*_min floor, with one flagged one-line guard needed for
  groups <90 days old. Gaming (inflating active_members) is honestly discussed with a real but
  slow mitigation (re-fit trends down if inflated members don't reply). TrashNothing crossover is
  explicitly flagged as an unresolved gap, not measured — a real gap for a "read-only sampling
  encouraged" design phase that had the data available to at least attempt.
- **D2 (7/10)**: Coastal geometry handled correctly via drive-time-based P90 computation (not
  crow-flies). Gerrymandering is well-defended (fixed 10km disc, ungerrymanderable by construction
  — one of the strongest anti-gaming stories among the four). Cold start has a clean three-tier
  fallback (min-sample gate → density-band pooling → DfT anchor). TrashNothing handled as a
  documented pre-existing data-quality issue with a concrete mitigation (P99.5 trim) rather than a
  flagged gap — slightly more resolved than D1's treatment. Docked for the circular-tightening-
  spiral risk being the design's own central, load-bearing vulnerability, even though it's well
  mitigated (15% clamp + backtest) — this is a real, structurally-inherent fragility other designs
  don't share to the same degree.
- **D3 (6/10)**: Coastal geometry explicitly deferred to "must use routing engine, not ST_Area" —
  correctly identified but not actually computed in the worked examples (illustrative geometry-
  radius numbers used instead), a real gap between claim and demonstration. Gaming is the honest
  weak point: the design itself states the α-formula IS gameable (draw a bigger polygon → bigger
  cap) and only "mostly cancels" under the ring formulation, relying on a human HQ-review process
  as backstop rather than an algorithmic guard — weaker than D1/D2's mitigations. Cold start is
  D3's best property (zero dependency on any post/reply history). TrashNothing is out-of-scope by
  design (doesn't affect polygon size) — a legitimate "not applicable" rather than a resolved gap.
  Adjacency-graph fragility (merged/deleted/enclave groups) is a genuinely new operational surface
  the other designs don't have, honestly flagged in the design's own §3.7 and §6.3.
- **D4 (6/10)**: Coastal/gerrymandering handled elegantly by construction (stratifying on
  post-level realized audience sidesteps both, a genuinely clever design choice not shared by
  D1-D3). Cold start via empirical-Bayes shrinkage is textbook-correct and well-specified. But this
  is the only design that introduces THREE genuinely new operational risks with no precedent
  elsewhere in the evidence base: (1) the deliverability feedback trap (explicitly named as
  "unsolved" even after mitigation), (2) permanent-holdout GDPR/LIA exposure with "no natural
  expiry" (explicitly flagged as needing sign-off beyond the original one-off experiment's scope),
  (3) anti-oscillation guards that are themselves new, untested machinery layered on top of an
  already-complex estimator. Each is honestly flagged, but stacking three novel, not-yet-built
  safety mechanisms onto a live user-notification pipeline is a materially higher-risk profile than
  D1-D3's edge cases, which are mostly extensions of statistical/geometric machinery that already
  exists.

### C7 — Buildability & fit (leverages existing infra, realistic increment)

- **D1 (9/10)**: The cleanest fit of the four. It adopts the Stage-A audience-budget mechanism that
  is *already coded end-to-end* (`ripple.go`, `ReachService.php`, unit-tested) and dark only for
  lack of a calibrated `target_users`. The single missing piece — how to derive N* — is exactly
  what this design supplies. No new schema, no new pipeline, no new experiment infrastructure. The
  quarterly re-fit reactivates (rather than replaces) the existing dead `RippleTuneService`/
  `rippling_params` scaffold. This is about as close to "flip a flag with a calibrated number" as
  a data-derived design can get.
- **D2 (7/10)**: Extends (doesn't replace) the existing isochroning mechanism — genuinely
  incremental, and reuses `RIPPLE_WITHIN_GROUPS` for scoping. But it does NOT use the
  already-persisted `cumulative_users` audience data (a design-time miss the design itself
  concedes in its own weakest-point section), and its core input (10km transaction-disc density) is
  a genuinely new computed axis requiring new monthly batch infrastructure that doesn't exist today
  (unlike D1's reuse of an already-built pipeline). Distance→drive-time conversion via real routing
  data is specified correctly but not yet demonstrated (worked examples use placeholder
  conversions).
- **D3 (6/10)**: Needs zero schema change (`max_minutes` is already a per-request parameter) and
  reuses the polyindex-intersection primitive `ExpandService` already computes for cross-posting —
  genuinely lightweight in one respect. But its cheapest implementation path is an explicit **hard
  dependency on an unbuilt feature** (the moderator catchment-map "widest-span" UI, backlog #21/22)
  — if that lands first, D3 is cheap; if not, D3 needs its own span-computation batch job built
  from scratch. The design is honest about this dependency, but "the cheap path requires something
  else to ship first" is a real buildability risk that D1 doesn't share. The adjacency-graph
  maintenance (recompute on boundary edit + group status change + monthly refresh) is also new
  operational surface with no existing equivalent to build on.
- **D4 (4/10)**: The lowest score on this criterion, deliberately. Yes, it reuses the same Stage-A
  enforcement point as D1 (`effectiveScheduleTotal`, genuinely "no change needed" per its own
  implementation sketch item 5) and reactivates the same dead `RippleTuneService` scaffold. But
  everything else is new, substantial engineering: a permanent 5% holdout requiring a new
  `rippling_reach_holdout` table and new routing logic in `ExpandService::advanceDue`/
  `initialiseNew`; a wholly new guard-rail/gatekeeper service (explicitly "no existing scaffold to
  build on"); nightly empirical-Bayes shrinkage estimation logic that doesn't exist in any form
  today; and the already-scoped reach experiment being repurposed from a bounded 3-week study into
  permanent infrastructure (a scope change with its own approval implications). The design's own
  §9.6 concedes "materially more operational surface area... than a constant." This is by far the
  largest actual build for a "design only, read-only sampling encouraged" brief whose stated
  purpose was to find a way to set ONE parameter — D4 answers a more ambitious question (build a
  permanent control system) than the one asked (derive a value), and an engineering-ops reviewer
  should weight that mismatch heavily.

### C8 — Scientific honesty (exposure-censoring / chicken-and-egg problem)

- **D1 (6/10)**: Acknowledges the deliverability feedback loop as an "unresolved" inherited risk
  and is honest that reply-rate-as-target is itself susceptible to confounds (post quality,
  category, seasonality) it doesn't model. But D1's core derivation (Demand §6 within-tercile
  analysis) is explicitly checked and shown NOT to be explained by the exposure confound — a
  genuine, credited strength — which is why it isn't scored lower. It does not, however, name or
  mechanically defend against a *tightening spiral* the way D2 does, because D1's mechanism
  (audience-count target) is less structurally exposed to that specific failure mode than D2's
  (percentile-of-censored-data) — so there is less for D1 to need to defend on this exact axis, but
  correspondingly it also gets less credit for defending it.
- **D2 (9/10)**: This is D2's standout strength and the reason it doesn't lose on this criterion
  despite being weaker elsewhere. The censoring problem is not just named, it is mechanically
  defended on four independent fronts: (a) using the less-censored pre-rippling baseline as primary
  input, with direct measured evidence the censoring effect is real (P99 shrank 55.8km→36.3km
  pre/post); (b) explicitly documenting the resulting P90 as a floor, not a demand ceiling, in both
  internal docs AND mod-facing copy; (c) a fully specified swap-in plan for the reach experiment's
  uncensored data the moment it reports; (d) a *computed*, not just named, guardrail (15%/period cap
  + 90%-collection-catch backtest) against the self-reinforcing spiral. This is the most rigorous,
  concrete treatment of the chicken-and-egg problem among the four designs — it doesn't just say
  "this is a risk," it builds the mechanical defense into the rule itself.
- **D3 (6/10)**: Honestly reports that its primary formulation doesn't work — a high-integrity
  finding that deserves credit under "scientific honesty" broadly. But D3 doesn't really engage with
  exposure-censoring as a *statistical* problem at all, because its shipped mechanism (adjacent-ring)
  is deliberately non-statistical/purely geometric — this sidesteps censoring rather than solving it,
  which is a legitimate design choice (named as a strength in D3's own §3.3) but means the design
  earns fewer points specifically for *handling the censoring problem credibly*, since it mostly
  opts out of the question by construction.
- **D4 (7/10)**: The permanent holdout (ADD design) is the only one of the four mechanisms that
  actually generates genuinely *uncensored* causal data going forward, rather than working around
  censored historical data — structurally the most rigorous long-run answer to the chicken-and-egg
  problem. But at launch, tick-1 (70% of the audience) is explicitly conceded to be governed by a
  feed-forward prior with "zero holdout signal" for that specific post — so the design's headline
  claim of solving censoring is real for the trailing 30% of audience but structurally weaker for
  the dominant burst, a limitation the design states honestly (crediting it here) but that measurably
  caps how much of the actual censoring problem gets solved by launch day.

---

## Which design would I ship, and why

**Ship D1 (Audience Normalization).** Through the engineering-operations-and-safety lens
specifically — not "which is the most elegant idea" but "which one do I want to be woken up for at
3am, and which one can I actually get through a change-review board this quarter" — D1 wins on the
criteria that matter most for that judgment: buildability (9/10, the only design that is close to
"flip an already-coded, already-unit-tested flag with a calibrated number"), a genuinely principled
central derivation with the exposure confound explicitly ruled out (the single strongest empirical
result in the whole evidence base), and a risk profile that adds essentially zero new operational
surface area (no new schema, no new services, no new experiment infrastructure, reuses a dead
scaffold rather than building parallel machinery). D2 scores marginally higher on raw honesty about
censoring and is a very close second — if D1's k=1.0 derivation were shown at Stage 0 dark-compute
to not hold up, D2 would be my fallback pick, precisely because its tightening-spiral defense is the
most mechanically rigorous of the four. D3's core weakness (the assigned formulation demonstrably
doesn't solve the stated problem, and the design pivots mid-flight to a different rule) makes it hard
to fully trust the rest of its derivation chain, even though its explainability and cold-start
properties are genuinely best-in-class. D4 is the most intellectually ambitious and arguably the
"correct" long-run answer, but it answers a bigger question than was asked, triples the honest
constant-count while claiming to eliminate constants, and stacks three novel safety-critical
mechanisms (holdout routing, guard-rail service, permanent GDPR-relevant experiment) onto a live
user-notification pipeline for a first release — that is a legitimate v2/v3 evolution once D1 (or
D2) has run safely in prod for a couple of quarters and proven the underlying data pipeline is
trustworthy, not a safe v1.

## One must-graft idea from each losing design, into D1

- **From D2**: Graft the **mechanical anti-circularity guardrails** — a hard per-period
  rate-of-change cap (D2's 15%) plus a live backtest that any tightening must still capture ≥90% of
  what actually happened last period. D1's own re-fit process (§5) currently only has a soft "if k
  falls outside a sane band, halt and review" trigger; it should adopt D2's concrete, computed,
  automatic guardrail rather than relying on a human noticing an out-of-band k after the fact.

- **From D3**: Graft the **mod-facing catchment-map co-location principle** — show the mod-facing
  N*/reach number on the same visual (a map showing the reached area and, ideally, the comparison
  group of "how many members like this reach represents") that any group-settings/catchment UI
  already displays, rather than only a prose explanation. D3's single best property is that its
  parameter and its explanation are provably the same fact shown in two places; D1's audience-count
  explanation is currently pure prose, which is a materially weaker trust story for the same
  hostile-mod audience that resigned moderators over exactly this kind of "trust me" framing.

- **From D4**: Graft the **causal-lift (holdout-based) calibration signal**, in a minimal,
  low-risk form, as the input to D1's quarterly re-fit — instead of re-fitting k purely from raw
  reply-rate-vs-audience curves (which D1 itself admits conflates "would have replied via browse
  anyway" with "replied because notified," and is explicitly named as vulnerable to the
  deliverability feedback loop), reuse the already-scoped, bounded, one-off reach experiment (not
  D4's *permanent* 5%-forever holdout) to get one clean causal-lift measurement per density band and
  use that measurement to sanity-check/adjust k at the next quarterly re-fit. This gets most of
  D4's scientific rigor on the specific point where D1 is weakest, without adopting D4's much larger,
  permanent, GDPR-exposed operational commitment.
