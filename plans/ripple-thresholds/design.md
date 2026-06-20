# Rippling-out reach: thresholds, country-wide validation & implementation

PR 459 introduces a geographic "rippling out" digest. A post ripples outward by
**drive-time isochrone**; a member sees the Offers/Wanteds inside their isochrone.
"Who sees a post from here" and "what I see here" are the **same reach** seen from
each end, so one reach policy governs both.

All analysis below is measured against the **live production DB** via the tunnel,
driving the real `iznik-routing-go` isochrone engine (56.7M-node UK road graph).
Artifacts: `/tmp/ripple-analysis/` (driver `ripple_iso.py` / `ripple_freegler.py`,
analyzers `analyze5.py` / `analyze_freegler.py` / `convergence.py`).

## The lever (corrected)

We do **not** cap the digest email contents. We tune the **reach of the ripple**
so the number of posts a user receives is right *by construction*. The reach is a
drive-time isochrone, chosen per location so the local catchment hits a target.
Distance is the wrong proxy (a 40 km circle is ~12 min rural, ~90 min in London) —
the algorithm is isochrone-based, so all measurement is too.

The reach must be **asymmetric**: ripple OUT to lift the starved, but **never cut
someone who already sees plenty** — a sudden drop reads as "Freegle is dying".

### Threshold policy (the rule)
Each user's new posts/day must land in **[0.9 × current, 1.5 × current]** —
no more than 10% fewer, no more than 50% more than they see now. The reach is
tuned (binary search on drive-time minutes) to hit that band. The starved (low
current) get lifted toward the +50% headroom; the busy keep ~what they have.

## Current state — what users actually receive today

Proper historical baseline: per sampled real user, their **real email-delivering
groups** (`emailfrequency≠0`), **Approved** Offers/Wanteds in `messages_spatial`
over a stable recent window, deduplicated (every message is on exactly one group
now + unified-digest dedup → summing groups is exact).

Delivered posts/day: p25=3, **median≈11–15**, p90≈45–49, p99≈99, max≈228.

| posts/day | 0 | 1–5 | 5–15 | 15–30 | 30–50 | 50–100 | 100+ |
|---|---|---|---|---|---|---|---|
| % users | 18% | 15% | 24% | 20% | 13% | 6% | 1% |

The distribution is **driven by group membership** ("users in areas" confirmed):

| memberships | share | median/day | p90/day |
|---|---|---|---|
| 1 group | 49% | 5 | 22 |
| 2–3 groups | 25% | 13 | 37 |
| 4+ groups | 25% | 36 | 78 |

Today the lever is **manual**: want more posts → join more groups. Half are in one
group (~5/day); a power-user quarter has joined 4+ (~36/day, your "50–100/day").
National volume ≈ **1,900 posts/day** (7-day window; longer windows undercount —
messages_spatial drops taken posts, 1898/d@7d → 475/d@90d, a survivorship trap).

## Change distribution under the policy (digest cohort, n≈2,378)

Model: tune each user's reach to reproduce their current count, clamped to the
reachable range [posts(10min), posts(40min)].

| change vs now | share |
|---|---|
| unchanged (reach reproduces current) | 83% |
| within −10%/+50% comfort band | **89%** |
| see >10% fewer | 6% |
| see >50% more | 5% |

Per-user change: p5 = −16%, p25/p50/p75 = 0%, p90 = +4%, p95 = +52%.

- **89% land within −10%/+50%** — the switch is invisible to them.
- **6% see a drop >10%** — power-users in ~5 *geographically dispersed* groups
  (cur ~29/day) a 40-min isochrone can't cover. The cohort to protect (raise their
  cap, or detect dispersed membership). Median drop −41%.
- **5% see a rise >50%** — low-current users in dense areas (1 group, ~6/day)
  where even a 10-min reach overshoots. Desirable; a smaller min reach tightens it.

## No hardcoding — the reach self-calibrates to density

Reach = drive-time until the isochrone holds a **target catchment**. Anchor the
target to **freegler density** (count of `users_approxlocs` in the isochrone), a
single global N — not a radius, not a city. Result (N≈500):

| local density | freeglers <30min | reach picked |
|---|---|---|
| sparsest 20% | 693 | 30 min |
| middle | 1,900–3,300 | 15 min |
| densest 20% | 13,793 | 10 min |

Reach slides **30→10 min** with density off one global constant. London → 10 min
*because it's dense*, not named. New megacity → 10 min automatically; new country →
works once it has freeglers. N≈500–1000 reproduces the national median.

Why freegler density (not post density): post density is volatile and **zero in
greenfield**; freegler density is stable and exists as soon as there are users.
Population (census) density is the greenfield bootstrap before freeglers exist.

**But a pure global-N target can't be switched to directly:**

| anchor (digest cohort) | within ±band | drop>10% | rise>50% |
|---|---|---|---|
| pure global N | 15% | **65%** | 18% |
| freegler-reach anchored to current | **89%** | 6% | 5% |

A fixed audience size **levels** the distribution → power-users crash. So:
- **Migration target = each user's current volume** (89% within ±band, no shock).
- **Steady-state / greenfield target = global N freeglers** (generalizes), **phased
  in**, never a hard cutover.

Only global, geography-free constants: target N, min/max reach, the 0.9/1.5 band.

## Confidence / convergence

The headline stats are settled (the earlier shifts were *methodology* changes —
radius→isochrone, all-groups→delivered, window — not sample size):
- prefix trajectory flat from **n=500** (within 89%, drop 6%, rise 5%);
- bootstrap 95% CIs at n≈2,378: within **89% ±1.2**, drop **6% ±1**, rise **5% ±1**;
- **independent replication**: a separate random n=3000 sample reproduces
  89% / 6% / 5% to ~1%.

## Implementation (three places; nothing London-specific)

1. **Spatial server (`iznik-routing-go`)** — add a reach *solver*. New
   `GET /v1/adaptive-reach?lat&lng&userid`: binary-search drive-time minutes until
   the isochrone holds ≈N freeglers (via `spatial-knn /v1/userapproxlocs/within`),
   then clamp so posts land in [0.9,1.5]×current (current = one query over the
   user's memberships × per-group volume). Return reach, catchment, change%, posts
   (reuse `posts_for_member.go`; **drop its `LIMIT 500`**). Reach is stable →
   precompute nightly into a `user_reach` table.
2. **Unified-digest builder (Laravel batch)** — select digest posts by the user's
   solved reach instead of group membership. Behind a per-group feature flag so the
   −10%/+50% band is watched live.
3. **Rippling page (`RipplingExplorer`/`setupRipplingExplorer.js`/
   `RipplingDigestModal`)** — show the algorithm's *solved* isochrone + catchment
   (N freeglers, M posts/day) + comparison to now (band shaded). Keep the manual
   minutes slider as an advanced override.
4. **Monitoring** — add a digest-side metric (the `change_pct` distribution + the
   sparse/dispersed tails) beside the existing `ripple_algorithm_metrics`.

Rollout: server solver + nightly precompute (shadow) → page viz (mods validate) →
digest builder behind per-group flag (watch change%) → widen; revisit the floor
once the transition is proven safe.

## Deployment data (must fix on the PR)

`iznik-routing-go/.gitignore` ignores all of `data/`, so nothing ships:
- **`uk-latest.osm.pbf` (2.4 GB)** — do NOT commit / NOT Git-LFS (weekly-refreshed
  external artifact). Download from Geofabrik (GB + Ireland/NI), `osmium merge`,
  place in the routing volume at deploy — ideally a one-shot init container. Prod
  compose already mounts `/data` from a named volume (built for this).
- **deprivation CSVs (≤1.7 MB)** — commit to the repo (move out of ignored `data/`).
  The Python generators referenced in the PR aren't in the branch, so the CSVs are
  otherwise unreproducible.
