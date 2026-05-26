# Ripple Notification Algorithm — Curve Evaluation

Last updated: 2026-05-27.  Source data: 483 historical Freegle posts with
1,128 (post, replier) pairs extracted from production, evaluated via
`iznik-routing-go/cmd/ripplesim`.

## What the simulator measures

For each historical (post, replier) pair, we ask: if the new ripple
algorithm had been live at the time, would it have notified the eventual
replier *before* they actually replied?  We don't claim more replies would
have happened — only that the schedule that reliably hits this metric is
closer to the right shape.

The simulator does **not** depend on data from the algorithm being changed;
the only inputs are user reply locations and reply times, which are
algorithm-independent facts.  See `cmd/ripplesim/main.go` for details.

## Sample distribution (the constraint the algorithm has to fit)

From the 790 reachable (post, replier) pairs:

| Stat | Time-to-reply | Replier rank percentile | Replier drive-time |
|------|---------------|--------------------------|---------------------|
| min  | 13 min        | 0.00 (closest)           | 0.91 min            |
| p25  | 3 hr          | 0.12                     | 7.9 min             |
| p50  | 16 hr         | 0.29                     | 10.3 min            |
| p75  | 48 hr         | 0.56                     | 16.3 min            |
| p90  | 57 hr         | 0.73                     | 26.3 min            |
| max  | 242 hr (10 d) | 0.95                     | 28.4 min            |

**Reads as**: half of replies arrive within 16 hours, three-quarters within
48 hours.  Half come from users in the closest 29 % of reachable freeglers;
three-quarters from users within ~16 drive-min.  5.7 % of replies came from
users beyond the 30-min isochrone cap — unreachable by any version of this
algorithm with the current max.

## Reach-in-time sweep: curve × lifetime

Numbers are `% of reachable historical repliers our schedule would have
notified before their actual reply time` (higher is better; ceiling is
100 % minus the 5.7 % unreachable pool = 94.3 %).

| Curve              | 0.5d | 1d  | 2d  | 3d  | 5d  | 7d  | 14d | 30d |
|--------------------|------|-----|-----|-----|-----|-----|-----|-----|
| linear             | 72%  | 64% | 56% | 50% | 44% | 41% | 34% | 28% |
| front-quad (1-(1-x)²) | 78% | 72% | 64% | 60% | 56% | 52% | 44% | 38% |
| front-cubic (1-(1-x)³) | 81% | 76% | 70% | 66% | 61% | 58% | 52% | 45% |
| front-sqrt (x^0.5) | 83%  | 79% | 72% | 70% | 67% | 65% | 61% | 57% |
| **front-heavy (x^0.3)** | **88%** | **86%** | **83%** | **80%** | **79%** | **78%** | **77%** | **74%** |
| back-quad (x²)     | 61%  | 52% | 40% | 34% | 28% | 25% | 19% | 12% |

## Reading the table

1. **Front-heavy (x^0.3) dominates every column.**  Why: at x = 1/30 (tick 1
   of 30), x^0.3 = 0.367 — tick 1 covers 37 % of reachable users in one go.
   The same percentage regardless of lifetime, which is why front-heavy
   is roughly lifetime-insensitive.

2. **Linear degrades sharply with longer lifetimes** because the
   notification rate per tick is `total / N` — spreading the same 30 ticks
   over 30 days means each one fires ~1 day apart, missing the bulk of
   historical replies that arrive in the first 48 hours.

3. **Back-loaded is uniformly bad.**  Confirms intuition: delaying the
   first batch costs us in the short reply window.

4. **`stop@3replies` reduces the in-time metric** in the simulator
   because the simulator counts every historical replier including ones
   who arrived after we stopped.  In reality those later replies wouldn't
   have happened (the item is taken), so the metric is somewhat unfair to
   the circuit-breaker.  Circuit-breakers should be evaluated on
   notification-volume saving, not reach-in-time.

## Recommendation

| Parameter | Recommended value | Rationale |
|-----------|------------------|-----------|
| Curve     | **front-heavy (x^0.3)** | Highest reach-in-time at every lifetime; matches the observed shape of historical reply distance distribution (most repliers are close) |
| Lifetime  | **3 days**       | Captures p75 reply window; longer is wasted notification volume |
| Ticks     | 30               | Tick interval ~2.4 hr at 3-day lifetime; finer than that doesn't materially help in-time but adds load |
| Max isochrone | 30 drive-min | Beyond this only catches 5.7 % more replies; diminishing return on Dijkstra cost |
| Circuit-breaker | Stop on `promised`/`taken` (not on 3 replies alone) | Replies-count cutoff hurts reach-in-time; status-based cutoff is honest |

Expected reach-in-time with these defaults: **~80 %** (extrapolating from
the 80.4 % the simulator measured on the 483-post sample).

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
