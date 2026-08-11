# Ripple: delayed replies, density-conditional sizing, match mail (10 Aug 2026)

Branch: `feature/ripple-delayed-replies-density-sizing`
Worktree: `/home/edward/FreegleDocker-ripple-delay` (status API port 12462)

Acting on the 10 Aug production-data report, items 2, 3 and the scout finding.
Item 1 (dashboard bias / permanent trial record) and item 4 (users_approxlocs
restore, PR #1303) are NOT in this PR.

## Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Delayed release replaces indefinite hold | ✅ | migration + config + service + command + tests |
| 2 | Density-conditional ripple sizing | ✅ | DensityService + per-post max_minutes + columns |
| 3 | Density instrumentation (Go API + sysadmin UI) | ✅ | /rippling/density + ModSysAdminRipplingDensity |
| 4 | Scouts become match mail | ✅ | propensity signal removed; matching and mails kept |
| 5 | Docs | ✅ | rippling-algorithm, first-reply, members + moderators rippling-out |
| 6 | Full suites + screenshots + PR | 🔄 | Laravel filtered green (67); Go and vitest pending |

## 1. Delayed release, not indefinite hold

Today a reply from outside the current reach is held until either the ripple
covers the replier, or `rippling_reach.status` reaches `done` (the backstop), or
the post goes. The report measured that 3 in 4 held repliers live somewhere the
ripple will NEVER reach, so for them the only exit is the backstop - days later,
by which time a quarter to a third of items have gone.

New rule: every hold is a *delay* with a due time, computed at hold time from how
far the replier is from the item.

- `delay = clamp(base_minutes + per_mile_minutes * milesFromOrigin, base, max)`
- Distance is exact haversine from the post's (blurred) origin to the replier -
  no geometry, both coordinates are already stored on the rows.
- Defaults: base 15 min, 3 min/mile, cap 180 min. A typical held replier (~18
  miles out) waits ~1 hour; nobody waits more than 3 hours.
- Coverage still wins: if the ripple reaches them first they are released then.
- `dueat` is stored on the row so support can answer "when will this land?".
  Named `dueat`, not `releaseat`, because `releasedat` already exists on the same
  table and one letter apart is a trap. Rows held by the Go/web path (which does
  not compute it) get it backfilled by the first sweep, so there is one
  implementation of the policy, in PHP.
- Releases are counted by reason (`released_covered` / `_delayed` / `_maxed` /
  `_backfill`), so the new exit is visible rather than pooled into one counter.

## 2. Density-conditional ripple sizing

`RIPPLE_MAX_MINUTES=30` was chosen from a pooled bullseye that turns out to be
city behaviour. Split by density the drop-off is completely different:
conversion collapses past ~20-25 min in dense areas and does not fall at all out
to 45 min in sparse ones.

- `DensityService` asks the spatial KNN service for the nearest K freeglers
  (K=400) to the post's blurred origin and takes the radius that contains them.
- Bands from the report's terciles: `<= 1.6 mi` dense, `<= 3.1 mi` medium, else
  sparse. Fewer than K found inside the KNN ceiling is definitively sparse.
- Per-band cap: dense 20 min, medium 30 min, sparse 45 min.
- The band, the radius and the cap used are stored on `rippling_reach` so every
  post carries the decision that shaped it.
- Killswitch `RIPPLE_DENSITY_ENABLED=false` reverts to the flat 30.

## 3. Instrumentation

`GET /rippling/density` (Go API) reports, per band over a window: posts, the cap
applied, median audience, reply rate, taken rate and held/released counts -
i.e. exactly the comparison needed to tell whether shortening cities and
lengthening the country helped. Surfaced in ModTools sysadmin.

## 4. Scouts become match mail

7,909 emails to 5,713 people produced 4 replies. The `frequent` signal (99.8% of
the volume) converted 3 times in 7,902 - so **propensity goes**, and only that.

What stays, because it is the part that answers a request rather than a guess:
matching a new post against members' own open posts of the opposite type and
their saved searches, and mailing them individually about that one item. It
works **both ways round** - a new OFFER finds open WANTEDs, a new WANTED finds
open OFFERs - so both sides of a would-be exchange can start it.

The mail is the immediate-digest layout with two changes, because a copy of the
digest sent sooner is indistinguishable from the digest people already ignore:

- the **post's own subject** as the email subject, with no `[Group]` prefix;
- one line at the top naming the post or search of theirs that it matched.

Renames: `ScoutService`->`MatchMailService`, `ScoutCommand`->`MatchMailCommand`
(`firstreply:matchmail`), `firstreply.scouts`->`firstreply.matchmail`, Go
`ScoutSignal`->`MatchSignal` and payload key `scouts`->`matches`, sysadmin panel
"Scouting"->"Matches". `rippling_reach.min_tick` STAYS: a matched member who
replies still pulls the reach out to them.

`firstreply_scouts` the TABLE is kept: it is the record of the experiment the
report is based on, and `reason = 'frequent'` rows stay visible on the sysadmin
panel for any window that includes them. `firstreply_prompts_sent` is kept
because the Freegle-chat prompt cadence (EngagementService) still uses it.
