# 5. Demand sufficiency — how much audience is ENOUGH?

**Scope:** OFFER posts on rippling_reach, live prod DB, sampled 2026-07-02. All SQL and figures below are
reproducible with the queries shown (read-only, `mysql:8` client via the V2 tunnel).

## 0. Data-quality gate (read this first — it changes the population)

`rippling_reach` has **one row per msgid** (PK on `msgid`), overwritten in place as a post's reach
expands tick-by-tick. On 2026-07-01 a **backfill job ran retrospectively** (`BackfillReachCommand` in
`iznik-batch`) and created placeholder rows for ~26,619 OLDER posts (some going back to 2020) with
`status='stopped', tick=0, total_freeglers=0` — these are NOT posts whose reach was intentionally
stopped at zero audience; they are pre-rippling posts touched by the backfill and never actually
expanded. 16,865 of them already have a `Taken`/`Received` outcome from years ago.

**Every query below therefore filters:**
```sql
NOT (r.status='stopped' AND r.tick=0)            -- excludes backfill placeholders
AND m.arrival >= '2026-06-23'                     -- TRUE post arrival (not rippling_reach.arrival,
                                                   -- which is overwritten each tick and can show a
                                                   -- 2026-07-01 backfill-run timestamp on a 2020 post)
```
`2026-06-23` is used (not `2026-06-14`) because daily volume of *genuinely* ripple-processed posts
jumps from ~60-110/day to 1,100-2,300/day exactly on that date — the real network-wide go-live; the
06-14 to 06-22 window was low-volume pilot/early-rollout traffic. This matches `enabled_at =
'2026-06-23'` in `config/freegle.php`'s `ripple.enabled_at` flood-guard cutoff.

**Resulting clean population:** 10,742 genuinely-rippled OFFER posts, arrival 2026-06-23 to
2026-07-02, mean audience (`total_freeglers`) 3,821.

Also fixed along the way: `groups` is a MySQL 8 reserved word (needs backticks); a `messages_groups`
join can return multiple rows per msgid for rippled-in cross-posted messages, so "home group" is taken
as `MIN(groupid)` among `collection='Approved'` rows (earliest/lowest id = the origin group in
practice); `chat_messages.type='Interested'` lookups need the existing `refmsgid` index and must be
pre-filtered through a temp table join (a raw CTE without materialisation was not using the index and
timed out).

## 1. Audience deciles vs replies

```sql
CREATE TEMPORARY TABLE t_base AS
SELECT r.msgid, r.total_freeglers, m.arrival AS post_arrival
FROM rippling_reach r JOIN messages m ON m.id = r.msgid
WHERE m.type='Offer' AND m.arrival >= '2026-06-23'
  AND NOT (r.status='stopped' AND r.tick=0);

CREATE TEMPORARY TABLE t_decile AS
SELECT msgid, total_freeglers, NTILE(10) OVER (ORDER BY total_freeglers) AS decile FROM t_base;

CREATE TEMPORARY TABLE t_replies AS
SELECT cm.refmsgid AS msgid, COUNT(DISTINCT cm.userid) AS repliers
FROM chat_messages cm JOIN t_base b ON b.msgid = cm.refmsgid
WHERE cm.type='Interested' GROUP BY cm.refmsgid;

SELECT d.decile, COUNT(*) n_posts, MIN(d.total_freeglers) min_aud, MAX(d.total_freeglers) max_aud,
  ROUND(AVG(d.total_freeglers)) avg_aud, ROUND(AVG(COALESCE(rp.repliers,0)),3) mean_repliers,
  ROUND(100*AVG(COALESCE(rp.repliers,0)>=1),1) pct_ge1,
  ROUND(100*AVG(COALESCE(rp.repliers,0)>=3),1) pct_ge3,
  ROUND(100*AVG(COALESCE(rp.repliers,0)>=5),1) pct_ge5
FROM t_decile d LEFT JOIN t_replies rp ON rp.msgid = d.msgid
GROUP BY d.decile ORDER BY d.decile;
```

| decile | n_posts | audience range | avg audience | mean repliers | P(≥1) | P(≥3) | P(≥5) |
|---|---|---|---|---|---|---|---|
| 1 | 1,075 | 1–695 | 353 | 0.512 | 31.5% | 3.9% | 0.7% |
| 2 | 1,075 | 695–1,102 | 916 | 0.563 | 33.5% | 5.7% | 0.7% |
| 3 | 1,074 | 1,102–1,575 | 1,327 | 0.613 | 35.8% | 6.8% | 1.0% |
| 4 | 1,074 | 1,575–1,972 | 1,788 | 0.559 | 33.2% | 5.3% | 0.9% |
| 5 | 1,074 | 1,972–2,485 | 2,205 | 0.599 | 35.9% | 5.1% | 0.8% |
| 6 | 1,074 | 2,487–3,348 | 2,917 | 0.554 | 33.0% | 5.4% | 0.8% |
| 7 | 1,074 | 3,351–4,229 | 3,726 | 0.650 | 38.5% | 6.7% | 0.7% |
| 8 | 1,074 | 4,231–6,131 | 5,136 | 0.541 | 30.8% | 6.1% | 0.7% |
| 9 | 1,074 | 6,131–9,586 | 7,643 | 0.418 | 23.6% | 4.0% | 0.7% |
| 10 | 1,074 | 9,586–17,656 | 12,206 | 0.404 | 22.1% | 4.7% | 1.0% |

**Reading it:** mean repliers and P(≥1 reply) are essentially FLAT from decile 1 through decile 8
(0.51–0.65 repliers, 31–39% chance of ≥1 reply) despite a **12x** audience range (353 → 5,136 avg). In
deciles 9–10 (the very largest audiences, 7,600–12,200 avg) the reply rate is **lower**, not higher, than
the middle deciles — the biggest-reach posts are disproportionately in saturated dense areas competing
for the same small pool of active repliers per unit area, or arriving in bursts that dilute individual
post visibility.

**P(≥5 replies) never exceeds 1.0% in any decile.** Sanity check on the whole clean population (not
just deciles): only **89 of 10,745** genuinely-rippled posts (0.83%) ever reach 5 distinct repliers,
at ANY audience size up to 17,656. This directly bears on a config value already live in the codebase:
`ripple.reply_saturation_stop = 5` (`config/freegle.php`, "5 = the figure from the Discourse rippling
thread") is meant to stop expansion once a post has 5 repliers. **It has never fired on any real post in
production** — confirmed by querying for `status='stopped'` rows with `total_freeglers > 0` (zero
results; every `stopped` row is the tick=0 backfill artifact). A threshold of 5 is set far above what
demand ever produces, so today it is a dead parameter, not a live governor.

### Finer-grained bins at the low-audience end (deciles are coarse where the action is)

```sql
SELECT CASE WHEN b.total_freeglers < 250 THEN '0-250' WHEN ... END AS aud_bucket,
  COUNT(*), ROUND(AVG(total_freeglers)), ROUND(AVG(COALESCE(rp.repliers,0)),3), ...
FROM t_base b LEFT JOIN t_replies rp ON rp.msgid=b.msgid GROUP BY aud_bucket;
```

| audience bucket | n_posts | avg audience | mean repliers | P(≥1) | P(≥2) | P(≥3) |
|---|---|---|---|---|---|---|
| 0–250 | 355 | 131 | 0.431 | 27.0% | 10.4% | 3.1% |
| 250–500 | 447 | 379 | 0.494 | 31.8% | 11.4% | 2.9% |
| 500–1,000 | 1,001 | 780 | 0.581 | 34.2% | 14.3% | 6.1% |
| 1,000–1,500 | 1,312 | 1,237 | 0.602 | 35.2% | 14.6% | 6.4% |
| 1,500–2,000 | 1,265 | 1,778 | 0.557 | 33.1% | 11.9% | 5.4% |
| 2,000–3,000 | 1,638 | 2,423 | 0.585 | 35.2% | 14.3% | 5.1% |
| 3,000–4,000 | 1,332 | 3,505 | 0.608 | 35.5% | 13.9% | 6.5% |
| 4,000–6,000 | 1,176 | 4,927 | 0.565 | 32.7% | 12.8% | 6.2% |
| 6,000–9,000 | 986 | 7,272 | 0.453 | 26.2% | 10.3% | 4.4% |
| 9,000–13,000 | 858 | 10,710 | 0.347 | 18.6% | 7.2% | 3.6% |
| 13,000+ | 375 | 14,372 | 0.499 | 27.2% | 12.8% | 6.1% |

**Where the curve flattens:** it is already essentially flat from the very smallest observed bucket.
Even a **131-member audience** returns 27% P(≥1 reply) and 0.43 mean repliers — not dramatically below
a 12,000-member audience (22-27% P(≥1), 0.40-0.50 mean repliers). The demand curve doesn't have a
clean knee at some large N — it rises gently from ~0 to ~1,000-1,500 members, plateaus 1,000-6,000,
and mildly DECLINES 6,000-13,000 before a noisy uptick in the top bucket (small n=375, likely dominated
by a few very dense/London posts with unusually high engagement — treat with caution). There is no
audience size in this data at which adding more members reliably buys more replies.

## 2. Outcome (Taken) vs audience decile — posts ≥14 days old

Caveat up front: because real (post-06-23) volume only exceeds 1,000/day from 06-23, and "today" in
the live DB is 2026-07-02, there are almost no genuinely-rippled posts yet 14 days old. Relaxing to
`arrival >= 2026-06-14` (the earlier, low-volume pilot window) gives a usable but SMALL sample.

```sql
CREATE TEMPORARY TABLE t_base14 AS
SELECT r.msgid, r.total_freeglers
FROM rippling_reach r JOIN messages m ON m.id = r.msgid
WHERE m.type='Offer' AND m.arrival >= '2026-06-14'
  AND m.arrival <= DATE_SUB(NOW(), INTERVAL 14 DAY)
  AND NOT (r.status='stopped' AND r.tick=0);
-- ... NTILE(10), LEFT JOIN messages_outcomes (MAX(outcome='Taken'))
```

Population: **429 posts**, arrival 2026-06-14 to 2026-06-18 only.

| decile | n_posts | avg audience | posts w/ any outcome row | % Taken (of ALL posts in decile) |
|---|---|---|---|---|
| 1 | 43 | 297 | 6 | 14.0% |
| 2 | 43 | 774 | 13 | 30.2% |
| 3 | 43 | 1,124 | 8 | 18.6% |
| 4 | 43 | 1,419 | 8 | 18.6% |
| 5 | 43 | 1,812 | 7 | 16.3% |
| 6 | 43 | 2,582 | 10 | 23.3% |
| 7 | 43 | 3,414 | 5 | 11.6% |
| 8 | 43 | 3,922 | 7 | 16.3% |
| 9 | 43 | 6,150 | 6 | 14.0% |
| 10 | 42 | 11,693 | 8 | 19.0% |

No monotonic trend, no visible relationship between audience size and Taken rate — consistent with the
reply-rate finding above (if replies don't increase with audience, downstream Taken outcomes shouldn't
either). **However this table is underpowered** (n=42-43/decile, ~15-30% report any outcome at all —
most posters never mark an outcome) and should not be treated as more than directionally consistent
with §1. It does NOT contradict §1; it simply can't independently confirm it yet. Re-run this query
after ~2026-07-15 once real 06-23+ volume clears the 14-day mark (n will be ~10,000+, one to two orders
of magnitude more powered).

## 3. Speed: time to first Interested reply, by audience decile

```sql
CREATE TEMPORARY TABLE t_ttr AS
SELECT d.decile, d.msgid, TIMESTAMPDIFF(MINUTE, d.post_arrival, fr.first_reply) AS mins_to_reply, ...
FROM t_decile d JOIN t_replies fr ON fr.msgid = d.msgid
WHERE TIMESTAMPDIFF(MINUTE, d.post_arrival, fr.first_reply) >= 0;   -- excludes backfill-artifact negatives
```
(0 rows excluded as negative once the `m.arrival >= 2026-06-23` filter above is applied — the negative
timestamps only appeared when `rippling_reach.arrival`, which can be a stale/overwritten tick
timestamp, was used instead of `messages.arrival`.)

| decile | n with reply | mean mins to 1st reply | median mins to 1st reply |
|---|---|---|---|
| 1 | 339 | 1,094 | 513 |
| 2 | 360 | 963 | 404 |
| 3 | 384 | 836 | 347 |
| 4 | 357 | 987 | 372 |
| 5 | 386 | 926 | 286 |
| 6 | 354 | 963 | 259 |
| 7 | 414 | 818 | 175 |
| 8 | 331 | 832 | 262 |
| 9 | 253 | 840 | 141 |
| 10 | 237 | 789 | **174** |

**This is the one metric that DOES move cleanly with audience size.** Median time-to-first-reply drops
from **513 minutes (decile 1, ~350 avg audience) to 141-174 minutes (deciles 9-10, ~7,600-12,200 avg
audience)** — roughly a 3x speedup. Combined with §1 (eventual reply COUNT is flat), the conclusion is:

**Bigger audience mainly buys SPEED, not eventual success.** A post that would eventually get ~0.5-0.6
replies gets that first reply faster with a bigger audience, but does not get MORE total replies. This
matters for the design: if speed-to-first-reply is the actual value being purchased by extra reach
(quicker collection, less time an item sits around), the stopping rule should be framed around "how much
speed is worth the marginal notification cost" rather than "how many more replies". Diminishing returns
on speed likely also flatten well before 30 min — the curve above still shows *some* speed gain out to
decile 10, but the deceleration is visible (decile 1→3: 513→347 mins, -166; decile 8→10: 262→174, -88 —
roughly half the per-decile speed gain in the tail vs the head).

## 4. Where replies come from: home group vs beyond

```sql
CREATE TEMPORARY TABLE t_home AS
SELECT mg.msgid, MIN(mg.groupid) AS home_groupid FROM messages_groups mg
JOIN t_base b ON b.msgid = mg.msgid WHERE mg.collection='Approved' GROUP BY mg.msgid;

-- distinct (msgid,userid) Interested repliers, joined to memberships on the home group
SELECT COUNT(*) total_distinct, SUM(is_home_member) home_distinct,
  ROUND(100*SUM(is_home_member)/COUNT(*),1) pct_home
FROM (SELECT DISTINCT msgid, userid, is_home_member FROM t_reply_home) x;
```

**82.5%** of distinct Interested repliers (4,794 / 5,813) are members of the post's home group; the
remaining **17.5%** are reached only via rippling (rippled-in, non-home-group members). This is a
**meaningful drop from the ~98% figure in the prior (pre-2026-06-22) measurement** — worth flagging
explicitly since the task brief asked whether it has moved:

- Rippling has now been live at full volume for ~9 days at time of sampling (vs. essentially day-zero /
  low-volume-pilot when ~98% was measured), so more genuinely-rippled-in replies have had time to
  accumulate.
- 17.5% "beyond home group" is a much larger fraction of value overall than the earlier reading
  suggested — it says rippling IS finding real incremental demand beyond the home group, just that
  (per §1) that incremental demand doesn't keep growing once the reach is already a few hundred to a
  few thousand members.

**By audience decile** (does the home/beyond mix shift with reach size?):

| decile | total reply rows | home-group reply rows | % home |
|---|---|---|---|
| 1 | 578 | 504 | 87.2% |
| 2 | 636 | 528 | 83.0% |
| 3 | 685 | 558 | 81.5% |
| 4 | 628 | 535 | 85.2% |
| 5 | 676 | 584 | 86.4% |
| 6 | 624 | 481 | 77.1% |
| 7 | 733 | 604 | 82.4% |
| 8 | 631 | 527 | 83.5% |
| 9 | 472 | 380 | 80.5% |
| 10 | 462 | 353 | 76.4% |

Mild downward drift (87% → 76%) from smallest to largest audience deciles — larger reach does pull in a
slightly higher proportion of non-home-group repliers, as expected, but the effect is small (11
percentage points across a 35x audience range) and the ABSOLUTE count of beyond-home-group replies
barely changes (74 in decile 1 vs 109 in decile 10) because total replies per post are themselves flat
or falling in the big-audience deciles (§1).

## 5. Overshoot cost: notifications per incremental distinct replier

```sql
CREATE TEMPORARY TABLE t_notified AS
SELECT rn.msgid, COUNT(*) n_notified FROM rippling_reach_notified rn
JOIN t_base b ON b.msgid = rn.msgid GROUP BY rn.msgid;
-- decile-level: SUM(notified)/SUM(repliers) per decile
```

| decile | n_posts | avg audience | avg notified/post | avg repliers/post | notified per replier (decile total) |
|---|---|---|---|---|---|
| 1 | 1,075 | 353 | 19 | 0.512 | **36.3** |
| 2 | 1,075 | 916 | 21 | 0.563 | 37.3 |
| 3 | 1,074 | 1,327 | 37 | 0.613 | 60.4 |
| 4 | 1,074 | 1,788 | 30 | 0.559 | 53.2 |
| 5 | 1,074 | 2,205 | 32 | 0.599 | 54.2 |
| 6 | 1,074 | 2,917 | 39 | 0.554 | 70.3 |
| 7 | 1,074 | 3,726 | 44 | 0.650 | 67.7 |
| 8 | 1,074 | 5,136 | 46 | 0.541 | 85.9 |
| 9 | 1,074 | 7,643 | 46 | 0.418 | 109.1 |
| 10 | 1,074 | 12,206 | 45 | 0.404 | **112.3** |

**The marginal cost curve rises monotonically and roughly TRIPLES** from decile 1 (36 notifications
sent per distinct replier obtained) to decile 10 (112 notifications per replier). Note `avg_notified`
per post plateaus around 40-46 from decile 3 onward (`rippling_reach_notified` tracks direct-mail/push
notification sends, which are themselves capped/throttled independent of raw polygon audience size —
so this is not literally "everyone in total_freeglers gets notified", it's the subset actually mailed).
Even on that already-throttled notification count, the cost-per-outcome still climbs sharply because the
denominator (repliers) FALLS in the large-audience deciles while the notification count stays flat or
rises slightly. This is the clearest single number for "how much is enough": **every notification sent
beyond roughly decile 2-3 (audience ≈ 1,000-1,500) buys measurably less marginal reply than the
notifications already sent**.

## 6. Within-density-class comparison (controls for the exposure confound)

The brief's caveat — audience size correlates with density which correlates with everything — is real,
so deciles above could in principle just be re-discovering "dense areas behave differently" rather than
"audience size itself doesn't matter". To check, posts were bucketed into **terciles of home-group
active-member count** (memberships with `lastaccess` in the last 90 days, `collection='Approved'`),
then WITHIN each density tercile, split into quintiles by the post's own `total_freeglers` audience:

```sql
CREATE TEMPORARY TABLE t_group_active AS
SELECT mem.groupid, COUNT(DISTINCT mem.userid) active_members FROM memberships mem
JOIN users u ON u.id = mem.userid
WHERE mem.collection='Approved' AND u.lastaccess >= DATE_SUB(NOW(), INTERVAL 90 DAY) AND u.deleted IS NULL
GROUP BY mem.groupid;
-- NTILE(3) on active_members -> density_tercile; NTILE(5) PARTITION BY density_tercile ON total_freeglers -> sub_quintile
```

| density tercile (home-group active members) | audience sub-quintile | n_posts | avg audience | mean repliers | P(≥1) |
|---|---|---|---|---|---|
| 1 (34–1,000 active) | 1 | 717 | 265 | 0.481 | 31.2% |
| 1 | 2 | 716 | 770 | 0.527 | 31.8% |
| 1 | 3 | 716 | 1,224 | 0.543 | 29.3% |
| 1 | 4 | 716 | 1,882 | 0.489 | 28.9% |
| 1 | 5 | 716 | 3,444 | 0.503 | 31.0% |
| 2 (1,000–1,726 active) | 1 | 717 | 1,021 | 0.696 | **41.3%** |
| 2 | 2 | 716 | 1,815 | 0.574 | 34.5% |
| 2 | 3 | 716 | 2,801 | 0.527 | 33.5% |
| 2 | 4 | 716 | 4,069 | 0.704 | 37.6% |
| 2 | 5 | 716 | 7,715 | 0.415 | **24.7%** |
| 3 (1,726–3,469 active, densest) | 1 | 716 | 1,731 | 0.676 | **38.8%** |
| 3 | 2 | 716 | 3,345 | 0.564 | 35.8% |
| 3 | 3 | 716 | 5,572 | 0.585 | 31.6% |
| 3 | 4 | 716 | 8,588 | 0.436 | 25.1% |
| 3 | 5 | 716 | 13,082 | 0.401 | **21.8%** |

**This is the strongest single result in the dataset.** WITHIN every density tercile, the SMALLEST
audience sub-quintile of that tercile does at least as well (density tercile 1, roughly flat) or
noticeably BETTER (density terciles 2 and 3: sub-quintile 1 has the HIGHEST P(≥1) of all 5, and
sub-quintile 5 — the largest audience for that density class — has the LOWEST) than the largest audience
sub-quintile. The exposure confound is not driving the flat/declining curve in §1 — it survives
controlling for density. If anything, within dense areas (tercile 3), pushing audience from ~1,700 to
~13,000 members actively HALVES the reply probability (38.8% → 21.8%), which is exactly the London
over-ripple complaint pattern from Discourse #9808/250, now visible directly in the reply data, not just
member-count spread.

## Bottom line

**"The audience beyond which marginal replies ≈ zero, by density class":**

| density class (home-group active members) | audience where reply-rate ADDS anything | audience where it visibly HURTS |
|---|---|---|
| Sparse (< ~1,000 active) | flat throughout observed range (~250–3,400); no clear ceiling reached, small samples get roughly the same P(≥1) as large ones | not observed in this data (ceiling ~3,400, driven by drive-time not sparse density) |
| Medium (~1,000–1,700 active) | best P(≥1) at ~1,000 audience (41%); no gain by 1,800–2,800 | clearly worse by ~7,700 audience (25%, down from 41%) |
| Dense (> ~1,700 active, e.g. London) | best P(≥1) at ~1,700 audience (39%); no gain by 3,300–5,600 | clearly worse by 8,600+ (25%) and worst at 13,000+ (22%) |

**A single principled number, if the parameter must be one N\*:** somewhere in the **~1,000–2,000
member** range captures essentially all of the achievable reply probability for every density class
observed; by ~5,000-6,000 the curve has already started falling in medium/dense areas, and by
~9,000-13,000+ it is clearly worse than sending fewer notifications would have been. This is
consistent with — and now empirically calibrates — the previously-uncalibrated `extent.target_users`
default of 4,000 in `config/freegle.php` (`RIPPLE_EXTENT_TARGET_USERS`, currently `enabled=false`,
dark): 4,000 sits past the point where reply probability peaks (which is nearer 1,000-1,700) but before
the point where it visibly degrades (~6,000+), so it is a reasonably conservative single global
starting value, though the density-stratified table above suggests urban/dense classes could bind
noticeably tighter (~1,500-2,000) without losing measurable reply probability, which is exactly the
`target_by_ru` per-RU-class stratification the MVP plan already earmarks as the next step.

The one place a bigger audience clearly still pays for itself is **speed** (§3: 3x faster first reply
from smallest to largest decile), so a stopping rule keyed purely to "P(reply) has flattened" would
sacrifice that speed benefit; a design that stops audience growth once expected marginal reply
probability drops below some threshold, but explicitly also considers or logs the speed benefit
foregone, would be more defensible than optimising on reply-count alone.

## Caveats carried through

- **Exposure confound**: addressed directly in §6 (within-density stratification); the flat/declining
  curve survives controlling for density, so it is not simply "big audience = dense area = different
  behaviour for unrelated reasons".
- **Outcome data (§2) is underpowered** (n=429, mostly <1 day since 14-day-old real-rippling volume
  became available) — re-run after 2026-07-15+ for a properly powered check.
- **`reply_saturation_stop=5` has never fired in prod** — either disabled or set far above what real
  demand produces (only 0.83% of posts ever reach 5 repliers at any audience size). If this parameter
  is intended as a working governor, 5 is not currently doing anything; a threshold nearer 2-3 would
  bind on a non-trivial share of posts (§1: P(≥3) is 4-8% across deciles vs P(≥5) at ~1%).
- **Notification counts in `rippling_reach_notified` are already throttled** independent of raw
  polygon/`total_freeglers` size (avg notified plateaus ~40-46/post from decile 3 up even as audience
  keeps growing 10x) — so §5's cost curve is a lower bound on true overshoot waste; the WASTED reach
  (members who were IN the polygon and eligible but never actually mailed) is larger still and not
  visible in this table.
- **Backfill contamination (§0)** was significant (56% of all rippling_reach rows) and, if not filtered,
  would have produced nonsensical results (negative "time to reply", diluted deciles, wrong "N* already
  low" conclusion driven by zero-audience placeholders). Any future query against `rippling_reach` MUST
  apply the same two filters or re-derive an equivalent freshness check, since the backfill job is not a
  one-off — check whether `BackfillReachCommand` runs again before reusing these numbers unfiltered.
