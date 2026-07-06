# Adversary 2 (Statistician) — Attack on SYNTHESIS.md

Scope: attack the derivations in `SYNTHESIS.md` (the D1-based audience-normalization design with
D2/D3/D4 grafts). Checked every load-bearing number against the underlying evidence files
(`1-mechanics.md`, `2-discourse.md`, `3-behaviour.md`, `4-audience-curves.md`, `5-demand.md`,
`6-external-anchors.md`) and against the four candidate designs (`D1`-`D4`) and the three judge
transcripts (`J1`-`J3`), which had already flagged some of this territory. Findings below are
either (a) genuinely new — not named anywhere in J1-J3 or SYNTHESIS's own §12/§13 — or (b) named
somewhere in the evidence base but **silently dropped or weakened when merged into SYNTHESIS.md**,
which is a distinct and more serious problem than "known limitation," because a reader of the
synthesis alone would not know the caveat exists or was ever stronger.

Verdicts: **FATAL** = the design is wrong as specified and must change before proposing to a
product owner. **MAJOR** = must be fixed before this is safe to build, but the core philosophy
survives. **MINOR** = worth noting in the doc, does not block.

---

## FATAL-1: The anti-spiral "collection-catch backtest" cannot detect the spiral it exists to catch

**Location:** `SYNTHESIS.md` §4 (lines 130-138), restated in §11 step 2 and the failure-mode table
(§9, "Self-reinforcing tightening spiral" row).

**The claim:** the backtest replays "last quarter's actual collections" against a proposed smaller
`N*` and requires ≥90% capture, and this is presented — twice, explicitly — as proof the proposal
"is shown to serve what already happened, **not just satisfy a percentile computed on an
already-shrunk population**" (§4, emphasis in original).

**Why this is false, mechanically:** "last quarter's actual collections" are themselves the output
of *last quarter's* `N*`/`k`. If quarter Q's re-fit shrinks `N*` (even within the 15% band), then
every collection in quarter Q+1 that the backtest replays happened under the *already-shrunk*
reach. A person who would have collected the item only if reach had been wider than Q's shrunk
`N*` never appears in the outcome table at all — they were never notified, never saw the post,
never converted. The backtest cannot "catch" a collection that structurally could not have
happened. It is not an independent check against the true, unshrunk demand; it is a check against
demand-as-already-filtered-by-the-thing-being-tuned. This is precisely the "already-shrunk
population" failure mode the synthesis's own sentence claims to rule out — it does not rule it
out, it restates it with different words and a specific number (90%) that gives false confidence.

**This was already correctly diagnosed in the source evidence and dropped in synthesis.**
`D2-behavioural-percentile.md` §1.3's own risk table (line 262) states the backtest is only *one
of three* mechanisms needed to actually break the spiral, and names the third explicitly as
load-bearing: *"(c) §1.5(c)'s explicit plan to swap in the reach experiment's uncensored
treatment-arm data the moment it's available, which **structurally breaks the spiral** by
introducing a genuinely uncensored input at least once."* D2 is explicit that (a) the 15% cap and
(b) the backtest **alone** only slow the spiral, they do not break it — only (c), the eventual
injection of experimentally-uncensored data, does that. `SYNTHESIS.md` §4 ports (a) and (b)
verbatim ("This is D2's exact mechanism... same logic, same threshold") but drops (c) entirely
from the graft. §11 (the self-maintenance loop) never once mentions the reach experiment as a
required input to the re-fit; §11's "Relationship to the planned reach experiment" (also §11)
treats the experiment as an independent, parallel workstream answering "a different question"
rather than as a necessary structural component of the anti-spiral guardrail it borrowed from D2.
The synthesis's own §12 con #1 talks about the sparse-tercile-peak problem but never states this
one, despite it being visible in the exact source document (D2) the graft was copied from.

**Concrete failure scenario:** Dense-tercile `k` sits at 1.0 (N*=~1,700-3,469 by §7's Tower
Hamlets example). Quarter 1 re-fit: reply rate has dipped slightly (noise, or a genuine but
unrelated cause — see FATAL-2/MAJOR-3 below), `k` moves down 10% (within the 15% band, so it
applies automatically). Quarter 2's collections are now measured under the 10%-smaller reach.
Some previously-reachable-but-marginal collectors (the ones between the old and new boundary) are
never notified and never collect. The backtest at the next re-fit replays Q2's (already-narrowed)
collections against a further-proposed cut — of course it clears 90%, because the marginal
collectors who would have failed that check were already excised from the sample one cycle
earlier by the previous cut. Nothing in the mechanism as specified would ever produce a "backtest
fails" result purely from over-tightening, because the population the backtest checks against
shrinks in lockstep with the cap. The 15% rate cap slows the descent; it does not stop it, and the
backtest provides false reassurance that it has been stopped when it has only been slowed.

**Fix (concrete):**
1. Import D2's dropped mechanism (c) as a **hard precondition**, not an optional parallel
   workstream: no re-fit is permitted to shrink `N*`/`k` for a density band **unless** that band
   has at least one completed reach-experiment readout (or an equivalent permanent small holdout —
   see D4's mechanism, which SYNTHESIS.md's own judge scores rate highest on exactly this
   criterion, C8, "scientific honesty" in `J2-judge.md`) providing an uncensored estimate of
   demand beyond the current cap. Until that exists for a band, the guardrail should be "may only
   *widen*, never shrink" for that band — asymmetric, and safe by construction, until real
   uncensored data exists.
2. Alternatively (cheaper, if a permanent holdout is judged too costly to run indefinitely): keep
   a **fixed, small, permanent random holdout per density band** (analogous to D4's 5% notification
   holdout, but scoped only to measurement, not to full notification suppression) that is *never*
   subject to `N*` tightening, specifically to give each re-fit one genuinely uncensored reference
   population to backtest against, instead of backtesting the proposal against its own recently-
   narrowed population.
3. At minimum, the mod-facing copy (§8) and the internal documentation must stop describing the
   backtest as proof the new number "serves what already happened, not just a percentile on an
   already-shrunk population" — that sentence is the specific false claim; it should instead say
   the backtest is a **necessary but not sufficient** check, pending genuinely uncensored data.

---

## FATAL-2: The `k=1.0` "peak" is read off the left edge of a censored curve, not an interior maximum

**Location:** `SYNTHESIS.md` §3 constant table, `k` row (line 97), and §2's headline claim ("the
demand report's within-density-tercile analysis... rules out the obvious objection... Holding
density fixed and varying audience within it, reply probability still peaks near roughly 1x the
tercile's own scale and *declines* past it").

**The claim:** within-tercile, P(≥1 reply) "peaks" at sub-quintile 1 (audience ≈ tercile's own
active-member scale) and declines monotonically afterward, which is offered as the empirical basis
for `k=1.0`.

**Why this is not what the data shows.** Checking `5-demand.md` §6's own table directly:

| tercile | sub-quintile 1 (smallest audience) | sub-quintile 2 | 3 | 4 | 5 (largest) |
|---|---|---|---|---|---|
| 2 (medium) | **41.3%** @ avg aud 1,021 | 34.5% @ 1,815 | 33.5% @ 2,801 | 37.6% @ 4,069 | 24.7% @ 7,715 |
| 3 (dense)  | **38.8%** @ avg aud 1,731 | 35.8% @ 3,345 | 31.6% @ 5,572 | 25.1% @ 8,588 | 21.8% @ 13,082 |

In both terciles, **sub-quintile 1 is the smallest audience bucket that exists in the sample for
that tercile** — not an interior point on a curve with data on both sides of it. There is no
observed data point at, say, 400 or 600 active-member-equivalent audience for either tercile;
sub-quintile 1's ~1,021 (tercile 2) and ~1,731 (tercile 3) are the left edge of what was measured,
determined by `NTILE(5)` splitting whatever audience range that tercile's posts actually reached,
not by any independent choice of bucket boundaries. Calling this a "peak" asserts the curve turns
over and heads back down below that point — nothing in the table shows this. It is equally
consistent with the true curve continuing to *rise* below audience ≈1,000-1,700 (i.e. the true
optimum for medium/dense areas could be even smaller than sub-quintile 1's range, and `k` should be
correspondingly smaller than 1.0), or genuinely flattening (matching the sparse-tercile pattern,
where §6's own text admits "tercile 1... roughly flat," i.e. no decline observed at all across the
full range 265→3,444). The synthesis's own §12 con #1 partially concedes the *sparse* tercile
never showed a decline, but frames this as "asymmetric confidence across bands," not as "the
medium/dense terciles' own claimed peak is unverified on the low side too" — the same structural
gap exists at the bottom of every tercile's range, not just the sparse one.

**This directly compounds FATAL-1's censoring problem, one level down.** `5-demand.md` §6 is
itself built from `total_freeglers` (realized, post-30-min-ceiling audience) — a post that, absent
the 30-min ceiling, "would" have had an even smaller natural audience simply doesn't exist in this
data at all (every post has *some* minimum floor set by the existing schedule's tick-1 burst, not
zero). `J2-judge.md` (line 228, C8 write-up on D1) already flags this exact problem for the *high*
end of the tercile-quintile table ("a post that 'would' have reached 20,000 members absent T_max
never appears in decile 10 at all") but the identical argument applies symmetrically at the *low*
end for the peak-location claim specifically, and neither J2 nor SYNTHESIS.md's §3/§12 name it
there. The peak location — the single number `k` is fit to — is exactly the number most exposed to
this gap, more so than the general "decline is real" claim (which the high-end truncation doesn't
threaten in the same way, since decline-after-a-point is visible regardless of what's below the
window).

**Fix (concrete):**
1. Before adopting `k=1.0`, run the **Stage 0 dark-compute re-validation** (already planned in
   §10) with a specific addition: don't just sweep `k ∈ {0.5, 0.75, 1.0, 1.25, 1.5}` against
   existing data (which cannot resolve this, since existing data has the same left-censoring at
   every sweep value ≥ what's observed) — instead, find or construct a genuinely lower-audience
   comparison population. Two candidates already exist in the evidence base: (a) the **pre-ripple
   period** (`3-behaviour.md` §4, pre-2026-06-14), where audience was bounded by group-polygon
   membership rather than a 30-min isochrone, which for many groups is *smaller* than today's
   floor and gives genuine sub-1,000 data points for dense/medium terciles; (b) posts that stopped
   early via the existing (dead, but data-generating) `reply_saturation_stop` mechanism, whose
   *actual* realized audience at stop-time might sit below sub-quintile 1's range for some
   density classes even under the current pipeline.
2. State the `k` derivation's confidence honestly as **"lower bound only, unverified whether truly
   optimal or merely the smallest available sample"** rather than "confound-checked, the strongest
   single result in the evidence base" (§2) — the confound-check (density held fixed) is real and
   correctly done; the *peak-location* claim is a separate, unverified claim smuggled in under the
   same sentence.
3. Until (1) resolves this, treat `k=1.0` as the **upper edge of a plausible range**, not a point
   estimate — i.e. do not let downstream honest-cons language ("1.0 is a round, slightly
   conservative fit, erring toward more reach not less" — §3 table) do the work of hand-waving away
   an actual unresolved identification gap. "Errs toward more reach" is true only if the untested
   region below sub-quintile 1 would show *further* decline, which is asserted, not shown.

---

## MAJOR-1: `T_min=10` is not derived from evidence at all — it contradicts the cited anchor

**Location:** `SYNTHESIS.md` §3 constant table, `T_min` row (line 101): *"DfT NTS0403f: national
one-way shopping-trip duration averages 17 min, flat across urban/rural bands. Retail 'primary
trade area' convention: 5-10 min generates 50-80% of footfall. 10 min is the lower edge of that
convention band."*

**Why this is arbitrary in disguise.** The two cited anchors give two different numbers — 17 min
(NTS shopping trip) and 5-10 min (retail footfall convention) — for two different activities
(a paid discretionary shopping trip vs. unpaid casual footfall to a convenience store). The design
picks **neither** number as `T_min`; it picks the *lower edge of the second, less-relevant anchor's
range*, which happens to sit below the first anchor entirely. There is no stated principle for why
"lower edge of the footfall convention" is the right selection rule rather than, say, "midpoint of
the footfall convention" (7.5 min) or "some fraction of the NTS figure" (e.g. 17×0.5≈8.5,
17×0.6≈10.2 — coincidentally close to 10, but this arithmetic is not shown or claimed). Reading
`6-external-anchors.md` directly (line 291): the anchors report's own bottom line describes "a
similar 5-10 minute 'primary' catchment with fast-diminishing marginal value beyond that" as
supporting evidence for a *short* floor generally, but never states or implies 10 specifically
should be the chosen number over, say, 8 or 12 — the anchors report is silent on exactly which
value within its own cited ranges to pick, and SYNTHESIS.md's derivation column asserts a specific
selection rule ("lower edge") that appears nowhere in the source evidence, effectively inventing a
selection principle post hoc to justify a value that (not coincidentally) matches the pre-existing
live config default.

**This is functionally identical to the "flat, uncalibrated placeholder" problem the whole design
effort exists to solve for `N*`** — except here it's happening to `T_min`, is unexamined, and the
table entry's parenthetical ("Matches the existing MVP floor (adoption, not a change)") is honest
about the number being unchanged from today, but that honesty doesn't retroactively make the
*evidence-based derivation* claimed in the same row true. `T_min` is being *justified after the
fact* with anchors that don't actually converge on 10, not *derived from* them.

**Fix (concrete):** Either (a) state plainly that `T_min=10` is a **retained operational default,
not a re-derived constant** — move it out of the "every number has a derivation" table (§3) into a
short explicit "constants we are choosing not to touch" list, alongside `T_max=30` (which the
design is honest is unchanged, but at least doesn't claim a specific numeric derivation path that
doesn't check out); or (b) if a genuine derivation is wanted, use the anchors report's own primary,
most-relevant, most-official source (NTS0403f, 17 min duration) with an explicit, stated
discount factor for "unpaid casual local errand vs. paid discretionary shopping trip" (the anchors
report itself gestures at this on line 103 — "likely to sit below the shopping-trip average" — but
never quantifies it), rather than switching anchors mid-derivation to the retail-footfall
convention, which measures a different behaviour (drive to a fixed retail location) that has only
a loose analogy to "how long should Freegle wait before rippling."

---

## MAJOR-2: The demand-flattening evidence and the "confound is ruled out" claim overclaim relative to what a stratified (non-randomized) analysis can actually show

**Location:** `SYNTHESIS.md` §2 (bullet 1, lines 55-61) and the `k` derivation row (§3, line 97,
"confound-controlled, the strongest single result in the evidence base").

**The claim:** "It is the only design whose central claim was confound-checked, not just
correlated... No other design's core number survives this kind of check."

**Why this overclaims.** Controlling for density-tercile (a 3-bucket coarsening of one confounder)
is a real and useful step, correctly credited by `J2-judge.md` (line 217-219: "a genuine
confound-control exercise... real, if simple, causal reasoning"), but J2's own next clause — which
SYNTHESIS.md's §2 does not carry forward — immediately qualifies it: *"still correlational — density
terciles could still correlate with unmeasured post-quality or category-mix differences."* Density
is not the only variable that plausibly correlates with realized audience size in this dataset:
posts in denser areas are also disproportionately likely to be posted by higher-frequency,
higher-reputation posters (who write better listings, add photos, post desirable categories more
often — London posting *volume* is ~327/day of the network's ~1,525/day per the known measured
facts, meaning London posters are structurally different in composition, not just location). None
of that is stratified on. "Within-tercile" controls for the *group's* density, not for the *post's*
quality, category, photo-presence, or time-of-day — all explicitly named as unmodelled confounders
in SYNTHESIS.md's own §12 con #3, but con #3 is framed there as a *forward-looking* re-fit risk
("the periodic re-fit implicitly assumes any drift... is due to the audience parameter"), not as a
caveat on the *original* tercile-quintile evidence that `k=1.0` is fit to in the first place. The
same unmodelled confounders that threaten the re-fit going forward already threaten the founding
measurement.

**Fix:** Downgrade the language in §2 from "confound-checked, not just correlated" (implying the
matter is closed) to "partially confound-checked (density only); category mix, post quality, and
poster-composition differences between dense and sparse areas remain unstratified and could still
be driving some of the observed within-tercile decline." Add a Stage 0 check: repeat the §6
tercile-quintile analysis with an additional stratification or regression control for post
category and photo-presence (both already in `messages`/`messages_attachments` — no new data
needed) before treating `k=1.0` as validated, not just directionally plausible.

---

## MAJOR-3: The re-fit's chosen fitness signal (reply rate) is exactly the metric structurally coupled to the deliverability feedback loop the design itself flags as unresolved

**Location:** `SYNTHESIS.md` §11 step 1 ("Re-derive `k` per density band... from the trailing 90
days of live reply-decile data") and §13 open question #2 ("Deliverability feedback loop... Flagged,
not resolved").

**Why this is a MAJOR, not just a listed open question.** The design already *names* this risk in
§13 as an open question to check "before the re-fit is first allowed to run unattended" — but §11,
the actual operational procedure, contains no such gate. Step 1 of the self-maintenance loop simply
re-derives `k` from trailing reply-decile data every 90 days with no stated precondition that the
deliverability check has been run first. This is an internal inconsistency: the design's own §13
correctly identifies that "high volume → spam-foldering → measured reply rate looks artificially
low → an over-correction in the wrong direction" is a *live risk to the primary fitness signal*,
but the mechanism in §11/§4 that would need to guard against it (the anti-spiral rate cap) only
looks at the *magnitude* of change (≤15%/period), not the *direction or cause*. A spam-foldering-
driven reply-rate suppression in a high-volume dense band would look, to the 15%-cap mechanism,
identical to a genuine over-reach signal — both present as "reply rate fell, propose shrinking
k" — and the cap would happily approve up to 15% of exactly the wrong correction, repeatedly,
quarter after quarter, without ever being distinguished from the correct case. This is a second,
independent spiral risk (distinct from FATAL-1's audience-censoring spiral): a **deliverability-
driven spiral**, where tightening reach in response to suppressed reply-rate makes the audience
smaller, which (if spam-foldering scales with volume, as the mechanism the design itself
describes implies) could paradoxically *worsen* deliverability-driven suppression in the surviving
audience, or at minimum provides zero signal either way since the two causes are unidentified from
reply-rate alone.

**Fix:** Before the re-fit is allowed to treat a reply-rate decline as evidence for shrinking `k`,
require an independent deliverability check (e.g. bounce/spam-complaint rate or open-rate trend
for the density band, both plausibly already logged by the mail pipeline) confirming the decline
isn't attributable to delivery suppression. This is a precondition on step 1 of §11, not merely an
"open question" to check once before Stage 1 — it needs to run **every quarter**, not once,
because deliverability conditions can change over any 90-day window, same as the thing being
re-fit.

---

## MINOR-1: The "provably inert / provably binding" claim in §2 is true only for realized (post-ceiling) audiences, but the design's own worked table (§7) already shows this being interpreted more strongly than warranted for near-boundary groups

`SYNTHESIS.md` §2 states the design is "provably inert exactly where nobody is complaining" —
correct for Hull/Swindon/Ribble Valley, which never cross even N*=2,000 (per `4-audience-curves.md`
§3c, "100% never-reach"). But Ribble Valley's own row (§7) shows "~20% of posts... cross the floor
early" — i.e. not 100% inert, ~1-in-5 posts from a group nobody has complained about would see
some tightening under this design. This is a small population and the practical effect is
described accurately in §7's own "what changes" column ("essentially unchanged... very small
tightening for a minority"), so this is not a correctness bug, only a rhetorical overreach in §2's
summary framing ("provably inert... completely untouched") that the design's own detailed table
one section later already contradicts for one of its three "inert" exemplars. Tighten the §2
language to "inert for the large majority of posts in these groups" rather than "completely
untouched."

## MINOR-2: `N*_min=1,000` derivation conflates "smallest bucket that discriminates" with "correct floor"

`SYNTHESIS.md` §3's `N*_min` row cites the fine-bucket table (500-1,000: 34.2% P(≥1)) as "close to
the observed peak," and separately cites "1,000 is the smallest N* that discriminates at all" from
the dark-compute schedule granularity. These are two different justifications for the same number
that happen to agree — worth flagging (not blocking) that the second justification is really an
artifact of the 9-tick schedule's own coarseness (`4-audience-curves.md` §0: fixed 9 ticks; small
N* values are hard to resolve precisely against a coarse tick ladder) rather than a demand-side
fact, and conflating a measurement-resolution limit with a demand-based floor risks the number
surviving a future re-derivation for the wrong reason (e.g. if tick granularity changes, this
"smallest N* that discriminates" argument silently stops applying, but the table wouldn't
necessarily flag it, since the demand-side co-justification (34.2% at 500-1,000) would still read
as if it independently supported 1,000).

---

## Summary table

| # | Verdict | One-line finding | Fix scope |
|---|---|---|---|
| FATAL-1 | FATAL | Collection-catch backtest checks the shrunk population against itself; cannot detect the spiral it's named to catch; D2's own third mitigation (uncensored experiment data) was dropped in the graft | Make experiment/holdout data a hard precondition for any *shrinking* re-fit, not a parallel workstream; fix mod copy claiming this is solved |
| FATAL-2 | FATAL | `k=1.0` "peak" is the left edge of censored data, not a verified interior maximum; identical structural gap to the already-flagged high-end truncation, unaddressed at the low end | Use pre-ripple or early-stop-saturation data to get genuine sub-1,000/1,700 data points before trusting `k=1.0`; state as upper-bound estimate, not point estimate |
| MAJOR-1 | MAJOR | `T_min=10` cites two anchors (17min, 5-10min) that don't converge on 10; "lower edge" selection rule invented post hoc | Either declare it an unchanged operational default (no derivation claimed) or derive properly from the primary NTS anchor with a stated discount factor |
| MAJOR-2 | MAJOR | "Confound-checked, not just correlated" overclaims — only density is controlled; post quality/category/poster-composition remain unstratified confounders | Downgrade language; add category/photo-presence stratification to Stage 0 before trusting `k` |
| MAJOR-3 | MAJOR | Deliverability-feedback risk named in §13 as an open question but has no operational gate in §11's actual re-fit procedure; second, independent spiral risk not covered by the 15%/backtest guardrails | Require a per-quarter deliverability check as a precondition on every re-fit, not a one-time pre-Stage-1 check |
| MINOR-1 | MINOR | §2's "completely untouched" for ceiling-bound exemplars is contradicted by §7's own Ribble Valley row (~20% affected) | Soften language |
| MINOR-2 | MINOR | `N*_min=1,000` derivation conflates tick-schedule resolution limit with demand-based floor | Note the two justifications are not independent |

All FATAL/MAJOR findings above are either genuinely new (FATAL-2's low-end symmetry, MAJOR-1,
MAJOR-3's operational-gate gap) or represent evidence that was **already correctly identified
somewhere in the source material (D2's own text, J2's own scoring) and then lost, weakened, or
contradicted during the synthesis step** (FATAL-1, MAJOR-2) — which is arguably the more
actionable category, since the fix in both cases is to restore language/mechanism the project's
own prior work had already produced, not to invent new analysis from scratch.
