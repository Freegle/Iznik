# Adversary 1: The Hostile Moderator — Attack on SYNTHESIS.md

**Posture**: I am Jax, or Jos, or Group-Mod-J, reading this design with the Discourse thread
(#9808) open in the next tab, primed to believe "the algorithm decided" is a fig leaf, and
already burned by post #7's "your feeling is probably wrong" framing. My job is to find where
this design would fail against a real complainant, not to be satisfied by good intentions.

**Method**: every claim in SYNTHESIS.md checked against the six evidence files it cites. Verdicts
are FATAL (design is wrong as written — must change before proposing), MAJOR (must fix before
this goes to a mod or a product owner), or MINOR (note in doc, doesn't block).

---

## FATAL-1: The flagship worked example's key number is not what it says it is

**Claim (§7, first row)**: NW-London Offers has "~12,000-14,000 active [members]", so
`N* = clip(1.0 × 12,000-14,000, 1000, 4000) = 4,000 (capped)`.

**What the evidence actually supports**: SYNTHESIS.md's own caption under the §7 table admits:
"Where a group's exact `active_members` count was not directly pulled in the underlying reports,
the audience-at-ceiling figure is used as a conservative proxy and flagged as an assumption."
For NW-London, the "~12,000-14,000 active" figure in the `active_members` column is **numerically
identical** to the "Today: audience @ 30min ceiling" column two columns over (~12,500-14,800) —
because it *is* that number, relabelled. `total_freeglers` (audience-curves.md §1, the realized
reach-polygon population at 30 minutes) and `active_members(home_group)` (5-demand.md §6, a
membership-table count with a `lastaccess` gate) are **defined as different things by this same
design** — one is "how many people are in the group," the other is "how many people are
reachable within 30 minutes drive of a post from that group." They are not interchangeable, and
for a dense borough group with heavy rippled-in reach they will diverge hard: `total_freeglers`
already includes everyone the *current, uncapped* 30-min rule pulls in from neighbouring groups,
which is exactly the quantity the new rule is trying to shrink. Using it as the input to the
formula that's supposed to *replace* it is circular — it bakes today's "too large" reach into
tomorrow's "principled" target.

**Why this is FATAL, not MINOR**: this is not a random row in the table. It is the flagship
example — the one built to answer post #250, the "Chilterns to East London, an hour's drive
each way" complaint that is the canonical citation for the whole project brief. Every number a
hostile mod would check first (does this actually fix Jax's complaint?) sits on top of a
proxy that was explicitly flagged as an assumption in a footnote, three columns away from where
it's used to compute the load-bearing N* value. A mod who reads the footnote (and Jax, at 55
posts in the thread, reads carefully) will find: "the group's own membership, which is what your
formula claims to key off, was never actually measured for the one example you built to convince
me." That is a credibility hole roughly one Discourse post away from resurfacing as "so it's not
even based on real numbers for the case that started this."

**Concrete check that would catch this**: query `active_members` (memberships,
`collection='Approved'`, `lastaccess >= 90 days`) directly for Ealing/Brent/Hammersmith & Fulham
— the design already names these three as the proxy cluster (§7 caption) and 5-demand.md's own
SQL (§6) is the exact query needed. This is a five-minute SQL query against a table this design
already reads from, not new instrumentation.

**Fix**: before this design is shown to anyone outside engineering, re-run the §7 table with
real `active_members(home_group)` for every named exemplar (Tower Hamlets, Oxford, Swindon, Hull,
Ribble Valley, and the NW-London proxy cluster), not `total_freeglers`-as-proxy. If real
`active_members` for Ealing/Brent/H&F comes back materially lower than 12,000-14,000 (plausible —
`total_freeglers` already includes rippled-in reach from neighbouring dense boroughs, which
inflates it well past a group's own membership), the resulting N* is still 4,000 (ceiling-capped
either way, since anything above 4,000 active members clips to the same value) — so the *final
number* may survive, but the *story* ("N* = a group's own size, not an arbitrary constant")
currently rests on an unmeasured, mislabelled input for its single most important case, and that
must be fixed even if the output number doesn't move.

---

## FATAL-2: k=1.0 is fit on data that never goes near the group this design's flagship
## example is about — this is extrapolation dressed as calibration

**Claim (§2, §3)**: "the demand report's within-density-tercile analysis... rules out the
obvious objection that 'more audience just means more density'... No other design's core number
survives this kind of check." k=1.0 is presented as *the* confound-checked, evidence-backed
number the whole design stands on.

**What the evidence actually supports**: 5-demand.md §6's density terciles are built from real
posts in the current network. The **densest tercile tops out at 1,726-3,469 active members**
(the table's own row label: "3 (1,726-3,469 active, densest)"). Tower Hamlets, at 3,469 active
members, is *literally the top of the calibration range this design's core constant was fit on*.
There is no data point anywhere in the cited evidence for a group with active_members in the
12,000-14,000 range — because (per FATAL-1) no report ever actually measured that number for any
real group; every group the demand report's tercile analysis covers already tops out below
3,500 active members, by construction of the tercile split on the network's actual density
distribution.

This means: for the single case this design was built to fix (NW-London / the 27,000-active-audience
complaint per post #248), k=1.0 is not "confound-checked, not just correlated" — it is
**extrapolated 3-4x past the edge of the only range it was ever checked against**. The
within-tercile finding ("reply probability peaks near ~1x the tercile's own scale, declines past
it") was demonstrated on groups scaling up to ~3,469 active members. Whether the same 1.0x
relationship holds, over-predicts, or under-predicts for a hypothetical ~12,000-14,000-active
group is **not evidence the report contains** — it's an assumption the design makes silently by
applying the same formula uniformly.

**Why FATAL not MAJOR**: §2's own selling point — "the only design whose central claim was
confound-checked, not just correlated" — is the design's single strongest argument for beating
D2/D3/D4. If the flagship application of that claim is actually an extrapolation past the fitted
range, the design's core differentiator doesn't survive contact with its own headline example.
A statistically literate hostile reader (Judge 2's own objection pattern: "the observed 'peak'
could be curvature within a truncated range") will find this in about the time it takes to
compare the tercile boundaries against the worked example — the same five minutes as FATAL-1,
and for the same underlying reason (no real ultra-dense group has ever been measured).

**Fix**: (a) explicitly and separately verify whether any group in the network — even outside
the reach dataset used for the tercile split, e.g. a single dense London borough sampled on its
own — has active_members materially above 3,469, and if so, whether its post-level reply-vs-audience
curve looks like a continuation of the tercile-3 trend or something different (a plateau,
a further decline, a discontinuity). This is the honest version of Stage 0's re-validation sweep,
and should explicitly include "does k=1.0 hold above the fitted range" as a named check, not just
"sweep k over {0.5...1.5} on the same data" (which re-tests the fit, not the extrapolation). (b)
Until that check exists, the design should state plainly that **for the single highest-stakes
case in the whole document, N* is a capped value (4,000, hitting `N*_max`) precisely because k
was never measured there** — the ceiling is doing the work of masking an untested extrapolation,
which is a materially different and much weaker claim than "your own group's size, scaled by an
evidence-backed multiplier."

---

## MAJOR-1: The anti-spiral collection-catch backtest protects the wrong transition

**Claim (§4, §9's "self-reinforcing tightening spiral" row)**: the ≥90% collection-catch
backtest is presented as the mechanism that stops N* from spiralling down to something that
misses real collectors — directly answering the mechanics report's own explicit prior finding
(§6 of 1-mechanics.md, listed as a decision "already made, do not relitigate"): "**A people-count
cap that DECREASES with density is wrong** — the taker-distance validation... refutes it:
dense-area real takers rank ~1,719th nearest active member (mean 5,126th), so a tight cap
(1,500) misses 54% of real collectors."

**What the evidence actually supports**: read §4 and §11 of SYNTHESIS.md carefully — the
backtest is gated on *quarterly re-fits*, and specifically only "before any re-fit is allowed to
**shrink** N\* for a density band." It is never run against the **initial** move from today's
uncapped ~30-min-ceiling reach down to the newly-derived N* at rollout (Stage 1/2, §10). But the
single biggest cut in the entire design **is** that initial move — Tower Hamlets goes from
13,336 (today, at its already-gated 17.8min) to 3,469 under N*, and the NW-London proxy cluster
goes from ~12,500-14,800 to 4,000 — both far bigger single-step cuts than any conceivable
15%-per-quarter drift the anti-spiral cap is designed to catch. The mechanics report's own cited
validation — the exact statistic ("1,500 cap misses 54% of real collectors", taker rank
~1,719th/mean 5,126th nearest active member) — is never actually run against the proposed
N*=3,469-4,000 values for these specific dense groups anywhere in this evidence base. The
closest thing offered is the *reply*-rate confound check (5-demand.md §6), which is a different
outcome variable from *collection* (Taken/Received) — and 5-demand.md §2 explicitly flags its own
Taken-outcome analysis as underpowered (n=429, "should not be treated as more than directionally
consistent... re-run after 2026-07-15+").

**Why MAJOR not FATAL**: the mechanism (collection-catch backtest) is sound in principle and
correctly identified by all three judges as the right kind of guardrail — it just isn't wired to
fire at the one moment (initial rollout) where the mechanics report's own prior warning about
density-decreasing caps is most directly on point. This is fixable without redesigning anything.

**Fix**: run the collection-catch backtest **before Stage 1 begins**, not just at each quarterly
re-fit — replay the actual taker-distance data (the exact methodology already in
1-mechanics.md §6 and 6-external-anchors.md §1/§3: taker rank vs cap, % of real collections
captured at a given cap) against the proposed N*=3,469 (Tower Hamlets) and N*=4,000 (NW-London
cluster) specifically, using real taker-rank-among-active-members data, not the reply-rate proxy.
If real dense-area takers cluster mostly under rank ~4,000 (plausible, given the cited "1,500
misses 54%, E*=5→~7,015 active misses only 19%" finding implies the true collector distribution
has a long tail well past 4,000), this initial-rollout backtest could show the flagship N*=4,000
cap itself materially undershoots real collector coverage — which would be a genuinely
inconvenient, load-bearing finding this design currently has no mechanism to surface before
shipping, because the backtest as specified never looks at the rollout transition at all.

---

## MAJOR-2: "It's not a slider" is a half-truth a technically literate mod will unpick

**Claim (§8, mod-facing copy)**: "This number isn't set by us, and it isn't a dial groups can
turn. It's recalculated automatically every three months from how many of your own members are
actually replying to posts at different reach sizes."

**What the design actually contains**: §4 specifies a hard 15%-per-period cap on how much `k`
(and the derived clips) can move, with any larger proposed move **"routed to manual review before
any live change."** §11 step 2 restates: "A re-fit that fails either gate routes to manual
review, not automatic application." §9's failure-mode table for the mod-load-count problem and
§13's open questions ("should the first 2-3 quarterly re-fits use a tighter... cap... given the
acknowledged higher noise") both treat "someone manually decides" as a live, expected pathway,
not a hypothetical.

This means: in any quarter where the data would move `k` (or the min/max clips) by more than 15%,
**a human being decides what number to use** — the same shape of decision a rejected per-group
slider would produce, just gated by a bigger threshold and routed to engineering/product instead
of the group's own moderator. The mod-facing copy's claim "it isn't a dial groups can turn" is
true in the narrow sense (mods themselves don't turn it) but glosses over the fact that *someone*
still turns it by hand whenever the data is noisy or the guardrail trips — which, per honest-con
#5 (10-day-old data, "should be treated as provisional and higher-variance"), is explicitly
expected to be common in year one. A hostile, technically-literate mod (Derek, Jos — both cite
screenshots and specific numbers, not vibes) reading §4/§11/§13 alongside §8's mod copy will spot
the gap: "recalculated automatically" is doing a lot of work to paper over "except when it isn't,
which the design itself expects to happen often at first."

**Why MAJOR**: this isn't a fabrication — the manual-review pathway is a genuinely good safety
mechanism (§4's whole point). But the mod-facing copy in §8 doesn't mention it at all, and this
document has already lived through one trust collapse triggered by an authority figure (#7,
"trust the data") appearing to oversimplify or steamroll moderator concerns (2-discourse.md §3:
"#400 (Sheila): 'Our suggestions are slapped down with quotes of data, that we can't possibly have
fully' — trust-in-data breakdown"). If a mod later discovers via a Discourse thread or a change-log
that "automatic" quarterly re-fits were in fact manually adjusted three times in year one, the
resulting "I told you it was secretly a slider" reaction will land with more force *because* the
copy claimed otherwise, not less — this is a worse trust outcome than being upfront about it from
the start.

**Fix**: rewrite §8's mod copy to say something closer to the truth: "recalculated automatically
from real data every three months, within safety limits; if the data suggests a bigger jump than
usual, that's flagged for a person to check before it goes live — same as any other unusual change
we make." This is a small wording change, it makes the guardrail a selling point (careful, not
capricious) instead of a hidden trapdoor, and it forecloses the specific "gotcha" a Discourse
regular would otherwise eventually find and post about.

---

## MAJOR-3: "Can a mod predict tomorrow's reach?" — no, and the design doesn't say what a mod
## actually needs to predict it

**Claim (§6 graft, §8 mod copy)**: the design is explicit that ~70% of audience is committed by
tick 1 and the number in effect is a "quarterly-refreshed prior, not live" — presented as an
honesty win (which it partly is: at least it isn't overclaiming). But this doesn't actually answer
the hostile question underneath: **given the mod-facing copy, can Jax, reading it, predict what
her Wednesday-morning post will reach?**

**What the design actually supports**: no. Even fully informed, a mod cannot compute N* herself
— `active_members(home_group)` is a live, internal query result (5-demand.md's SQL, not exposed
anywhere in ModTools per the current task list — item #21/#22, "widest span" box, is about
distance/postcodes, not active-member counts or N*). The mod-facing copy (§8) says the boundary
"is shown on the map above" (per the §5 graft), which is the right instinct, but that graft is
explicitly **not yet built** ("awaiting release" — modtools task list items #12-#24, several
still `pending` per this project's own task tracker: #16, #21, #22, #23, #24 open as of this
design). So today, and for however long the Catchment map / N* co-location graft takes to ship,
a mod asking "why does my post reach 32 groups" has **no artifact at all** to point to — §5's
whole graft, the thing all three judges praised as solving exactly this objection, is vaporware
relative to this design's own timeline. The design's Stage 1/2 rollout plan (§10) does not gate
on the map shipping first; §13's open question ("if the map ships materially before this design's
Stage 1... is it better to hold the map release until both can land together?") explicitly leaves
this **unresolved**, i.e. the single graft that answers "can a mod see why" might not exist when
the audience-cap first goes live.

**Why MAJOR**: shipping the audience-cap mechanism (Stage 1 canary) before the explainability
artifact it depends on (§5's graft) reproduces exactly the failure mode 2-discourse.md's
requirements section names as non-negotiable: "Must not re-centralize the 'we have no control'
grievance... unless paired with a visible per-area published number" (2-discourse.md §7,
requirement 3). An invisible N* landing before its visible explanation is the single scenario
this whole design was supposed to avoid, and the open question in §13 currently allows it to
happen by default (silence = no ordering constraint = Stage 1 can proceed independently).

**Fix**: make "the Rippling Explorer / Catchment map + N* overlay ships to mods BEFORE or
simultaneously with Stage 1 canary" a hard sequencing gate in §10, not an open question in §13.
If the map isn't ready, Stage 0 (dark-compute re-validation) can proceed on schedule, but Stage 1
(the first live canary, i.e. the first real mod-visible behaviour change) should not start until
a mod can click through to see their own group's N* and boundary — otherwise this design ships
the exact "invisible algorithm changed my reach and I found out from a complaint, not a screen"
pattern that caused two resignations already.

---

## MAJOR-4: Would the flame war accept the mod-facing text? — testing it against the thread's
## own vocabulary shows a mismatch on the density framing

**Claim (§8)**: "a small rural group and a large London borough both get a fair local audience
for their area, instead of both being forced to travel the same number of minutes down the road
regardless of how many people that covers."

**What the evidence actually supports**: this sentence is designed to answer #248/#250 (Jos,
Neville_Reid — the Swindon-vs-London complaint). But re-read what those posts actually say
(2-discourse.md §2, item 11): the complaint is framed as "*halfway on reach slider* = ~30 min
travel Swindon (judged 'pretty perfect') vs ~50+ min London/Islington (judged too far)" — i.e.
the mods are talking in **slider position and minutes**, not audience counts. Nobody in the
thread ever says "my post should reach N people" — 2-discourse.md §4 is explicit: **"No mod
proposed a data-derived/self-calibrating mechanism themselves... The 'principled, data-derived,
self-maintaining' framing in the project brief is not something any mod asked for or would
recognize as satisfying their complaint on its own — they want a dial, and got a partial one (the
per-member slider)."**

This means the mod-facing copy's chosen framing ("equal audience, not equal time") is a genuine,
correct technical fix to the *underlying* problem the complaints are gesturing at, but it is not
phrased in a vocabulary the actual complainants have ever used. A hostile-but-fair reading: Jax
and Jos will read "fair local audience... instead of the same number of minutes" and their first
reaction, based on their own in-thread history, is likely to be "why are you telling me about
audience counts, I never asked for that, I asked why my post goes to 32 groups an hour's drive
away" — i.e. the copy answers a question in the language of the analyst, not the language of the
complaint. The design is not wrong to fix audience-fairness (the underlying math is sound and
D1's diagnostic case for *why* audience, not time, is the right invariant is genuinely strong) —
but §8's copy, as currently drafted, risks landing as another instance of "the data says so"
(#7's framing, which 2-discourse.md documents as having "visibly backfired repeatedly") dressed
in nicer prose, rather than as an answer to "why does my post reach 32 groups."

**Why MAJOR not MINOR**: 2-discourse.md §7 requirement 1 is explicit that any design "needs an
answer better than 'the data says so.'" The current copy leads with the mechanism (audience vs
time) before it leads with the complainant's own framing (group count, minutes, "how far will
this actually go"). This is a copy-ordering problem, not a math problem, but it's exactly the kind
of thing that determined the difference between #237's wording fix (accepted, implemented same-day)
and #7's data-first framing (which "visibly backfired repeatedly" per the same evidence file).

**Fix**: reorder §8's copy to open with the complainant's own vocabulary — group count and
minutes — before introducing audience-size as the mechanism: e.g. "Your post won't be shown to
every group within a fixed number of minutes any more — instead it stops once it's reached a fair
number of people for an area like yours, which in a dense area happens much sooner (fewer
minutes, fewer groups) than it used to." Lead with the outcome mods actually complained about
(fewer minutes, fewer groups touched), not the mechanism (audience counted, not time). This is a
wording change, not a design change, but §8 as currently drafted risks re-triggering the exact
"you're explaining, not listening" reaction the thread's own history warns about.

---

## MINOR-1: "Punishes rural groups?" — checked, does not hold, but the honest-cons section
## undersells how asymmetric the confidence really is

The design does NOT punish rural groups on any axis actually checked: §7's worked examples (Hull,
Swindon, Ribble Valley) are unchanged or near-unchanged, and honest-con #1 already flags that
sparse-tercile k "is assumed to generalize... rather than independently confirmed," correctly
noting this is "functionally inert" because sparse groups are floor/ceiling-bound regardless.
This is a fair characterization — no fix needed on the substance. One nuance worth adding: the
"functionally inert" framing is true for *audience size* but the design never checks whether the
**drive-time to reach the floor** changes meaningfully for any rural group under the new rule
(§7's Ribble Valley row gestures at "~27-29 min for the ~20% of posts that do cross the floor
early" but doesn't quantify how many rural groups fall into that 20%-early-crossing bucket
nationally, vs. the 80%+ that stay ceiling-bound). Given 35% of all groups nationally never reach
even N*=2,000 (4-audience-curves.md §3c), a small verification that this 20%-early-crossing
pattern isn't concentrated in some particular rural cluster (e.g. small commuter-belt towns just
under the sparse/medium tercile boundary) would close this off entirely. Low stakes, cheap check,
worth doing during Stage 0.

## MINOR-2: reply_saturation_stop recalibration (5→3) shares an evidence base with k but is
## validated on the SAME flat/declining data the FATAL-2 extrapolation concern applies to

§3's table justifies dropping `reply_saturation_stop` from 5 to 3 using 5-demand.md §1's
P(≥3)=4-8% vs P(≥5)≈1% figures — reasonable on its own terms, and correctly flagged in §13 as
deserving separate validation rather than silent bundling. No additional finding beyond what §13
already states; noting only that this constant inherits the same "no real ultra-dense group in
the underlying tercile split" caveat as FATAL-2, so its own separate validation pass should
explicitly check whether P(≥3) holds at higher audience levels too, not just re-confirm the
existing decile table.

---

## Summary verdict

| # | Finding | Verdict | Blocks the flagship complaint fix? |
|---|---|---|---|
| FATAL-1 | NW-London worked example's `active_members` is actually `total_freeglers` (circular, mislabelled) | FATAL | Yes — undermines the single example built to answer #250 |
| FATAL-2 | k=1.0 extrapolated 3-4x past the densest fitted tercile (max 3,469 active) for the flagship case | FATAL | Yes — same example, deeper problem: even a correctly-measured input would still be outside the fitted range |
| MAJOR-1 | Collection-catch backtest never runs against the initial rollout transition, only future re-fits | MAJOR | Indirectly — the exact prior finding it's supposed to honor ("cap that decreases with density is wrong") is untested for the actual proposed cut |
| MAJOR-2 | Mod copy claims "not a dial" while §4/§11/§13 describe a real manual-review pathway | MAJOR | No, but risks a second trust collapse if discovered later |
| MAJOR-3 | Explainability graft (§5, the map) has no shipping-order guarantee relative to the audience-cap itself | MAJOR | Yes — could ship the invisible parameter before the visible artifact that's supposed to justify it |
| MAJOR-4 | Mod-facing copy leads with mechanism (audience) not complainant vocabulary (minutes, group count) | MAJOR | Partially — risks the same "explaining not listening" reaction that already cost trust once |
| MINOR-1 | Rural early-crossing minority not quantified | MINOR | No |
| MINOR-2 | reply_saturation_stop recalibration shares FATAL-2's evidence-range caveat | MINOR | No |

**Bottom line for the hostile moderator**: the underlying philosophy (audience, not time, should
be the invariant) is sound and well-evidenced for every group this design's own data actually
measured — Tower Hamlets, Oxford, Swindon, Hull, Ribble Valley all check out. The design breaks
down exactly where it matters most: the single flagship case (NW-London / post #250) that the
whole project exists to fix rests on a proxied, mislabelled input variable and an untested
extrapolation of its core multiplier, and the collection-catch guardrail that's supposed to
catch exactly this kind of problem is wired to fire on the wrong transition (future re-fits, not
the initial rollout). None of these are reasons to abandon D1's approach — they are reasons not
to present the NW-London row as demonstrated, and not to start Stage 1 until (a) real
`active_members` is measured for at least one genuinely ultra-dense group, (b) the collection-catch
backtest is run against the actual proposed initial cut for that group, and (c) the Catchment-map
explainability graft is confirmed to ship no later than Stage 1, not left as an open scheduling
question.
