# Revealed Travel Behaviour: How Far Do Freeglers Actually Travel?

Data pulled read-only from live prod DB, 2026-07-02. All SQL shown inline. Crow-flies (haversine) km
throughout — no road-network distance available cheaply at this volume, so all figures **understate**
true drive distance/time (roads are never straight lines; typical rule of thumb is road distance ≈
1.2-1.4x crow-flies in urban areas, more in rural areas with rivers/motorways-only crossings).

## THE CENTRAL CAVEAT — read this before using any number below

**Every distance in this report is a behaviour-WITHIN-EXPOSURE distribution, not free demand.**
A freegler can only reply to, or collect, a post that was already visible to them. Since go-live
(~2026-06-14) that visibility is capped at ~30 min drive-time reach (post-ripple) or group membership
(pre-ripple / non-members). So:

- We are measuring "how far did people travel, **given that reach already capped what they could see**",
  not "how far would people be willing to travel if reach were unlimited".
- The tail of the distribution is mechanically truncated at the current reach ceiling. We cannot see
  the counterfactual demand beyond it — that requires the separate randomized reach experiment (already
  specced, ~3 weeks to result).
- This dataset is therefore usable for one thing with confidence: **"what cap would preserve X% of
  collections we are currently getting"** — i.e. a safe *floor* for any new parameter, verified against
  what's actually happening today. It is **not** usable to conclude "nobody would travel further than
  Nkm" — some might, if shown the post; we just can't see them yet.
- Second-order caveat: `users_approxlocs` is a **current-location snapshot** (one row per user, no
  history), not a point-in-time record. For the pre-rippling (March/April) comparison this means we are
  using each user's *current* (June/July) location for their March collection — if a meaningful number
  of users moved house in between, the pre-period numbers are somewhat noisy. Coverage rate is similar
  in both periods (99.5% pre vs 95.3% post of taker-records had a resolvable location), so this is not a
  coverage bias, only a possible drift-in-location bias, believed small over a ~3 month window.
- Third-order caveat: about half of Taken/Received outcomes have no matching `messages_promises` row
  (some transactions go straight to "Taken" without a formal promise step), so the taker-distance sample
  is a ~50% sub-sample of all collections, not all of them. No obvious reason to expect this to bias
  distance (promise usage looks like a UI-adoption effect, not a proximity effect), but flagging it.

## Data sources used

- **Taker location**: `messages_promises` (msgid, userid, promisedat) — the person promised the item,
  taken as the most recent promise per msgid at/around the Taken outcome. `messages_outcomes` has no
  `takenby`/`userid` column, so this is the best available "who actually took it" signal. Verified
  reasonable coverage: 29,636 / 60,025 (49%) of Taken/Received outcomes since 2026-05-01 have a promise
  record; 96% of those promised users have a resolvable location.
- **Replier location / taker location**: `users_approxlocs` (userid, lat, lng) — current best-guess
  location per user, 174,786 rows. This is a snapshot, not a log (see caveat above).
- **Post location**: `messages.lat`/`messages.lng` — always populated (100% of the Taken/Received sample).
- **Reply events**: `chat_messages` where `type='Interested'`, joined via `refmsgid` to the post and
  `chatid`→`chat_rooms.chattype='User2User'` to isolate person-to-person replies (excludes system/mod
  chat). Replier identified as `chat_messages.userid`.
- **Home group / membership class**: `messages_groups` (picking one canonical group per message,
  preferring an `Approved` collection row) joined to `memberships` to classify the taker as
  `home_member` (member of that group, not via rippling), `rippled_member` (member via rippling —
  `memberships.rippled=1`), or `non_member` (not a member of the post's group at all — took/replied
  purely via reach exposure without ever joining).
- **Density proxy**: no ready-made ONS rural-urban class column exists on `groups`. Built proxy =
  `membercount / polygon_area_km2`, area computed from `groups.polyindex` (a `POLYGON` whose vertices are
  stored as raw lng/lat degrees — despite an `ST_SRID` tag of 3857, `ST_Area()` on it returns degrees²,
  not m², so area was converted manually: `area_km2 = area_deg2 * 111.32 * 111.32 * cos(group_lat)`).
  Exemplar groups requested: Hull(21473), TowerHamletsFreegle(21662), Oxford-Freegle(21555),
  ribblevalleyfreegle(21589), Swindon-Freegle(92103), EdinburghFreegle(21354). "NW-London Offers" as
  named in the Discourse complaint does not match any current group `nameshort`/`namefull` — it is
  presumably a legacy Yahoo-era group name; not analysed as a named exemplar, but London-area coverage is
  reasonably proxied by TowerHamletsFreegle here.

## 1. Taker distance (post → collector), since 2026-05-01

SQL (haversine distance, most-recent promise per msgid, joined to Taken/Received outcomes):

```sql
SELECT ROUND(dist_km,2) FROM (
  SELECT m.id as msgid,
    (6371 * ACOS(LEAST(1, GREATEST(-1,
        COS(RADIANS(m.lat)) * COS(RADIANS(ua.lat)) * COS(RADIANS(ua.lng) - RADIANS(m.lng)) +
        SIN(RADIANS(m.lat)) * SIN(RADIANS(ua.lat))
    )))) as dist_km
  FROM messages m
  JOIN messages_outcomes mo ON mo.msgid = m.id AND mo.outcome IN ('Taken','Received')
  JOIN (
    SELECT p.msgid, p.userid,
      ROW_NUMBER() OVER (PARTITION BY p.msgid ORDER BY p.promisedat DESC) as rn
    FROM messages_promises p
  ) plat ON plat.msgid = m.id AND plat.rn = 1
  JOIN users_approxlocs ua ON ua.userid = plat.userid
  WHERE mo.timestamp >= '2026-05-01' AND m.lat IS NOT NULL AND m.lng IS NOT NULL
) x
```

Sanity check: 60,025 Taken/Received rows since 2026-05-01, all with post lat/lng; 29,636 have a promise
record; 28,896 of those resolve to a location (n used below).

**Overall taker distance, n=28,893** (after also joining membership class, see below — 3 rows dropped by
that join's group resolution edge cases):

| Percentile | Distance (km) |
|---|---|
| p50 | 2.1 |
| p75 | 5.9 |
| p90 | 12.7 |
| p95 | 18.8 |
| p99 | 37.6 |
| mean | 5.5 |
| max | 710 (data-quality outlier — stale/incorrect approxloc or postal exchange, not representative) |

**Split by membership class relative to the post's home group** (`home_member` = joined that group
normally; `rippled_member` = joined that group via a rippling-triggered membership
(`memberships.rippled=1`); `non_member` = took the item without ever being a member of that group at
all — i.e. pure reach-exposure collection):

| Class | n | p50 | p75 | p90 | p95 | p99 | mean |
|---|---|---|---|---|---|---|---|
| home_member | 28,418 | 2.1 | 5.9 | 12.5 | 18.4 | 36.6 | 5.4 |
| rippled_member | 30 | 4.5 | 7.3 | 8.7 | 8.9 | 23.2 | 5.0 |
| non_member | 445 | 4.1 | 11.3 | 25.9 | 44.0 | 219.2 | 13.0 |

Read with caution — `rippled_member` is a very small sample (30) since formal rippled-membership
creation is rare (most reach interactions don't convert to membership). `non_member` (445, ~1.5% of all
takers) is the cleanest signal of "genuine ripple-only collection" and is markedly longer-tailed than
home members (p90 26km vs 12.5km) — consistent with rippling successfully extending collection distance
for the minority of transactions it drives, but also shows some very long tail outliers (>100km) that are
likely delivery arrangements or stale-location noise rather than drive-and-collect.

**What cap would preserve X% of ALL observed collections** (n=28,893, all classes combined):

| Cap | % of collections captured |
|---|---|
| 2 km | 48.7% |
| 3 km | 58.2% |
| 5 km | 70.8% |
| 8 km | 81.6% |
| 10 km | 85.9% |
| 15 km | 92.5% |
| 20 km | 95.6% |
| 25 km | 97.2% |
| 30 km | 98.2% |
| 40 km | 99.2% |
| 50 km | 99.4% |

At a very rough 25 km/h effective urban drive-time conversion, 20km ≈ ~48 min, i.e. today's ~30min /
~45km ceiling is already generous relative to where 95%+ of actual collections happen — consistent with
the "feels too large" complaint. (Conversion is approximate; see time/distance caveat below.)

## 2. Reply-distance decay, since rippling went live (2026-06-14)

SQL (all `Interested` replies in User2User chats, replier location vs post location; also flags whether
that specific replier was the one who ultimately got the message promised to them):

```sql
SELECT
  ROUND((6371 * ACOS(LEAST(1, GREATEST(-1,
      COS(RADIANS(m.lat)) * COS(RADIANS(ua.lat)) * COS(RADIANS(ua.lng) - RADIANS(m.lng)) +
      SIN(RADIANS(m.lat)) * SIN(RADIANS(ua.lat))
  )))), 2) as dist_km,
  m.id as msgid, cm.userid as replier,
  CASE WHEN plat.takerid = cm.userid THEN 1 ELSE 0 END as replier_was_taker
FROM chat_messages cm
JOIN chat_rooms cr ON cr.id = cm.chatid AND cr.chattype='User2User'
JOIN messages m ON m.id = cm.refmsgid
JOIN users_approxlocs ua ON ua.userid = cm.userid
LEFT JOIN (
  SELECT p.msgid, p.userid as takerid,
    ROW_NUMBER() OVER (PARTITION BY p.msgid ORDER BY p.promisedat DESC) as rn
  FROM messages_promises p
) plat ON plat.msgid = m.id AND plat.rn = 1
WHERE cm.type = 'Interested' AND cm.date >= '2026-06-14'
  AND m.lat IS NOT NULL AND m.lng IS NOT NULL
```

Sanity check: 27,013 `Interested` chat_messages since 2026-06-14; 23,738 (88%) resolve to a replier
location. 3,464 of those replies (14.6%) belong to the replier who eventually got the item promised to
them ("converted" replies).

**Overall reply distance distribution, n=23,738:**

| Percentile | Distance (km) |
|---|---|
| p50 | 6.0 |
| p75 | 11.8 |
| p90 | 20.0 |
| p95 | 27.1 |
| p99 | 50.4 |

(Note replies are further out than takers on average — makes sense, replying is low-cost/high-hope,
whereas actually collecting selects for people who are close enough to bother.)

**Replies per post by distance band, and conversion rate (did that replier become the taker):**

| Band | Replies in band | % of all replies | Taker-replies in band | % of all taker-replies | Conversion rate (taker / replies in band) |
|---|---|---|---|---|---|
| 0-2 km | 3,724 | 15.7% | 628 | 18.1% | 16.9% |
| 2-5 km | 6,350 | 26.8% | 1,027 | 29.6% | 16.2% |
| 5-10 km | 6,244 | 26.3% | 876 | 25.3% | 14.0% |
| 10-20 km | 5,049 | 21.3% | 652 | 18.8% | 12.9% |
| 20-40 km | 1,942 | 8.2% | 238 | 6.9% | 12.3% |
| 40km+ | 429 | 1.8% | 43 | 1.2% | 10.0% |

**This is the clearest decay signal in the whole dataset**: conversion rate drops monotonically with
distance, from 16.9% at <2km to 10.0% at 40km+ — roughly a 40% relative drop end to end, gentle rather
than a cliff. Cumulatively, 91.9% of all taker-conversions happen within 20km of the post, 98.8% within
40km. There is no sharp elbow — decay is smooth, which argues against there being one universal "correct"
cutoff distance and supports a probabilistic/percentile-based design (e.g. "cover the drive-time radius
that historically yields 90-95% of conversions for this area's density") over a single hard constant.

## 3. Density splits (exemplar groups)

Density proxy: `membercount / polygon_area_km2` (see Data Sources for area computation method).

| Group | Members | Area (km²) | Density (members/km²) |
|---|---|---|---|
| TowerHamletsFreegle | 26,137 | 20.2 | **1,292.0** |
| Oxford-Freegle | 50,177 | 206.1 | 243.4 |
| EdinburghFreegle | 47,617 | 402.8 | 118.2 |
| Swindon-Freegle | 9,067 | 559.4 | 16.2 |
| ribblevalleyfreegle | 5,338 | 574.4 | 9.3 |
| HullFreegle | 3,360 | 1,156.8 | **2.9** |

(Hull's low density despite being a city is because its group polygon extends over a large rural
hinterland catchment beyond the urban core — density here is a property of the *group's drawn boundary*,
not just population density; a real implementation should probably use population/member density in a
fixed-radius disc around the post rather than group-polygon-average, to avoid this artefact.)

**Taker distance by exemplar group** (same promise-based method as section 1, filtered to messages whose
canonical group is one of the six), since 2026-05-01:

| Group | n takers | p50 | p75 | p90 | p95 | p99 | mean |
|---|---|---|---|---|---|---|---|
| TowerHamletsFreegle | 105 | 0.54 | 1.04 | 4.99 | 8.54 | 12.57 | 1.60 |
| Oxford-Freegle | 785 | 2.84 | 4.99 | 9.69 | 13.44 | 29.25 | 4.37 |
| EdinburghFreegle | 510 | 3.49 | 5.95 | 8.56 | 12.28 | 49.32 | 6.31 |
| Swindon-Freegle | 39 | 3.93 | 5.85 | 21.97 | 26.84 | 191.26 | 10.02 |
| HullFreegle | 44 | 3.73 | 7.76 | 12.95 | 16.91 | 23.56 | 5.55 |
| ribblevalleyfreegle | 3 | 18.31 | 19.6 | 19.6 | 19.6 | 19.6 | 15.34 |

(Ribble Valley n=3 — too small to draw any conclusion, shown only because it was a named exemplar in the
Discourse complaint; treat as anecdote not data.)

**What cap captures X% of collections, per group** (this is the direct empirical input for a per-density
extent parameter):

| Group | 2km | 5km | 8km | 10km | 15km | 20km | 30km |
|---|---|---|---|---|---|---|---|
| TowerHamletsFreegle (dense) | 81.0% | 90.5% | 94.3% | 98.1% | 99.0% | 100% | 100% |
| Oxford-Freegle | 35.3% | 75.0% | 86.0% | 90.8% | 96.1% | 97.6% | 99.4% |
| EdinburghFreegle | 30.8% | 64.1% | 87.1% | 92.2% | 96.5% | 97.3% | 98.2% |
| HullFreegle | 25.0% | 63.6% | 77.3% | 88.6% | 93.2% | 95.5% | 100% |
| Swindon-Freegle (sparse) | 38.5% | 69.2% | 82.1% | 84.6% | 87.2% | 89.7% | 97.4% |

This is the single most decision-relevant table in the report: **the same percentage-of-collections-
captured target implies wildly different distance caps by density.** To hit "95% of collections
captured": TowerHamlets needs ~5km, Oxford/Edinburgh/Hull need ~15-18km, Swindon needs >30km. This
directly supports the extent-governor's premise (equal-N* audience-sizing beats equal-drive-time) and
gives concrete per-density calibration points rather than a guess.

Reply-distance data (section 2) by the same exemplar groups, since 2026-06-14 (small samples for several
— treat non-Oxford/Edinburgh rows as indicative only):

| Group | n replies | p50 | p75 | p90 | p95 | n taker-replies |
|---|---|---|---|---|---|---|
| Oxford-Freegle | 600 | 3.93 | 6.91 | 12.96 | 26.6 | 72 |
| EdinburghFreegle | 447 | 4.91 | 8.74 | 19.14 | 48.7 | 64 |
| HullFreegle | 59 | 3.88 | 7.68 | 11.68 | 16.91 | 12 |
| Swindon-Freegle | 17 | 4.64 | 12.3 | 24.45 | 26.84 | 4 |
| TowerHamletsFreegle | 30 | 3.40 | 5.87 | 6.49 | 9.85 | 2 |
| ribblevalleyfreegle | 11 | 9.51 | 14.63 | 24.7 | 26.08 | 1 |

## 4. Pre- vs post-rippling comparison (taker distance)

Pre = outcomes 2026-03-01 to 2026-05-01 (before rippling went live 2026-06-14). Post = outcomes from
2026-06-01 onward. Same promise-based taker-distance method as section 1.

Sanity check: pre period 59,269 Taken/Received rows, 30,167 (51%) with a promise, 30,021 resolve to
location. Post period 29,335 Taken/Received rows, 14,157 (48%) with a promise, 13,493 resolve to
location. Coverage rates are consistent between periods (99.5% and 95.3% resolvable), so this is not a
coverage-bias artefact.

| Period | n | p50 | p75 | p90 | p95 | p99 | mean |
|---|---|---|---|---|---|---|---|
| Pre (Mar-Apr) | 30,021 | 2.21 | 6.15 | 13.29 | 20.18 | 55.76 | 6.47 |
| Post (Jun-Jul) | 13,493 | 2.04 | 5.79 | 12.30 | 18.23 | 36.30 | 5.14 |

**Bottom line on pre/post: no evidence that rippling has meaningfully lengthened typical collection
distance so far.** The median is essentially unchanged (2.21km → 2.04km), and the observed tail actually
*shrank* (p99 55.8km → 36.3km, mean 6.5km → 5.1km) rather than grew. Two plausible readings, not
distinguishable from this data alone:
1. Rippling genuinely hasn't shifted typical collector behaviour yet (early days, ~2.5 weeks live at
   measurement time; adoption/awareness of extended reach may be low; and the earlier "0.9-1.0 distinct
   repliers/post, 56-66% zero-reply" facts suggest most posts aren't yet being seen/acted on by the newly
   reachable population).
2. The `users_approxlocs` current-location-snapshot confound (see top caveat) could be smoothing out a
   real shift if it exists — this comparison is the one most exposed to that caveat, since it spans the
   longest time gap (up to ~4 months between the pre-period transaction and the location snapshot).
Given the extent-governor's stated purpose is to *rein in* excess reach in dense areas rather than
*grow* it, this "no lengthening yet" finding is not alarming — it's mainly a reminder that revealed
pre/post data cannot yet answer "how much MORE would people collect from if given more reach" (that's
what the separate reach experiment is for), only "has typical behaviour visibly shifted so far" (answer:
not yet, materially).

## Bottom line: what cap would capture X% of observed successful collections, by density class

Using the taker-distance-by-group table (section 3) as the primary evidence, converted to an
**approximate** drive-time using a rough 25 km/h effective speed for dense urban and 35-40 km/h for
lower-density areas (conversion is illustrative only — no road-network data was used; real drive-times
should replace this before any implementation):

| Density class (exemplar) | Distance cap for 90% of collections | Distance cap for 95% | Approx drive-time cap for 95%* |
|---|---|---|---|
| Very dense (TowerHamlets, ~1,300/km²) | ~5 km | ~5-8 km | ~12-20 min |
| Dense (Oxford, ~240/km²) | ~10 km | ~15 km | ~25-30 min |
| Medium (Edinburgh, ~120/km²) | ~9 km (noisy tail) | ~15 km | ~25-30 min |
| Low (Hull's drawn catchment, ~3/km²) | ~9 km | ~15-18 km | ~25-30 min |
| Sparse (Swindon, ~16/km²) | >20 km (still climbing) | >20 km | 35-45+ min |

*Time conversion is a rough illustrative placeholder, not a measured value — treat the km columns as the
credible numbers and the time column as "roughly what this would mean in the current drive-time-based
reach system", pending either real routing-engine conversion or a switch to a distance/audience-based
metric that sidesteps the conversion problem entirely.

**Overall recommendation implied by this data**: a single global drive-time constant cannot satisfy "95%
of collections captured" across this density range without being needlessly large in dense areas (where
5-8km covers 95%) or too small in sparse areas (where even 20km+ doesn't). This is exactly the
"~80x active-member-pool spread" finding restated in collection-distance terms, and supports deriving the
extent-governor's N* (or an equivalent distance/time cap) from **local density**, calibrated so that the
captured fraction of *currently observed* successful collections (e.g. targeting the 90-95% band) is held
roughly constant across areas — rather than holding drive-time constant. The concrete per-density
percentile tables above (sections 1 and 3) are a ready-made calibration anchor for that N*/cap function,
though should be treated as a **floor** (today's exposure-limited behaviour), refreshed once genuine
demand data exists from the separate randomized reach experiment.
