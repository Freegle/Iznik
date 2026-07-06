# Rippling reach: audience-vs-drive-time curves from real persisted schedules

Dark-compute of the extent-governor MVP (audience-sized burst, N\* target) directly from
`rippling_reach.schedule`, which already stores each post's real routing-server tick schedule
(`drive_min`, `cumulative_users` per tick). No new instrumentation, no implementation — this is a
read-only replay of production data to answer: **what would different N\* choices actually produce,
geographically, right now?**

Data pulled from live prod DB, 2026-07-02.

---

## 0. Method and data caveats

- `rippling_reach` has 50,831 rows total (data starts 2026-06-22 — 10 days of rippling-live traffic,
  not the 3-4 weeks originally asked for; that's all that exists post-launch).
- `status`: `stopped` = 26,619 rows (52%), ALL with `schedule IS NULL` / `total_freeglers = 0` — these
  never got a first tick at all (killed immediately: not an Offer, no usable location, reach-gated for
  other reasons — see `finding_reach_gate*` prior work). They carry no audience-curve information and
  are excluded from the curve analysis below (but see §3c for what this means for "never fills").
- `status IN ('done','expanding')` = 24,213 rows **with a real schedule** — this is the analysis
  population. `done` (11,994) completed all `total_ticks=9` scheduled ticks (fixed schedule design,
  confirmed: every post uses exactly 9 ticks). `expanding` (12,219) is mid-flight, still dwell-gated
  waiting for its next tick (all 12,219 have a future `next_expansion_at`, none stalled).
- For "current final audience" stats (§1) I used **`done`-only** (unbiased, fully expanded) —
  12,219 `expanding` rows would understate audience since they haven't finished. In practice the two
  populations are statistically close (p50 2,457 done vs 2,314 expanding-so-far), because most
  `expanding` posts already stopped early via the reply-stop/dwell gate rather than being far from done.
- For "drive-time to cross N\*" (§2-4) I used **all 24,213** posts' curves — reading a curve up to
  whatever tick a post has currently reached is valid data regardless of whether the post has finished;
  it only under-counts "never reached N\*" slightly for `expanding` posts that might still cross N\*
  on a future tick. Flagged where relevant.
- Confirmed ceiling: **max_drive_min caps at exactly 30.0 minutes** (not 45 — the 45min figure in the
  brief was speculative and is not what's live). `done` posts range from 13.5 to 30.0 min drive-time
  (reply-stop / audience plateau can end expansion well before the ceiling even today).
- Home group joins: `messages_groups` WHERE `rippled_in=0` (the group the post originated in, i.e. not
  a group it rippled into) — 24,201/24,213 (99.95%) resolved to exactly one home group.
- All SQL/JS shown below; raw intermediate files in this directory (`curves_raw.tsv`,
  `msg_home_group.tsv`, `results.json`, `group_stats.json`, `notified_counts.tsv`).

### Extraction SQL (avoids ever selecting the huge `wkt` polygon field)

```sql
-- Schema check
DESCRIBE rippling_reach;
-- schedule shape confirmed: [{"tick":1,"drive_min":15.88,"cumulative_users":2800,"wkt":"POLYGON(...)"}...]

-- Curves: pull ONLY drive_min/cumulative_users arrays via JSON_EXTRACT, never the wkt
SELECT r.msgid, r.lat, r.lng, r.total_freeglers, r.max_drive_min, r.status,
  JSON_EXTRACT(r.schedule, '$[*].drive_min')        AS dm,
  JSON_EXTRACT(r.schedule, '$[*].cumulative_users')  AS cu
FROM rippling_reach r
WHERE r.status IN ('done','expanding') AND r.schedule IS NOT NULL;
-- 24,213 rows

-- Home group per message (origin group, not rippled-in)
SELECT mg.msgid, mg.groupid, g.nameshort, g.region
FROM messages_groups mg
JOIN rippling_reach r ON r.msgid = mg.msgid AND r.status IN ('done','expanding') AND r.schedule IS NOT NULL
JOIN `groups` g ON g.id = mg.groupid
WHERE mg.rippled_in = 0;

-- Notification fan-out
SELECT msgid, COUNT(*) AS n FROM rippling_reach_notified GROUP BY msgid;
-- 18,143 distinct msgids, 930,344 total notification rows
```

### N\*-crossing JS (linear interpolation between ticks, origin assumed (0,0))

```js
function driveTimeToReachN(post, N) {
  const ticks = post.ticks; // [{drive_min, cumulative_users}, ...] sorted by tick
  if (ticks[0].cumulative_users >= N) {
    if (ticks[0].cumulative_users === 0) return ticks[0].drive_min;
    return ticks[0].drive_min * (N / ticks[0].cumulative_users); // interpolate from origin
  }
  for (let i = 1; i < ticks.length; i++) {
    if (ticks[i].cumulative_users >= N) {
      const t0 = ticks[i-1], t1 = ticks[i];
      const frac = (N - t0.cumulative_users) / (t1.cumulative_users - t0.cumulative_users);
      return t0.drive_min + frac * (t1.drive_min - t0.drive_min);
    }
  }
  return null; // never reached within the post's observed ticks
}
```

---

## 1. Distribution of total audience at current full reach (30-min ceiling)

`done`-only, n=11,994 (fully expanded posts, unbiased):

| Percentile | total_freeglers |
|---|---|
| p10 | 602 |
| p25 | 1,257 |
| p50 | 2,457 |
| p75 | 4,743 |
| p90 | 8,696 |
| p99 | 15,012 |
| max | 17,776 |
| mean | 3,656 |

**Spread confirmed and quantified precisely**: p90/p10 = **14.4x**, max/p10 = **29.5x**. (All
24,213 done+expanding posts together: max/p10 = 34.0x, p90/p10 = 16.3x — consistent.) This is somewhat
lower than the ~80x figure quoted from the prior "active-member-pool" measurement, because
`total_freeglers` here is the routing server's *realized, eligibility-filtered* audience at the fixed
30-min polygon (already excludes ineligible/duplicate/opted-out members etc.), whereas the ~80x figure
was the raw active-member-pool-within-30-min-drive (p50 2,900 → max 15,236). Both point the same
direction — a large, real, order-of-magnitude spread in what "30 minutes" delivers depending on area —
just measured at different pipeline stages. The routing-server output (this data) is the more direct
answer to "what audience does the CURRENT rule actually produce", so treat 14-30x as the operative
figure, not 80x.

---

## 2. Drive-time to cross candidate N\* targets (dark-computed governor MVP)

All 24,213 posts with curves. For each N\*, the drive-time at which cumulative_users first reaches N\*
(interpolated), and the fraction of posts that **never** cross it within their observed schedule
(would ride to the ceiling under an audience-sized-burst rule with that N\*).

| N\* | % posts reaching N\* | drive-time p10 | p25 | p50 | p75 | p90 | p99 | mean | % NEVER reach (→ ceiling) |
|---|---|---|---|---|---|---|---|---|---|
| 1,000 | 81.1% | 6.2 | 7.6 | **11.2** | 18.5 | 25.7 | 29.6 | 13.6 | 18.9% |
| 2,000 | 57.3% | 11.9 | 13.8 | **17.4** | 24.3 | 28.2 | 29.8 | 18.8 | 42.7% |
| 3,000 | 42.0% | 16.7 | 18.8 | **22.3** | 26.1 | 28.5 | 29.9 | 22.4 | 58.0% |
| 4,000 | 30.0% | 17.9 | 20.3 | **23.3** | 26.7 | 28.6 | 29.9 | 23.3 | 70.0% |
| 6,000 | 0.004% (1 post) | — | — | — | — | — | — | — | ~100% |
| 8,000 | 0% | — | — | — | — | — | — | — | 100% |

**Reading this**: at N\*=2000, half of all posts would already be satisfied by ~17 minutes (well under
today's fixed 30), but 43% of posts would never reach 2,000 people at all and would ride to whatever
ceiling is set regardless — this population is intrinsically low-density and N\* doesn't discriminate
for them (a ceiling, not N\*, controls their behaviour). N\*=6000/8000 are **not usable as global
targets** — essentially no post in the current network reaches that audience even at 30 min, so those
values would degenerate to "always ceiling" for ~100% of posts, i.e. no different from today for nearly
everyone. Usable N\* range for a "burst until crowd is big enough" rule, given today's network density,
is roughly **1,000-4,000**.

---

## 3. Exemplar groups — dark-computed governor outcomes

Sample sizes are posts-in-window (2026-06-22 to 2026-07-02) attributed to each **home** group.
"NW-London Offers" is not an actual group name in the DB (likely informal/Discourse shorthand); the
London boroughs bordering the Chilterns-to-East-London complaint corridor (Brent, Ealing, Harrow-area)
are covered by the region-level and top-group tables in §3c/§3d, which validate the complaint directly.

| Group | n | current audience p50 (@ 30min ceiling) | current drive_min p50 | N\*=2000 drive-time p50 | N\*=2000 never-reach % | N\*=4000 drive-time p50 | N\*=4000 never-reach % |
|---|---|---|---|---|---|---|---|
| Hull (21473) | 51 | 659 | 30.0 | — | **100%** | — | **100%** |
| Oxford (21555) | 509 | 3,829 | 30.0 (p10=28.4) | 14.4 | 0.6% | 28.7 | 74.1% |
| Ribble Valley (21589) | 15 | 1,446 | 30.0 | 27.5 | **80%** | — | **100%** |
| Tower Hamlets (21662) | 65 | 13,336 | **17.8** | **11.3** | 0% | **17.8** | 1.5% |
| Swindon (92103) | 52 | 672 | 30.0 | — | **100%** | — | **100%** |
| Edinburgh (21354) | 414 | 2,144 | 30.0 | 26.5 | 23.4% | — | **100%** |

**This is the direct dark-compute of the complaint.** Tower Hamlets already stops expanding at a p50
of 17.8 minutes today (the extent-governor's existing reply-stop/dwell gate is doing *some* of this
work already, on top of the fixed 30-min ceiling) — but its audience at that point is 13,336, ~6x
Oxford's full-ceiling audience and ~20x Hull/Swindon's. An N\*=2000 rule would cut Tower Hamlets to
~11.3 minutes (a further ~6.5min tightening from its already-gated 17.8) while leaving Hull, Swindon,
and Ribble Valley completely unaffected (they never reach 2,000 people regardless — they're
ceiling-bound, not N\*-bound).

---

## 3c. Full network view: who never fills at N\*=2000 (rural/dense split)

Across all 354 home groups with ≥10 posts in the sample, grouped by median audience at full reach:

**15 lowest-audience groups** (all 100% never-reach-N\*=2000, i.e. every single post from these areas
would ride to whatever ceiling is chosen, at N\* up to at least 2,000):

| Group | n | audience p50 |
|---|---|---|
| Anglesey | 16 | 50 |
| Halesworth | 27 | 54 |
| North Northumberland | 15 | 71 |
| Ayr | 12 | 97 |
| West Norfolk/March | 29 | 98 |
| Denbighshire | 11 | 104 |
| Perth and Kinross | 42 | 125 |
| Barrow | 15 | 155 |
| Penzance | 31 | 164 |
| St Austell | 20 | 167 |
| Hungerford | 10 | 175 |
| Bishop's Castle | 50 | 177 |
| Neath Port Talbot | 12 | 177 |
| Bangor | 48 | 184 |
| Cromer/Sheringham | 13 | 189 |

**15 highest-audience groups** (all 0% never-reach; would be tightened by any N\* ≤ 4,000) — every
single one is inner/west London:

| Group | n | audience p50 |
|---|---|---|
| Hammersmith & Fulham | 106 | 14,792 |
| Kensington & Chelsea | 78 | 14,204 |
| Tower Hamlets | 65 | 13,336 |
| Camden South | 11 | 13,038 |
| Islington South | 35 | 12,856 |
| Ealing | 126 | 12,811 |
| Wandsworth | 201 | 12,639 |
| Lambeth | 126 | 12,579 |
| Hackney | 89 | 12,569 |
| Westminster | 74 | 12,544 |
| Brent | 98 | 12,544 |
| Barnet | 108 | 12,012 |
| Islington North | 20 | 11,601 |
| Islington West | 35 | 11,433 |
| Southwark | 115 | 11,380 |

**124 of 354 groups (35%) with ≥10 posts have 100% of their posts never reaching N\*=2000** — these are
uniformly rural/small-town groups (Wales, Scottish Highlands/Perthshire, Cornwall, Northumberland, East
Anglia coast). For these, **N\* choice in the 1,000-4,000 range is irrelevant**: only the ceiling
(currently 30 min) governs their reach, because there simply isn't enough population density within
any plausible drive-time. A governor that only changes N\* (leaving the 30-min ceiling untouched) does
nothing for the *low* end of the complaint spectrum (nobody there is complaining — they want more
reach if anything, not less) — its whole effect is concentrated on the dense/urban tail, which is
exactly the target of the Discourse complaints.

## 3d. Region-level rollup (proxy for ONS rural-urban class, since that field isn't populated here)

| Region | n | audience p50 (full ceiling) | N\*=2000 drive-time p50 | % never reach 2000 |
|---|---|---|---|---|
| London | 3,652 | 9,897 | **13.0** | **0%** |
| Yorkshire & Humber | 1,456 | 3,181 | 17.4 | 35% |
| South East | 5,916 | 2,528 | 19.5 | 38% |
| East | 2,926 | 2,402 | 19.8 | 41% |
| West Midlands | 1,942 | 1,888 | 16.7 | 52% |
| North West | 2,936 | 1,875 | 17.9 | 52% |
| East Midlands | 1,402 | 1,972 | 24.2 | 53% |
| Scotland | 977 | 1,636 | 26.8 | 61% |
| North East | 342 | 1,438 | 26.2 | 75% |
| South West | 2,076 | 1,329 | 26.5 | 79% |
| Wales | 523 | 242 | 29.5 | 99% |
| Northern Ireland | 51 | 649 | N/A | 100% |

London is categorically different from every other region: it's the only region where **0%** of posts
fail to reach N\*=2000, and its median drive-time to do so (13.0 min) is barely half of the next-lowest
region. Every other English region and all of Scotland/Wales/NI sit in a fairly compressed
17-30 minute band for N\*=2000, with 35-100% never reaching it. This strongly supports treating "London
vs everywhere else" (or a density-banded version of the same idea) as the dominant axis for any N\*
scheme — a single national N\* in the 1,500-2,500 range would meaningfully tighten London specifically
while leaving most of the rest of the country essentially at today's ceiling-bound behaviour.

---

## 4. Notification fan-out today (`rippling_reach_notified`)

Per-post distinct-users-notified, 18,143 posts that have notified at least one rippled-in user
(930,344 total notification rows):

| Percentile | notified users/post |
|---|---|
| p10 | 14 |
| p25 | 26 |
| p50 | 44 |
| p75 | 67 |
| p90 | 96 |
| p95 | 117 |
| p99 | 207 |
| max | 324 |
| mean | 51 |

This is much smaller than `total_freeglers` (the eligible audience within the reach polygon) because
notification is presumably further gated (e.g. only users who haven't seen it, digest/frequency caps,
opt-in preferences) — but it has the same shape: a long tail, with p99/p50 ≈ 4.7x. It's a useful sanity
check that the *practical* fan-out today (tens of people notified per post, not thousands) is much
smaller than the raw audience-in-polygon numbers in §1, though every one of those thousands is still
theoretically able to see the post organically (browse/search), so audience-in-polygon is still the
right basis for tuning reach.

---

## Bottom line

**What N\* values produce what reach geography, and who never fills:**

- The current rule (fixed 30-min drive-time everywhere) produces a 14-30x spread in realized audience
  between the top and bottom deciles of home groups (602 → 8,696-17,776), which is the root of the
  "feels too large" complaints — those come exclusively from the small number of extremely dense areas
  (inner/west London: Hammersmith & Fulham, Kensington & Chelsea, Tower Hamlets, Islington, Ealing,
  Wandsworth, Lambeth, Hackney, Westminster, Brent, Barnet, Camden, Southwark — 15 groups median
  11,000-15,000 realized audience at 30 min) versus everywhere else (median audience under ~4,000 even
  at Oxford/Edinburgh scale, and under 700 for Hull/Swindon-scale areas).
- An audience-sized-burst rule (expand until cumulative_users ≥ N\*, existing floor/ceiling) is
  **usable in the N\*=1,000-4,000 range** for this network; N\*≥6,000 is not usable — virtually no post
  reaches that population within the 30-min ceiling today, so it would degenerate to "always ceiling"
  and change nothing.
- **N\*=2,000** dark-computes to: London posts cross it at a p50 of 13.0 min (vs today's fixed 30,
  Tower Hamlets specifically at 11.3 min vs its already-gated 17.8 min) — a genuine, substantial
  tightening exactly where the complaints originate — while **35% of all groups nationally (124/354
  sampled, concentrated in Wales, Scottish Highlands/Perthshire, Cornwall, Northumberland, rural East
  Anglia) never reach 2,000 people at all** and would be completely unaffected, continuing to ride to
  whatever ceiling is set (their reach is ceiling-bound, not N\*-bound, at any N\* up to 4,000).
- **N\*=4,000** is a gentler version of the same shape: still fully resolves Tower Hamlets (17.8 min,
  matching what the existing reply-stop gate already does for it) and meaningfully trims Oxford
  (28.7 min, only slightly under its current ~30), but 70% of all posts nationally never reach it —
  i.e. it would act almost like "leave everyone alone except the most extreme handful of boroughs",
  which may or may not be enough to satisfy the moderators who complained (Oxford, a mid-size university
  city, barely moves).
- The practical recommendation this data supports: **N\* should not be a single national constant** —
  region/density banding (even the coarse ONS-region split in §3d, where London is categorically
  different from all 11 other regions) captures almost all of the actionable signal. A national
  N\*≈2,000 with London (or a density-derived equivalent) singled out for tighter treatment, OR a
  smooth function of local density (e.g. N\* scaled by home-group's own audience-at-10-min, which is
  cheap to compute from each post's own early ticks with zero extra instrumentation) would directly
  target the complaint population without perturbing the 35%+ of the network that's already
  ceiling-bound today. This reframes the "needs a product decision" N\* calibration gap: the DATA shows
  N\* only matters (is only binding) for roughly the top 30-45% of the density distribution — pick N\*
  in 1,000-4,000 for that stratum, and the remaining rural/small-town majority is untouched regardless
  of the exact value chosen.
