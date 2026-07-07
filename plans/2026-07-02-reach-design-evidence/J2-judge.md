# Judge 2 — Statistical Validity & Causal Inference Review

Lens: is the derivation of the tuning parameter actually supported by the evidence claimed for
it — sample sizes, confounding, censoring/selection bias, identification strategy, stability of
estimates, and honesty about uncertainty? I am not scoring "is this a good product idea" in the
abstract; I am scoring whether the *statistical reasoning underneath each rule* would survive a
methods review.

Reviewed in full: D1-audience-target.md, D2-behavioural-percentile.md, D3-community-relative.md,
D4-adaptive-control.md, plus the demand (5-demand.md §6 in particular — the within-density-tercile
table), behaviour (3-behaviour.md), audience-curves (4-audience-curves.md) and mechanics
(1-mechanics.md) evidence files.

---

## Scoring matrix (1-10, higher = better)

| Criterion | D1 Audience-target | D2 Behavioural-percentile | D3 Community-relative | D4 Adaptive-control |
|---|---|---|---|---|
| C1 Fixes dense-area complaint | 8 | 8 | 7 (only under the ring reformulation, not the assigned α-rule) | 8 |
| C2 Preserves rural utility | 9 | 8 | 9 | 8 |
| C3 Principled derivation | 8 | 6 | 4 | 5 |
| C4 Explainability & mod trust | 8 | 7 | 8 | 5 |
| C5 Self-maintenance | 6 | 6 | 7 | 7 |
| C6 Robustness (edge cases) | 7 | 6 | 6 | 7 |
| C7 Buildability & fit | 9 | 7 | 6 | 6 |
| C8 Scientific honesty (censoring/chicken-egg) | 8 | 9 | 5 | 8 |
| **Total (/80)** | **63** | **57** | **52** | **54** |

---

## Per-criterion rationale

### C1 — Fixes the dense-area complaint

- **D1 (8).** N* proportional to home group's active members, capped 1,000-4,000, directly binds
  Tower Hamlets/inner-London (audience-curves shows these groups already at 11,000-15,000 —
  clipping to 4,000 is a real ~3x cut) and is demonstrated inert exactly where it should be
  (Hull/Swindon never reach even N*=2,000). The worked-example numbers are read off real
  `rippling_reach.schedule` interpolation, not invented.
- **D2 (8).** Density-banded P90 collapses TowerHamlets from 30min to an estimated ~15min in the
  worked table — a real, large, defensible move. Docked slightly because the worked-example
  drive-times are explicitly flagged as haversine-derived placeholders, not real routing output —
  the actual magnitude of the fix is unverified pending real `iznik-routing-go` numbers.
- **D3 (7).** The document itself proves, with real area data, that the *literal assigned rule*
  (α × own span) does **not** bind in the complaint geography — every London borough clamps at 30
  min regardless. The design only fixes the complaint after silently substituting a different rule
  (adjacent-ring). That substitute rule does work (~15-18 min for inner London), so the *design as
  actually shipped* scores well, but a design that has to abandon its assigned formulation to
  produce a result is a genuine mark against "does the rule as specified solve the problem."
- **D4 (8).** The marginal-benefit stopping rule is calibrated directly against the single
  strongest empirical result in the whole evidence base (dense-tercile P(≥1 reply) halving from
  38.8%→21.8% as audience 1,700→13,000) — this predicts a real, large tightening for exactly the
  complaint population, and the densest-decile-first rollout targets it directly.

### C2 — Preserves rural utility

- **D1 (9).** N* scales with the home group's own active-member count and floors at 1,000; Hull/
  Swindon/Ribble Valley never cross even the floor's implied audience before hitting T_max=30, so
  they are provably untouched — this is shown with real data (audience-curves §3), not asserted.
- **D2 (8).** Density-band P90 for sparse bands lands near 28-30 min in the worked table — correctly
  near-unchanged — but because the underlying P90 estimate for very-low-n groups (Ribble Valley,
  n=3) is explicitly "not estimable with confidence," the sparse-band number rests more on the
  cold-start fallback (DfT 20min anchor) than on a genuine local percentile, which is a slightly
  weaker guarantee of "never starved" than D1's structural floor-and-ceiling logic.
- **D3 (9).** Purely geometric and immune to sample-size problems — a sparse group's ring/span is
  always computable from the road graph on day one, so there's no statistical risk of a data-thin
  estimate for rural rides at all. Strongest of the four on this specific point, methodologically
  (no minimum-n gate is even needed).
- **D4 (8).** Rural strata are shown to be "backstop-bound" (T_ceiling governs, not the
  marginal-benefit rule) because at low absolute audience the marginal cost stays low too — a
  reasonable qualitative argument, but this is asserted rather than derived from a rural-specific
  worked calculation the way D1's Hull/Swindon table is.

### C3 — Principled derivation (no arbitrary constants; every number traces to evidence)

- **D1 (8).** The strongest triangulation of the four: k=1.0 is read directly off the
  within-density-tercile peak (5-demand.md §6), independently cross-checked against the
  behaviour report's coverage curves and the reply-saturation-stop-derived outer ceiling. Genuinely
  three different evidence sources, two of them causally cleaner than anything the other designs
  cite (see C8). Docked because the design itself is candid that k=1.0 rests on only two observed
  tercile peaks (medium, dense) — sparse never shows a decline in range — so the fit, while
  triangulated, is thin at the edges.
- **D2 (6).** P90 is defended as a "standard convention" (retail-geography, "cover the overwhelming
  majority") rather than a value that falls out of a model fit to data the way D1's k does. The
  design is explicit and honest about this ("not a provably optimal cutoff... smooth decay, no
  elbow") — good scientific honesty, but that same honesty is an admission the *derivation* itself
  is weaker: P90 vs P85 vs P95 is not distinguished by any statistical argument in the evidence, it
  is a convention borrowed from an unrelated industry (retail catchment areas) applied by analogy.
- **D3 (4).** Lowest here, for a specific, well-documented reason: the assigned philosophy's formula
  (α × span) is evidence-triangulated to α≈2.0 from three sources, but the design's own worked
  examples then show that formula produces the *wrong answer* (no tightening anywhere in the
  complaint geography) — so the design abandons it for a topologically different, zero-parameter
  rule (adjacent-ring). The ring rule is elegant and honestly presented as "1 ring, no tuning," but
  "exactly 1 ring, not 0.5 or 1.5" is itself an unederived, purely judgment-based choice that the
  document admits ("a genuinely hostile critic could reasonably ask..."). A design that had to
  discard its own core evidence-derived constant to work is weaker on this criterion than one
  whose central constant survives its own worked examples.
- **D4 (5).** The most self-aware about this weakness of any document (§9.1 leads with it): θ=1 and
  the V-weights are "back-solved to roughly reproduce the answer the evidence already suggested"
  — i.e. the design explicitly concedes the meta-parameters were reverse-engineered to hit
  ~1,000-2,000, the same number D1 derives forward from the tercile peak. This is honest, but
  honesty about circularity doesn't earn back the statistical-validity points the circularity
  costs. The *r_hat causal-lift* piece (§1.2, ADD holdout design) is genuinely principled and the
  best piece of causal-inference thinking in any of the four documents (see C8) — but it estimates
  the wrong-side-of-the-equation quantity (marginal benefit of one more notification) while the
  actual stopping point (θ, V, C) remains asserted, so the "principled" label applies to the
  *measurement machinery*, not to the *decision rule* it feeds.

### C4 — Explainability & mod trust

- **D1 (8).** "Equal audience, not equal time" is a genuinely one-sentence, testable, falsifiable
  claim a mod can hold Freegle to, and it directly rebuts both classes of hostile Discourse
  objection (unequal audience for equal time; "why can't I set my own number"). Mod copy is
  concrete and specific.
- **D2 (7).** "Covers 9 in 10 of the collections that already happen here" is concrete and
  locally-verifiable (a mod can, in principle, check this against their own group's history) —
  Discourse evidence says this style of claim lands best with this specific audience. Docked
  slightly relative to D1 because "P90 of collections" is a subtler statistical concept for a
  non-technical mod to sanity-check than "roughly as many active members as your own group has."
- **D3 (8).** The ring formulation ("reach = one community over") is the most intuitively graspable
  of any of the four rules — it maps directly onto mod vocabulary already seen in Discourse
  ("over the water," "crosses the river"), and ties to the same widest-span number the catchment UI
  will show, so mod-facing copy and computed value are provably the same fact. High mark despite
  the C3 weakness — explainability and derivation-purity are different axes, and D3 is strongest on
  the former precisely because it gave up statistical purity for topological simplicity.
- **D4 (5).** The document itself flags this as a serious risk: "a nightly empirical-Bayes
  shrinkage estimator computing a causal lift from a live experimental holdout" is much harder to
  explain honestly than a fixed number tied to local density, and the Discourse evidence shows this
  specific mod population already distrusts "algorithm decided" framing. The mod-facing copy
  (§7) is a reasonable mitigation but explicitly hides the actual mechanism (r_hat, θ, holdout) from
  mods — meaning the "explanation" a mod gets is a plausible-sounding gloss over a system whose real
  logic is not what's described, which is a genuine trust risk if a technically-minded mod digs in
  (as several Discourse participants clearly are capable of doing).

### C5 — Self-maintenance (stays correct as behaviour/actives drift, without hand-tuning)

- **D1 (6).** `active_members` itself updates live (good), but k, N*_min, N*_max only refresh on a
  quarterly re-fit cadence — reasonable, but slower than D2/D4's monthly/nightly cadence, and the
  design's own honest-cons section flags the circularity risk (rippling itself changes membership,
  which changes the input the re-fit uses) as "bounded but real."
- **D2 (6).** Monthly recompute with an explicit anti-spiral guardrail (15%/period clamp +
  collection-catch backtest) is a genuinely well-specified self-maintenance mechanism — mechanically
  concrete, not just "we'll re-run it." Docked because the design is explicit that its core
  calibration data (post-rippling collections) is structurally self-referential with the very cap
  it's setting, and the "fix" (swap in experiment data once available) is a one-time correction, not
  an ongoing solution to the circularity.
- **D3 (7).** The strongest "doesn't go stale" story of the four, for a genuinely different reason
  than the others: because span/adjacency are geometric facts re-derived from the road graph and
  group boundaries, not fitted statistics, "is it still correct" reduces to "is the map still
  accurate" — a much easier operational question with no sampling-noise dimension at all. The
  trade-off (named honestly in the doc) is that it therefore cannot track genuine demand drift.
- **D4 (7).** Nightly re-estimation with empirical-Bayes shrinkage and an explicit anti-oscillation
  rate-of-change cap is the most statistically sophisticated self-maintenance mechanism of the
  four, and correctly identifies seasonal drift (Oxford term-time) as its strongest use case. Not
  higher because operational fragility (nightly batch, holdout routing, a new schema table) is
  itself a maintenance burden the simpler designs don't carry, and the design's own honest-cons
  section flags this explicitly.

### C6 — Robustness (coastal, tiny groups, overlaps, gaming, cold start, TN)

- **D1 (7).** Coastal geometry self-corrects by construction (audience-based, not shape-based) —
  genuine strength. Gerrymandering is only partially mitigated (lastaccess-gated active_members
  raises the bar but doesn't close the loophole; the design admits the re-fit mitigation is
  "slower than a hard rule"). TrashNothing crossover honestly flagged as an unresolved gap, not
  measured — the design does not pretend to have solved it.
- **D2 (6).** Coastal handled correctly via drive-time-not-crow-flies. Gerrymandering is genuinely
  closed (density measured on a fixed 10km disc, ungameable by boundary redraw) — this is a real
  strength over D1 and D3 on this specific point. Cold start (20min DfT anchor) is well-derived.
  Docked because the design's own minimum-sample gate (n≥50) implicitly concedes that a meaningful
  fraction of the network (small/thin groups) will sit on the cold-start default indefinitely if
  volume never crosses the threshold — not clearly bounded in the document.
- **D3 (6).** Coastal handled by construction (drive-time via road graph excludes water). Cold-start
  is this design's best property (zero dependency on post/reply history at all). But gerrymandering
  is honestly conceded as a real, only partially-closed vulnerability under the ring formulation
  (inflating your own polygon does increase the union span, even if the dominant term is neighbours'
  span) — the design's own §3.2 admits this outright. Adjacency-graph fragility (merges, deletions,
  ambiguous "adjacent" cases) is a real, distinct operational risk not shared by the other three.
- **D4 (7).** Coastal and gerrymandering are both well-handled by stratifying on post-level realized
  audience rather than any static geographic or group-polygon proxy — a genuinely clean solution to
  both failure modes simultaneously, better than D1/D3's partial mitigations. Cold start via
  empirical-Bayes shrinkage is textbook-correct. Docked for the frankly-acknowledged deliverability
  feedback-trap risk (§6.2, Risk 2) which is a real, only partially-guarded, self-reinforcing
  failure mode unique to a closed-loop design, plus materially higher overall operational surface
  area for a silent-corruption failure (explicitly analogized to the real 56%-backfill-contamination
  incident already observed in this evidence base).

### C7 — Buildability & fit (leverages schedule/governor MVP/experiment; realistic increment)

- **D1 (9).** The cleanest fit of the four: this is explicitly "supply the missing derivation for
  the already-coded, currently-dark Stage-A extent governor" — zero new mechanism, only a formula
  for a parameter the code already reads. Reactivates the RippleTuneService/rippling_params stub
  with a concretely specified computation, not a vague "make it smarter."
- **D2 (7).** Extends (doesn't replace) the existing isochroning mechanism — a real, modest
  increment (density-banded ceiling instead of one flat constant) — but does not reuse the
  cumulative_users/schedule audience data that's already persisted, and doesn't touch the
  already-half-built extent governor at all, so it's a partially-separate piece of new
  infrastructure (new density-disc computation, new monthly job) rather than a direct completion of
  existing dark code.
- **D3 (6).** Needs zero schema change (`max_minutes` already a per-request parameter) — genuinely
  cheap to wire. But it has a hard, named external dependency (the not-yet-built widest-span
  UI primitive, backlog #21/#22) for its cheapest computation path, and its fallback path (per-group
  Dijkstra + adjacency-graph maintenance) is new machinery the pipeline doesn't have today, unlike
  D1's reuse of already-coded audience-budget plumbing.
- **D4 (6).** Correctly identifies and reuses the most existing scaffold of any design (extent-
  governor Stage-A cap, RippleTuneService stub, RIPPLE_WITHIN_GROUPS scoping) — strong marks for
  recognizing what's already built. But it also requires materially new infrastructure the others
  don't (permanent 5% holdout routing, a new schema table for holdout bookkeeping, a new guard-rail
  gatekeeper service) — the document's own honest-cons section (§9.6) concedes this is "materially
  more operational surface area" than a constant-based design, which is a legitimate buildability
  cost, not just a risk.

### C8 — Scientific honesty (handles the exposure-censoring/chicken-and-egg problem credibly)

- **D1 (8).** This is where D1's core evidence is actually strongest on causal grounds, and it is
  under-sold in the design's own self-description: the within-density-tercile analysis
  (5-demand.md §6) is a genuine confound-control exercise — it explicitly tests and rules out
  "audience size is just a proxy for density" by holding density (tercile) fixed and varying
  audience (quintile) within it, finding the decline survives. That is real, if simple, causal
  reasoning (a stratified analysis, not a randomized experiment, so still correlational — density
  terciles could still correlate with unmeasured post-quality or category-mix differences — but
  meaningfully stronger than a raw marginal correlation). The design is honest that this whole
  edifice sits on only 10 days of post-launch data and that "reply-rate-as-target" is itself
  susceptible to a flagged deliverability feedback loop it does not solve. Docked one point
  relative to D2/D4 because the design does not explicitly reckon with censoring in the sense D2
  does (today's *audience* itself is capped at 30min reach, so the tercile-quintile table is itself
  measured within an exposure-limited population — a post that "would" have reached 20,000 members
  absent T_max never appears in decile 10 at all, so the observed decline past ~2,000-13,000 could
  in principle be curvature within an already-truncated range rather than the true global shape).
  This is a real, unaddressed gap in D1's evidentiary chain that D2 names explicitly for its own
  data and D1 does not name for its own (structurally identical) data.
- **D2 (9).** Highest score on this specific criterion, deservedly — this is the one design whose
  entire raison d'être is naming and mechanically defusing the censoring problem: it explicitly
  states the P90 is a *conservative floor, not a demand ceiling* (matching the behaviour report's
  own central caveat verbatim), uses the *pre-rippling* baseline as a less-censored primary input
  (a genuinely clever identification move — pre-ripple, the binding constraint was group-polygon
  geography, not a drive-time isochore, so for large-polygon sparse groups it's meaningfully less
  truncated), cites direct evidence the censoring is real and operating (P99 shrank 55.8km→36.3km
  pre/post), and builds a *mechanical* anti-spiral guardrail (15%/period clamp + 90%-catch backtest)
  rather than just a promise to "keep an eye on it." It is the only design to specify exactly how
  it will be superseded once the reach experiment's uncensored data lands (full input-distribution
  swap-in, same rule structure) — a genuinely testable, falsifiable transition plan.
- **D3 (5).** The design does not really engage with censoring as a statistical problem at all —
  its calibration inputs (behaviour report's 90-95%-coverage-distance and reply-distance-decay
  figures) are the *same* censored, exposure-limited data D1 and D2 use, but D3 does not name or
  discuss the censoring issue anywhere in its own text (§1.2's α-derivation table cites the
  coverage-capture distances without flagging that those distances are themselves capped by
  existing 30-min reach). This is a real gap: D3's own honest-cons section (item 2) admits "no
  connection to real demand/engagement signal at all" as a weakness, but frames it purely as "can't
  detect under/oversaturation," not as "the geometric constant itself was calibrated on
  exposure-truncated behavioural data" — the latter is the more fundamental issue and it's absent
  from the document.
- **D4 (8).** The second-strongest treatment of this problem, via a genuinely different and
  arguably more rigorous mechanism than D1/D2: rather than trying to de-censor observational data
  after the fact, D4 proposes to *make the already-scoped randomized ADD experiment permanent
  infrastructure* — i.e. solve censoring by generating genuinely uncensored (holdout vs treated)
  data forever, rather than reasoning around a censored historical sample. This is the only design
  of the four with an actual identification strategy for causal lift (r_hat = treated-conversion
  minus holdout-conversion) rather than an observational proxy. Docked one point below D2 because:
  (a) the *decision rule itself* (θ, V, C) is not derived from the holdout, only r_hat is, so the
  causal-inference rigor doesn't extend all the way to the stopping point (see C3); (b) the
  document's own §9.1 admits the meta-parameters were partly back-solved to match pre-existing
  observational conclusions, which is a form of the same circularity D2 is more careful to avoid
  (D2's guardrails are computed thresholds, not fitted-to-match-the-answer weights).

---

## Which design would I ship

**Ship D1 (Audience Normalization), total 63/80.**

From a statistical-validity/causal-inference standpoint, D1 wins for a specific, defensible
reason that the other three don't match: its central empirical claim (the audience level at which
reply probability peaks scales with a group's own active-member base) is the only claim in any of
the four documents that has actually been tested against a real, if simple, confound-control
design — stratify by density tercile, then vary audience within each stratum. That is meaningfully
different from D2's percentile-of-observational-data approach (no confound control, honestly named
as a floor not a ceiling) and categorically different from D3's purely geometric constant (no
attempt to connect to outcome data, confound or otherwise) and from D4's back-solved meta-parameters
(admittedly circular per its own §9.1). D1 also has the best fit-to-existing-infrastructure story
(C7) of the four, which matters for actually getting a principled number into production rather than
staying a design document.

D1's real, unresolved weakness on this lens is the same one D2 is honest about and D1 is not: both
designs' calibration data is exposure-censored (today's realized audiences are capped by the
existing 30-min ceiling), so neither can distinguish "reply probability truly peaks near 1x active
members" from "reply probability appears to peak because we've only ever observed a truncated
range of audiences." D2's percentile design names this explicitly and builds a mechanical
anti-spiral guardrail; D1 does not name it for its own tercile-quintile analysis at all. That is
D1's most serious statistical gap and the reason it should not claim more certainty than D2 does
about "the peak" being a true global optimum rather than an artifact of truncation. D4's permanent-
holdout mechanism is the only one of the four that actually resolves this by generating unbiased
data going forward rather than reasoning around biased data retrospectively — which is why D1
should not be shipped as a static, one-time-calibrated rule; it should be shipped with an explicit
commitment to validate its k against the reach experiment's (or a permanent holdout's) uncensored
data once available, exactly as D2 already specifies for its own P90.

## Grafts — one must-have idea from each losing design, into D1

- **From D2 (behavioural-percentile):** graft the **explicit censoring-honesty apparatus** —
  (a) name the exposure-censoring problem for D1's own tercile-quintile evidence, not just for D2's;
  (b) adopt D2's mechanical anti-spiral guardrail pattern (a hard per-period % cap on how far a
  re-fit can move k, plus a "would this k have still captured ≥90% of last quarter's actual replies"
  backtest) for D1's quarterly re-fit, replacing the current soft "re-fit uses reply outcomes" story
  with a computed, falsifiable check; (c) explicitly commit, as D2 does, to swap in the reach
  experiment's (or D4's permanent-holdout) uncensored data the moment it exists, with a pre-specified
  procedure for how k changes if the uncensored peak differs materially from the censored one.

- **From D3 (community-relative):** graft the **ring-formulation's mod-load side effect** — D1
  correctly limits *audience*, but the evidence base is explicit that mod-load is driven by
  *group-count* under the current "ripple into every intersecting group" engine, which D1 alone does
  not touch. D3's adjacent-ring idea (bound reach to the union of home group + immediately-bordering
  published groups) is a natural, near-zero-cost **secondary cap** layered on top of D1's N*: expand
  until `cumulative_users >= N*` OR the reach polygon would cross into a *second* ring of groups,
  whichever binds first. This directly starts to address the group-count/mod-load dimension of the
  complaint that D1's own honest-cons section (§6, con #2) admits it does not fix on its own,
  without taking on D3's full adjacency-graph machinery as the primary lever.

- **From D4 (adaptive-control):** graft the **causal-lift (ADD holdout) measurement of r as the
  input to k's re-fit**, replacing D1's current re-fit design (which infers "audience level where
  reply probability peaks" from raw, uncontrolled conversion rates, i.e. correlational, not causal).
  D4's r_hat = conversion(treated) - conversion(holdout) is the only genuinely causal quantity in
  any of the four documents. D1 does not need D4's full nightly closed-loop machinery, meta-
  parameter apparatus, or permanent 5%-of-every-tick holdout — but it should borrow the *measurement
  primitive*: run D1's quarterly re-fit against a small, scoped holdout (even a periodic 2-week
  experiment rather than D4's permanent always-on version) so that the k re-fit is estimating a
  causal lift, not a raw observational correlation that the D1 document itself (and this review, C8)
  flags as potentially confounded by the very exposure-censoring the design doesn't fully reckon
  with.

---

## One-line summary per design (for cross-judge comparison)

- **D1**: Best-triangulated, best-evidenced, best-fit-to-existing-code; its confound control
  (density-tercile stratification) is real methodology, not just correlation-and-hope — but it
  under-claims its own censoring exposure relative to D2, and doesn't fix mod-load/group-count.
- **D2**: Most scientifically honest treatment of censoring of any of the four (explicit floor-not-
  ceiling framing, mechanical anti-spiral guardrails, pre-registered swap-in plan) — but its central
  constant (P90) is admitted convention, not a fitted optimum, and it doesn't use the audience data
  the pipeline already persists.
- **D3**: Best cold-start/self-maintenance story of the four (pure geometry, no sampling
  uncertainty at all) — but its assigned core formula demonstrably fails on its own worked examples,
  forcing a mid-document pivot to a different rule, and it never engages with the censoring issue
  that the very same behavioural data (which it borrows uncritically) is subject to.
- **D4**: Only design with an actual causal identification strategy (randomized holdout for r_hat)
  — the most rigorous piece of statistics in any document — but the decision threshold built on top
  of that measurement (θ, V, C) is candidly conceded to be back-solved/circular, and the operational
  complexity is the highest of the four by a wide margin.
