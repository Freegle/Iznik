# Judge 1 — Product & Moderator-Trust Lens

Scoring the four rippling-reach designs (D1 audience-target, D2 behavioural-percentile,
D3 community-relative, D4 adaptive-control) against the fixed criteria, read through the lens of:
**would a real, skeptical, already-burned Freegle moderator population trust this, would it visibly
fix the complaint they raised, and does it survive contact with the actual Discourse thread?**

Grounding fact that dominates this lens (from `2-discourse.md`, confirmed by direct re-read): **no
moderator proposed a data-derived/self-calibrating mechanism.** Every in-thread ask was either a
manual dial (rejected per-group slider, shipped per-member slider, requested per-post flag `#397`) or
a timing tweak. Two experienced moderators resigned mid-thread over rippling; two more seriously
considered it; the Board Chair personally intervened repeatedly. The evidence base explicitly warns:
"a fully-automatic invisible parameter, however well-derived, risks re-triggering the 'we're not being
listened to / not in control' complaint... unless it comes with a visible, explainable 'why this
number' artifact per area." That sentence is close to the whole ballgame for this lens.

---

## Scoring matrix (1-10 per criterion)

| # | Criterion | D1 Audience-target | D2 Behavioural-percentile | D3 Community-relative | D4 Adaptive-control |
|---|---|---|---|---|---|
| C1 | Fixes dense-area complaint | 8 | 8 | 7 | 8 |
| C2 | Preserves rural utility | 9 | 8 | 9 | 8 |
| C3 | Principled derivation | 8 | 7 | 6 | 5 |
| C4 | Explainability & mod trust | 7 | 8 | 9 | 4 |
| C5 | Self-maintenance | 7 | 7 | 8 | 8 |
| C6 | Robustness (edge cases) | 6 | 6 | 6 | 7 |
| C7 | Buildability & fit | 9 | 7 | 8 | 5 |
| C8 | Scientific honesty | 9 | 9 | 8 | 8 |
| | **Total (/80)** | **63** | **60** | **61** | **53** |

### Rationale, one sentence each

**C1 — Fixes the dense-area complaint**
- D1 (8): Tower Hamlets self-gates from 13,336→~3,469 audience and inner-London N* caps at 4,000 vs today's 12,500+, a direct ~3x cut matching #248/#250 verbatim.
- D2 (8): Worked examples show TowerHamlets/inner-London ~30min→~15min, roughly halving the ceiling and directly answering "an hour's drive each way."
- D3 (7): Only the ring-formulation (not the brief's own α×span formula, which the design admits fails to bind) gets inner-London to ~15-18min — real but the design's own honesty that the literal assigned rule doesn't work costs a point of confidence.
- D4 (8): Densest decile is explicitly targeted first and the marginal-benefit rule mechanically produces the same ~1,000-2,000-member outcome as D1, so the numeric fix is comparable.

**C2 — Preserves rural utility**
- D1 (9): Provably inert for Hull/Swindon/Ribble Valley since they never reach even N*=1,000-2,000 — floor/ceiling-bound exactly as today, by construction.
- D2 (8): Sparse bands stay near 28-30min in worked examples, correctly un-penalised, though the P90-of-censored-data mechanism could in principle tighten a thin rural band via noise before the guardrail catches it.
- D3 (9): Ring formulation gives rural groups long unions of large sparse neighbours, clamping at 30min identically to today, and is the only design with literally zero dependency on post/reply history so it cannot mis-fire on thin rural data.
- D4 (8): Sparse strata are explicitly shown to be "almost always keep going to T_ceiling" under the marginal-cost rule, matching today, but this rests on an estimated (not geometric) property so carries slightly more tail risk than D1/D3.

**C3 — Principled derivation (no arbitrary constants)**
- D1 (8): k=1.0 is triangulated from three independent evidence lines with the strongest single result in the whole base (within-tercile reply peak, confound explicitly ruled out) as primary; honestly flags k rests on only two clean data points.
- D2 (7): P90 is explicitly conceded to be "a defensible convention, not a provably optimal cutoff" since decay is smooth with no elbow — principled procedure, arbitrary percentile choice, and the design says so.
- D3 (6): α=2.0 is evidence-triangulated but the design's own worked examples prove the literal brief formula doesn't work in the complaint geography, forcing a pivot to a different (ring) rule not directly derived from the same α-evidence — a real derivation gap, honestly flagged as "designer improvisation" risk.
- D4 (5): θ=1 and the V-weights are conceded in the design's own strongest self-critique to be "back-solved to roughly reproduce the answer the evidence already suggested" — the most honest design about this, but also the one with the least defensible claim to eliminating arbitrary constants, only relocating them.

**C4 — Explainability & mod trust (survives the flame-war audience)**
- D1 (7): "Equal audience, not equal time" is a clean one-sentence pitch and directly rebuts the Swindon-vs-Islington framing, but a raw people-count is one level more abstract than what mods actually said in the thread (which was almost entirely in minutes and place-names).
- D2 (8): Anchored on "9 in 10 of the collections that already happen here" — the mod's own transaction history, which the Discourse evidence itself says mods respond to best ("concrete, locally-verifiable claims") — and stays in minutes, the vocabulary mods actually used.
- D3 (9): Uses the mods' own vocabulary almost verbatim (Jax's "near me first," Group-Mod-J's "over the water," Jos's "crosses the river") and — uniquely among the four — the parameter and the mod-facing catchment-map number become the literal same computed value via backlog #21/#22, closing the explainability requirement by construction, not by copy-writing.
- D4 (4): This is the design's own admitted Achilles heel against exactly this lens — nightly empirical-Bayes shrinkage over a permanent causal holdout is genuinely hard to explain honestly to a self-declared non-technical population that already distrusts "algorithm decided" framing, and the mod-facing copy deliberately hides the internals, which risks reading as evasive to the exact skeptical audience that produced two resignations.

**C5 — Self-maintenance (stays correct as behaviour/actives drift)**
- D1 (7): Quarterly re-fit of k per density band is specified and reactivates the dead RippleTuneService scaffold, but a quarterly cadence is slow relative to D4's nightly loop and the design flags its own circularity/deliverability-feedback risk as unresolved.
- D2 (7): Monthly recompute with a live 15%-tightening clamp and collection-catch backtest is a genuinely mechanical anti-drift design, though it inherits a "does the backtest itself degrade as tightening continues" second-order weakness the design names honestly.
- D3 (8): Because span/adjacency are re-derived geometric facts (road graph + polygon), not fitted statistics, "is it still correct" reduces to "is the map still accurate" — a genuinely easier and more robust self-maintenance question than a calibrated demand curve, its strongest property.
- D4 (8): Nightly re-estimation with shrinkage is the fastest-reacting and most literally "continuously correct" of the four by design intent, but the added operational surface (holdout routing, guard-rail service) is itself a new thing that can silently drift or break, which the design admits is a real, not hypothetical, risk (citing the 56%-backfill-contamination precedent against itself).

**C6 — Robustness (coastal, tiny groups, overlaps, gaming, cold start, TN)**
- D1 (6): Handles coastal/seasonal well structurally, but is explicitly gameable via active-member inflation (mitigated only slowly via re-fit) and has a genuinely unresolved TrashNothing gap the design itself flags as unmeasured.
- D2 (6): Coastal handled correctly (drive-time primitive), gerrymandering essentially un-gameable (disc-based density), but cold-start relies on an external DfT anchor with no Freegle-specific local signal and the design's own P90-of-censored-data is structurally the most exposed to the central chicken-and-egg failure mode of any design.
- D3 (6): Cold-start is this design's best property (zero dependency on post history, works day one for a brand-new Highlands group) and coastal is mitigated by construction via routing-graph use, but the α-formulation is honestly conceded gameable by boundary-redrawing, and adjacency-graph maintenance is flagged as more operationally fragile than a global constant.
- D4 (7): Stratifying on post-level realized audience (not group polygon) sidesteps both the coastal-boundary artefact and gerrymandering more cleanly than any other design, and empirical-Bayes shrinkage is a textbook-correct cold-start answer, but this is also the design with by far the most moving parts (nightly batch, holdout bookkeeping, guard-rail service) so has more distinct places to silently fail.

**C7 — Buildability & fit (leverages existing schedule/governor MVP/experiment; realistic increment)**
- D1 (9): Is explicitly "adopt and complete" the already-coded, already-unit-tested Stage-A extent governor — the smallest realistic increment of the four, reusing `cumulative_users` in `rippling_reach.schedule` with zero schema change and reactivating the dead RippleTuneService scaffold for real work.
- D2 (7): Extends the existing drive-time isochoring mechanism cleanly (only the ceiling constant becomes banded) and reuses RIPPLE_WITHIN_GROUPS, but needs a new density-disc computation pipeline and a new monthly backtest job not currently scaffolded anywhere.
- D3 (8): Needs zero schema change (`max_minutes` is already a per-request parameter) and reuses the exact polyindex-intersection primitive ExpandService already computes for cross-posting, but has a real hard dependency on an unbuilt UI primitive (#21/#22 widest-span) for its cheapest implementation path.
- D4 (5): Most ambitious increment by far — one new schema table, a new guard-rail gatekeeper service, permanent holdout-routing logic layered into `ExpandService`, and turns a scoped 3-week experiment into permanent infrastructure with an unresolved GDPR/LIA question — realistic only as a second-generation project, not a next ship.

**C8 — Scientific honesty (handles exposure-censoring/chicken-and-egg credibly)**
- D1 (9): Explicitly rules out the exposure confound via within-density-tercile stratification (the strongest result in the evidence base) rather than assuming it away, and separately names the deliverability-feedback-loop risk as unsolved and inherited, not hidden.
- D2 (9): This is D2's entire structural centerpiece — pre-rippling baseline as primary input specifically because it's less censored, explicit floor-not-ceiling framing in both internal docs and mod copy, a mechanical 15%-clamp + backtest guardrail against the tightening spiral, and a named full-swap-in plan once the reach experiment reports; arguably the most mechanically rigorous treatment of the censoring problem of the four.
- D3 (8): Names the same "floor not ceiling" caveat and defers to the reach experiment for re-validation rather than re-derivation, and is honest that its own literal brief-formula doesn't work — but as a purely geometric design it has comparatively less to be honest about on the censoring axis specifically, since geometry isn't itself censored the way behaviour data is.
- D4 (8): Names its own chicken-and-egg problem sharply (θ=1 "back-solved," a hostile reviewer could fairly say "you've just hidden the slider inside an algorithm") and the causal-holdout mechanism is the only one of the four that structurally, not just retrospectively, de-censors demand by design — but this honesty is undercut by the sheer number of meta-parameters doing quiet work.

---

## Recommendation

**Ship D1 (Audience Normalization).**

Through the product-and-moderator-trust lens specifically, D1 wins not because it is the most
sophisticated (D4 is) or the most rhetorically native to the mods' own words (D3 is), but because it
is the design most likely to actually **ship and hold** against this specific, already-wounded
moderator population: it completes machinery that is already coded, tested, and dark rather than
proposing new operational surface area at exactly the moment two moderators have already resigned and
trust in "the algorithm decided" is at its lowest; its one-sentence pitch ("equal audience, not equal
time") is a direct, falsifiable rebuttal to the single most-quoted complaint in the thread (#248,
Swindon-vs-Islington); and unlike D4 it does not ask the org to explain a permanent experimental
holdout or a shrinkage estimator to a population the evidence base itself says is not statistically
sophisticated and already suspicious of invisible automation. D2 is a very close second on pure
scientific rigor (its censoring-handling is arguably the best-engineered of the four) and D3 is the
single most emotionally resonant with the actual words mods used — but D1's combination of minimal
build risk, the strongest single evidence result in the whole base as its primary derivation, and a
mod-facing story that pre-empts both live objection classes ("why does distance differ" and "why can't
I set my own number") makes it the safest bet to actually survive a second Discourse thread.

The decisive product-trust risk this recommendation must carry forward explicitly: the evidence base
states flatly that **no moderator asked for a data-derived mechanism at all** — they want a visible
dial. D1's rollout must therefore not stop at "the number is derived" copy; it needs the same kind of
visible, per-group "why this number" artifact D3 gets for free from the catchment-map UI (backlog
#21/#22). That is precisely the graft below.

## Grafts — one must-have idea from each losing design, into D1

- **From D2**: adopt the **collection-catch backtest guardrail** (before shrinking any group's N* at
  a quarterly re-fit, mechanically verify the proposed new value would still have captured ≥90% of
  that group's actual recent collections) — D1's quarterly re-fit currently only checks reply-rate
  drift, and D2's concrete, computed anti-tightening-spiral check is a stronger, more auditable
  guardrail against the same circularity risk D1 admits it inherits from the MVP plan.

- **From D3**: **surface N* and the resulting drive-time on the same moderator catchment-map UI
  already in the backlog (#21/#22)**, so "why did my group's reach change" is answered by a picture
  the mod can see and verify against their own area, not just an in-product sentence — this is D3's
  single strongest property (one number, shown in two places, always consistent) and directly
  addresses the Discourse evidence's core warning that an invisible, well-derived parameter still
  risks the "we're not being listened to" complaint unless paired with a visible artifact.

- **From D4**: adopt the **feed-forward/closed-loop split as an explicit design acknowledgment**, not
  necessarily the full permanent-holdout machinery — D1 should state plainly (as D4 does) that ~70% of
  audience is committed by tick 1 on a periodically-refreshed prior (quarterly k), and only frame the
  remaining ticks as responsive to a specific post's own early signal if/when D1 is extended to do so;
  this prevents D1's mod-facing copy from overclaiming real-time responsiveness it doesn't actually
  have, which is exactly the kind of gap a technically literate hostile reviewer (the same population
  that already caught the "45 min" figure being aspirational-not-live) would find and use to undermine
  trust in the "re-measured every three months" framing.
