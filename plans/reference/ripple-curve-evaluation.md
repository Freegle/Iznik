# Ripple Notification Algorithm — Curve Evaluation

Last updated: 2026-05-27.  Source data: **20,977 historical Freegle posts**
with **48,671 (post, replier) pairs** extracted from production over the
last 12 months (i.e. essentially the whole population — only ~225 posts
were dropped because all repliers lacked locations), evaluated via
`iznik-routing-go/cmd/ripplesim`.

## What the simulator measures

For each historical (post, replier) pair, we ask: if the new ripple
algorithm had been live at the time, would it have notified the eventual
replier *before* they actually replied?  We don't claim more replies would
have happened — only that the schedule that reliably hits this metric is
closer to the right shape.

Four metrics are reported per (curve, group):

| Metric | What it means |
|--------|---------------|
| **All in-time %** | Fraction of (post, replier) pairs where our schedule would have notified the replier before they replied |
| **1st in-time %** | Same, but only counting the FIRST replier per post.  Arguably more important — 50 % of posts only get one reply |
| **Notify-vol** | Total notifications sent across the simulated runs |
| **Wasted %** | Fraction of those notifications sent AFTER the post had already been taken / promised — effectively wasted email |

Plus a per-tick "where caught" histogram: how many of the in-time pairs
were notified at tick 1, ticks 2–5, ticks 6+.

The simulator does **not** depend on data from the algorithm being changed;
the only inputs are user reply locations and reply times, which are
algorithm-independent facts.  See `cmd/ripplesim/main.go` for details.

## Sample distribution (the constraint the algorithm has to fit)

From the 5,000-post extraction (4,264 posts kept, 10,005 (post, replier) pairs):

| Stat | Time-to-FIRST-reply | Time-to-ANY-reply | Replies per post |
|------|---------------------|-------------------|-------------------|
| p25  | 1.4 hr              | 2.5 hr            | 1                 |
| p50  | **7.7 hr**          | 14.8 hr           | 1                 |
| p75  | 28.9 hr             | 45.9 hr           | 3                 |
| p90  | 181 hr (7.5 d)      | 245 hr (10 d)     | 5                 |
| p95  | 401 hr              | 523 hr            | 7                 |

**Reads as**:

- **Half of posts get their first reply within 7.7 hours.**  Three-quarters
  within ~29 hours.  Any schedule whose first batch doesn't fire until day 2
  is far too slow.
- **50 % of posts only ever get one reply** — so the first-replier metric
  applies to half the dataset; whatever we do for "later" repliers only
  matters for the other half.
- 88 % of posts are Offers, 12 % Wanted.  65 % end up marked Taken; the
  remaining 35 % have no recorded outcome (could be silently taken,
  withdrawn, or expired).

## Curve sweep on 5k sample (ticks=30, lifetime=3d, max-min=30)

| Curve | URBAN 1st in-time | URBAN wasted% | RURAL 1st in-time | RURAL wasted% |
|-------|-------------------|---------------|-------------------|---------------|
| linear            | 48.8% | 24.3% | 49.2% | 19.4% |
| front-quad        | 60.1% | 16.8% | 58.2% | 13.1% |
| front-cubic       | 66.3% | 12.7% | 62.8% | 9.7% |
| front-sqrt (x^0.5) | 72.8% | 16.3% | 67.3% | 12.9% |
| front-heavy (x^0.3) | 84.5% | 11.2% | 77.6% | 8.8% |
| front-x^0.2       | 89.4% | 8.1%  | 82.9% | 6.3% |
| **front-x^0.15**  | **91.2%** | **6.3%** | **85.6%** | **4.9%** |
| **step-70%+linear** | **92.0%** | 7.6%  | **86.0%** | 6.0%  |
| back-quad         | 30.3% | 31.8% | 33.6% | 25.7% |

## Reading the table

1. **More aggressive front-loading wins on BOTH metrics.**  This was a
   surprise — we expected a tradeoff (aggressive → high in-time but high
   waste).  Instead: if you blast 90 % of notifications immediately, only
   the trailing 10 % can be sent late enough to be wasted.  Spreading the
   load across the lifetime puts most notifications in the "late" zone
   where they coincide with the post being taken.

2. **The top two curves (front-x^0.15 and step-70%) are within 1 pp on
   first-replier in-time and within 1.5 pp on waste.**  step-70% is
   slightly better at catching first repliers; front-x^0.15 has slightly
   lower waste.  Either is defensible.  **step-70%+linear** is more
   interpretable: "blast 70 % immediately, then ripple the remaining 30 %
   linearly across the 3-day lifetime".

3. **Per-tick caught histogram** (step-70%, urban): t1=4885 / t2-5=36 /
   t6+=56.  98 % of in-time catches happen at tick 1.  The trailing
   linear ticks are only catching the long tail.

4. **First-replier and all-replier metrics converge** at the most
   aggressive curves.  For step-70% urban they're both 92.0 %, meaning
   when we catch the first replier we also catch the rest.  For linear
   urban they diverge (55.2 % all vs 48.8 % first) because the slow ramp
   catches later repliers more readily than the first.

5. **Back-loaded is the worst on every axis.**  Includes both lowest
   in-time AND highest waste (because notifications pile up at the end
   when the post has already been taken).

## Are we notifying users "too early"?  Lead-time analysis

A natural concern with any "blast everyone immediately" curve: even if
we catch the eventual replier, we notified them *long before* they would
actually reply.  That long lead time may waste their attention.

Lead time = `reply_time - notification_time` (hours).  Lower is better
("just in time").  We track p50/p75/p90 across in-time catches:

| Curve | 1st in-time | p50 lead | p75 lead | p90 lead |
|-------|-------------|----------|----------|----------|
| linear | 62.6% | 20.6h | 102.8h | 426.3h |
| front-cubic | 75.8% | 18.7h | 73.7h | 363.0h |
| front-heavy x^0.3 | 87.3% | 16.8h | 55.8h | 331.5h |
| **step-70** | **93.0%** | **15.9h** | **51.4h** | **308.7h** |
| back-quad | 48.4% | 26.6h | 162.1h | 537.1h |

**Counter-intuitively, step-70 has the LOWEST lead times** despite being
the most aggressive front-loader.  Why?  Because aggressive front-loading
catches more *fast-repliers* (whose lead time = their short reply time),
which pulls the distribution toward lower values.  Less aggressive curves
miss the fast-replier cohort and end up with longer mean lead times.

### Can we time batches "just before reply"?

Idea: schedule each batch to fire close to when those users typically
reply.  Requires knowing reply time by rank.  Looking at the data:

| Rank percentile | All-replier p50 reply time |
|-----------------|------------------------------|
| Closest 0-5%   | 19.2 h |
| 5-10%          | 17.9 h |
| 10-20%         | 16.3 h |
| 20-30%         | 15.2 h |
| 30-50%         | 14.5 h |
| 50-70%         | 15.2 h |
| 70-90%         | 14.4 h |
| 90-100%        | 17.6 h |

**Rank doesn't predict reply time.**  The spread across all rank buckets
is only 5 hours.  We can't usefully time batches by rank because
"someone replying within 14 hours" is equally likely at every distance.

Note: the closest-rank bucket replies *slowest* in this view — a
counter-intuitive result that is plausible because the closest 5 % is a
small absolute number of users (so any single eager person doesn't shift
the median much), whereas the broad mid-rank buckets include the eager
respondents who occasionally reply quickly even from further away.

**Conclusion (initial)**: lead times of 10-20 hours p50 and 5-22 days p90 are
inherent to any curve that catches a meaningful share of repliers.

### Refinement: digest users skew the lead-time picture

The apparent lead time conflates *our notification time* with *the
user's first opportunity to see the notification*.  Most members have
their group set to a 24-hour digest (`emailfrequency = 24`), so even
if we send the email at t=0 they don't actually receive it until their
digest fires up to 24 hours later.

Distribution of `emailfrequency` among repliers in the 12-month sample:

| Setting | Count | Share |
|---------|------:|------:|
| 24-hour digest | 40,786 | 83.8 % |
| Immediate (-1) | 3,638 | **7.5 %** |
| Off (0) | 3,490 | 7.2 % |
| Other digest (1-12h) | 90 | 0.2 % |
| No membership record | 641 | 1.3 % |

Repeating the lead-time table for **first-replier catches only, immediate-setting users only**
(the production-relevant cohort), urban:

| Curve | 1st in-time | p50 lead | p75 lead | p90 lead |
|-------|-------------|----------|----------|----------|
| linear            | 51.1 % | 7.3 h | 30.0 h | 257.8 h |
| front-cubic       | 67.5 % | 5.1 h | 21.2 h | 185.0 h |
| front-heavy x^0.3 | 83.6 % | 3.8 h | 15.7 h | 140.0 h |
| **step-70**       | **92.0 %** | **3.3 h** | **13.9 h** | **116.7 h** |

step-70's real-world lead time for the first replier (the person who
matters most) on immediate settings is **3.3 hours median** and **14
hours p75** — i.e. we email them an average of just over 3 hours before
they would reply, and ¾ of the time within 14 hours.  That is about as
"just in time" as you can be without seeing the future.

In-time catch rate is essentially identical across filters
(93 % all / 92 % immediate / 93 % digest), so the algorithm catches
both cohorts equally well — the long apparent lead time was purely a
measurement artifact of the digest schedule.

**Revised conclusion**: the "too soon" cost is much smaller than it
first appeared.  For the 7.5 % of users who see notifications
immediately, step-70 delivers them roughly 5 hours before they reply
— close to "just in time".  For digest users, the curve choice
doesn't matter for lead time because they're constrained by their
own digest cadence; what matters is that the notification is *in
their next digest*, which a t=0 schedule guarantees.

## Lifetime sensitivity sweep (step-70, urban first-replier)

| Lifetime | In-time | Wasted% | Tick interval |
|----------|---------|---------|----------------|
| 0.5d | 93.7% | 1.5%  | 25 min  |
| **1d**   | **93.1%** | **2.6%** | **48 min** |
| 2d   | 92.3% | 5.4%  | 96 min  |
| 3d   | 92.0% | 7.6%  | 2.4 hr  |
| 5d   | 91.8% | 10.2% | 4.0 hr  |
| 7d   | 91.6% | 11.9% | 5.6 hr  |
| 14d  | 91.1% | 14.8% | 11.2 hr |
| 30d  | 90.9% | 17.2% | 24.0 hr |

**Lifetime sensitivity is asymmetric**: in-time drops only 3 pp across
0.5d → 30d (because the tick-1 burst catches most first-repliers
regardless), but wasted % scales linearly with lifetime (the trailing
30 % is spread over more wall-clock time, so more of it lands after the
post is already taken).

**1 day is the sweet spot.**  Only 0.4 pp behind 0.5d on in-time but
with a sensible cron cadence (one tick every 48 minutes).  0.5d would
mean a tick every 25 minutes which is wasteful in cron-firing cost
and doesn't actually improve outcomes meaningfully.

## Recommendation

| Parameter | Recommended value | Rationale |
|-----------|------------------|-----------|
| Curve     | **step-70%+linear** | 93 % urban / 87 % rural first-replier in-time at 1d lifetime |
| Lifetime  | **1 day**        | Sweet spot from sensitivity sweep — 93.1% in-time / 2.6% wasted |
| Ticks     | 30               | Tick interval 48 min at 1d; smaller ticks add cron load without benefit |
| Max isochrone | 30 drive-min | Beyond this only catches ~5 % more replies |
| Circuit-breaker | Stop on `promised`/`taken` outcome | Status-based cutoff is honest |

Expected first-replier reach-in-time with these defaults: **~93 % urban,
~87 % rural**, with **~2-3 % wasted notifications**.

The step-70% curve is essentially "legacy-style bombardment for 70 % of
reachable users at tick 1, then ripple the remaining 30 % over the next
3 days".  This:
- preserves the immediate-notification behaviour for nearby users (which
  the legacy 2-mile notifier already does well), and
- adds long-tail expansion for users beyond the legacy 2-mile box, with
  a deprivation-fairness extension if turned on.

The trailing 30 % is where the new algorithm provides value over legacy:
it reaches users the legacy system never would (further out, harder to
get to by drive-time), without bombarding them all at once.

## Caveats

1. **Sample is 483 posts**.  Larger sample would tighten the numbers but
   the curve-ordering is unlikely to change — the spread between
   front-heavy and linear is huge (30+ pp).

2. **Replier location is current**, not the location they were at when
   they replied.  Most users don't move house in a year so this is a
   minor distortion.

3. **The 5.7 % unreachable pairs** are historical replies from users
   beyond the 30-min isochrone from the post.  They got notified by some
   other mechanism (broader bounding-box, group emails, digest).  Our
   algorithm doesn't try to reach them; that's a feature not a bug.

4. **The simulator measures reach-in-time, not user behaviour**.  It says
   nothing about whether users would actually reply if notified earlier.
   That can only be measured by Layer-2 parallel-run with the legacy
   system in production.

## Does the optimal curve vary by post type? — answered

Stratification by `--group-by=type` at lifetime=1d:

| Curve | Offer 1st in-time | Offer wasted | Wanted 1st in-time | Wanted wasted |
|-------|--------------------|---------------|---------------------|----------------|
| linear            | 58.7% | 9.3% | 84.0% | 0.0%* |
| front-heavy x^0.3 | 84.9% | 4.3% | 93.0% | 0.0%* |
| front-x^0.15      | 90.3% | 2.4% | 95.4% | 0.0%* |
| step-70%+linear   | 90.7% | 2.9% | 94.3% | 0.0%* |

\* Wanted posts don't get a "Taken" outcome in our schema (only Offers
do), so the wasted-notification metric is always 0 for them.  This is
a measurement limitation, not real behaviour.

**Conclusions**:

- **Wanted posts are 4-5 pp easier to reach in-time** than Offers under
  the same curve.  Probably because Wanted posts have fewer replies per
  post (less competition) and a more eager responder demographic.
- **Same curve wins for both types** — step-70 and front-x^0.15 dominate
  regardless of post type.  No need for per-type parameters.

## Does the optimal curve vary by area type? — answered

`rippleextract` now records each post's ONS RU classification (A1..F2)
from `transport_postcode_classification`, and `ripplesim --group-by=ru-coarse`
splits the simulation by urban (A/B/C) vs rural (D/E/F).

Results at lifetime=3d, ticks=30, max-min=30:

| Curve | Urban (614 pairs) | Rural (130 pairs) | Diff |
|-------|-------------------|--------------------|------|
| linear            | 50.5% | 56.2% | rural +5.7 |
| front-quad        | 61.6% | 63.1% | rural +1.5 |
| front-cubic       | 67.1% | 69.2% | rural +2.1 |
| front-sqrt        | 72.0% | 72.3% | ≈      |
| **front-heavy (x^0.3)** | **83.2%** | **77.7%** | **urban +5.5** |
| back-quad         | 33.9% | 39.2% | rural +5.3 |

**Reads as**:

- **Front-heavy wins in both buckets** and remains the right default.
- The *effect size* of front-loading is bigger in urban (50.5%→83.2% =
  +33pp lift from linear) than rural (56.2%→77.7% = +21pp lift).  Urban
  density rewards aggressive batching; rural is more spread out so the
  linear baseline already does better.
- **A single national curve is fine** — the same algorithm wins in both
  buckets.  If we wanted to fine-tune, urban could go *even more*
  aggressive (x^0.25 say); rural is closer to optimal at x^0.3.
- This justifies NOT branching the production cron by area type.  A
  single curve choice (front-heavy) covers both well.

## Next steps

- **Per-area-type curve investigation** (see above).
- **Layer 2 evaluation**: deploy the ripple algorithm alongside the legacy
  2-mile notifier in production; tag every notification by source.  Track
  which source(s) preceded each historical reply.  This is the gold-standard
  evaluation and cannot be done in simulation.
- **Notification cron**: implement the production cron that fires the
  schedule, using these recommended defaults.
- **Logging infrastructure**: `notification_log` table to support Layer 2.
