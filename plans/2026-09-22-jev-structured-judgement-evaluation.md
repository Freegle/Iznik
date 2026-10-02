# Jev on the matched-posts email: measured against the threshold it would replace

Date: 22 September 2026. Continues the 21 September session that first tried Jev against
vector search. Evaluation only, nothing implemented. No key or credential appears here.

## What Jev is

TypeSafe's structured-decision model, `POST https://api.typesafe.ai/v1/systemone`, model
`jev-latest` (answered as `jev-1.13.0`). It takes a `state` object and a set of typed
questions and returns typed answers: a `noul` is a probability for a yes/no. No index, no
vectors, no free text.

That shape means it cannot retrieve. Asking it about every open post to run a search costs
about $0.07 and 144 seconds per search against 4,142 posts, versus 2ms for the cosine scan
we do now. So it is not an embeddings competitor. It is a candidate for the part of the
pipeline that is weak, which is the fixed number a cosine is compared against.

Four places in the code make exactly that decision:

| Site | Constant | What it decides |
|---|---|---|
| `message/vectorsearch.go:20,26` | 0.65 / 0.75 | which search results a member sees |
| `message/postmatches.go:45` | 0.85 | whether we send an unsolicited "we found it" email |
| `message/similar.go:56` | 0.80 | the similar-items browsing strip |
| `newsfeed/duplicate.go:22` | 0.82 | telling a moderator a ChitChat post is a duplicate |

21 September measured the first one. This note measures the second, which is the one where
being wrong costs a real member a junk email, and the one whose docstring already records a
measured precision curve to argue with.

## Method

Real production data through the read-only tunnel, no synthetic examples.

1. Pulled every open post with an embedding in a London box (51.2-51.8N, -0.6-0.4E):
   3,457 Offers and 1,676 Wanteds, 5,133 rows with their stored 256-dim subject vectors.
2. Replicated `PostMatches` exactly: for each Offer, the best-scoring open Wanted inside the
   same 0.15 degree box, excluding the poster's own posts, scored on subject cosine only.
   Cosine is a plain dot product because the stored vectors are already L2-normalised, so
   this is bit-for-bit what the Go store computes. Reach filtering was skipped: it changes
   who is eligible, not whether a match is good.
3. Drew 40 pairs from each of four bands (0.90-0.95, 0.85-0.90, 0.80-0.85, 0.75-0.80),
   shuffled them, and hid the score.
4. Labelled all 160 blind, on subject and body only, against one question: would the person
   who posted this WANTED be glad to get an unasked email about this OFFER? 88 relevant,
   72 junk.
5. Asked Jev the same question about the same 160 pairs, with the same text, three times.
6. Scored both rules against the labels, weighting each sampled pair by its band's share of
   the 1,610 sources whose best match falls in 0.75-0.95.

## Result

Threshold-free first, because it does not depend on where anyone puts the cut. Separating
the 88 relevant from the 72 junk:

**AUC: cosine 0.779, Jev 0.933.**

At matched volume, which is the fair comparison, both rules sending about 414 emails per
1,610 posts:

| | precision | recall | junk sent | good missed |
|---|---|---|---|---|
| production, cosine >= 0.85 | 71.9% | 43.0% | 116 | 395 |
| Jev >= 0.43 | 93.7% | 54.1% | 26 | 344 |

Same number of emails, roughly a quarter of the junk, and more of the good matches found.

The per-band table shows why. In 0.80-0.85, which production discards outright, 15 of 40
sampled pairs are genuinely relevant, and Jev picks 7 of them with no junk. In 0.85-0.90,
which production sends in full, only 26 of 40 are relevant, so 14 junk emails go out per 40.
The cosine cannot tell those apart because it is one global number and the question is
local: "Shelf" against "Cat shelf" scores 0.916, "3 seater sofa" against "2 Seater Sofa"
scores 0.929, and "Phone" against "Phone Case" scores 0.907. All three are junk to the
person who asked, and Jev scores them 0.10, 0.11 and 0.03.

## Three things to be careful about

**Jev is not calibrated at 0.5 for this question as I worded it.** At 0.5 it keeps only
41% of the good matches. The useful operating point was 0.43 for matched volume and 0.30
for best F1 (precision 84.8%, recall 81.3%, F1 83.0 against production's 53.8). Anything
built on this must set its own point against labels rather than assume the midpoint, or
recalibrate by softening the "fails a requirement they stated" clause in the criteria.

**It is still jagged, as 21 September found.** Across three runs: median spread 0.020,
p95 0.060, worst 0.100, and 3 of 160 decisions (1.9%) flipped across the 0.5 line. That
matches the earlier 1.1% and the vendor's own "Jev 1.13 jaggedness" page. Aggregate accuracy
survives it; an individual borderline decision does not. Sampling several times and taking
the majority is the documented answer and costs three calls instead of one.

**The labels and Jev's criteria were written by the same person, which flatters Jev
against a cosine that had no say in either.** The honest check on that is the five pairs in
the top band I called junk: Jev independently scored four of them 0.03 to 0.11 and hedged
at 0.33 on the fifth, so the labels are not eccentric, but a second labeller would make the
comparison stronger. The sample is also one city and one day, and top match only.

The docstring at `postmatches.go:33` records precision 0.92 for 0.85-0.90 and 1.00 above
0.90. My labels give 65% and 87.5% for the same bands. I am not claiming that measurement
was wrong: it is a stricter standard applied by a different person, and the gap is the
usual gap between "is this the same kind of thing" and "would this email be junk to them".
Both rules here are judged against the same labels, so the comparison holds either way.

## What the live data says, which matters more than any of the above

`firstreply_scouts` records every send and stamps `replied_at` when that person went on to
reply. The migration says the reason column exists "so we can tell which signal is actually
earning its keep rather than guessing". The whole table, 11,098 rows, last written
11 August 2026:

| signal | mailed | replied | rate |
|---|---|---|---|
| frequent | 11,073 | 8 | 0.07% |
| wanted | 9 | 1 | 11.1% |
| search | 16 | 0 | 0% |

The targeted signal converts about 150 times better than the broadcast one did. `frequent`
has since been changed to pick by how fast someone replies rather than how often
(`3be749195`), and the current service only writes `wanted` and `search`, so this is
retrospective support for a decision already taken rather than a live fire.

The live figure to sit with is the other one: **the matched-post signal sent nine emails in
the whole trial, and every one of them scored 0.95 or above.** Not one send landed in the
0.85 to 0.95 range the constant was set to allow. The surface is currently off
(`FIRSTREPLY_MATCHMAIL_ENABLED` defaults false, rollout percent defaults 0) and last mailed
on 11 August.

So the threshold is not currently costing members junk mail, because nothing is being sent.
Tuning the constant on a feature that sends nine emails is not worth doing. The question
worth answering before it is turned back on is why a pool with 414 matches above 0.85 in one
city produced nine sends nationally, and that is a question about the job's other gates
(post age, cooldowns, reach, rollout share), not about 0.85.

## What I would do next, in this order

1. Find out why `wanted` produced nine sends. Instrument the job's funnel so each gate says
   how many candidates it dropped. Until that is known, no threshold work pays.
2. If and when it is turned back on, adjudicate the 0.75 to 0.95 band with Jev rather than
   moving the constant. Retrieval stays a cosine scan, the cosine floor drops to 0.75 purely
   to bound how many judgements are asked for, and Jev decides inside that band. Measured
   cost at the volumes in play: 160 judgements took 74k input and 3.4k output tokens at a
   mean 247ms, p95 518ms, at concurrency 8.
3. Set the operating point against a fresh labelled sample, ideally labelled by someone who
   did not write the criteria, and take the majority of three runs for anything near the
   boundary.
4. The same experiment is worth repeating on `newsfeed/duplicate.go` (0.82), where being
   wrong takes a member's post away and the volume is small enough that three calls per
   decision is nothing.

## Reproducing

Scripts are in this session's scratchpad and will be cleaned: `match.mjs` replicates
PostMatches over a pool TSV, `sample.mjs` draws the blind stratified sample, `jev.mjs` runs
the judgements, `eval.mjs` and `stab.mjs` score them. The pool query, the exact question
wording and the 160 labels are all in this note's method section. The API key is held
outside the repository.
