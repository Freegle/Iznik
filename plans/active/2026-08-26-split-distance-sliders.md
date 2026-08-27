# Split the "How far away" filter into inbound + outbound sliders

**Branch:** `feature/split-distance-sliders` (worktree `distance-split`, base `origin/master` cd1327452)

## Goal

Today one control, `settings.browseMaxDistance`, does two jobs: it narrows what you see
(browse, digest, immediate mail, reach mail, daily push, browse-scoped search) AND it caps
how far away other people can see your posts. Split them, with **no behaviour change for
anyone who does not touch the new slider**.

## Design decisions (agreed with Edward 2026-08-26)

1. **Linked-then-split UI.** Default renders exactly today's single slider plus a quiet
   "Set separately" action. Splitting reveals a second slider on a shared 5-45 axis.
   "Link them again" re-yokes.
2. **Splitting writes nothing.** Only dragging the outbound thumb persists a key. So
   "no change unless they modify that slider" is literal, not approximate.
3. **New keys** `myPostsMaxMinutes` (source of truth) + `myPostsMaxDistance` (derived
   miles). Absent / JSON-null / <= 0 all mean *linked* -> fall back to `browseMaxDistance`.
4. **Outbound range is the full 5-45 ripple ceiling**, not the member's density band cap.
   Posts already travel to `DensityService::ceiling()` regardless of band, so capping the
   outbound slider at the band would misreport a city member's real reach. The inbound
   track greys out past their band cap instead (`deadZoneFrom`).
5. **Map shades both** when split: inbound solid (as now), outbound dashed outline.
6. `browseReachMaxDistance` (inbound-only band default) is never consulted outbound.
   Unchanged.

## Verified facts this design rests on (measured, not assumed)

- apiv2 saves settings with `JSON_MERGE_PATCH`, which **deletes** a key patched to `null`
  (`user.go:2469`). So re-linking = send both keys as null.
- `JSON_EXTRACT(s,'$.k') IS NULL` is **false** for a JSON null; `JSON_UNQUOTE` of it yields
  the *string* `'null'`, which `CAST(... AS DECIMAL)` turns into 0. So "unset" must be
  tested through the NULLIF/COALESCE chain, never `IS NULL` alone. Fails open (safe).
- `DECIMAL(20,6)` holds 14 integer digits; the sentinel `9007199254740991` is 16, so
  `CAST('9007199254740991' AS DECIMAL(20,6))` saturates at `99999999999999.999999` and the
  existing sentinel arm in `AuthorReachCapWhere` **never fires today**. Widen to
  `DECIMAL(30,6)`. No live bug (it fails open through the distance comparison).
- Outbound cannot feed back into inbound: ripple growth is `DensityService::ceiling()`-sized,
  not slider-sized, so `ExpandService::addPosterMembershipToRippledGroups` auto-joins the
  same groups whatever the outbound slider says. Membership set unchanged -> own feed unchanged.

## Enforcement surface

| Direction | Where |
|---|---|
| Inbound (unchanged) | `isochrone.resolveMaxDistance`, `message.go` browse-scoped search, `DistancePreferenceFilter::maxDistanceMiles`, client `filterMessagesByDistance` |
| Outbound (changes) | `utils.AuthorReachCapWhere` only, and `DistancePreferenceFilter::authorMaxDistanceMiles` only |

The new SQL keeps **exactly today's 4 placeholders in today's order** (sentinel, lat, lng,
lat), so no call site changes: `search.go:271`, `search.go:294`,
`isochrone/message.go:300`.

## Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Worktree + branch off origin/master | ✅ | containers built from stale dc1264a21 - rebuild needed before tests |
| 2 | Constants: outbound keys + ceiling | ✅ | |
| 3 | `useReachDistance` axis parameter | ✅ | |
| 4 | `useReachOverlay` two keyed slots | ✅ | |
| 5 | `RangeSlider` `deadZoneFrom` | ✅ | |
| 6 | `NearbyTowns` `perspective` prop | ✅ | |
| 7 | `DistanceSliders.vue` (linked/split) | ✅ | |
| 8 | Wire into PostFilters + FeedSettingsSection | ✅ | |
| 9 | `PostMap` dual overlay | ✅ | |
| 10 | Go `AuthorReachCapWhere` resolution chain | ✅ | |
| 11 | PHP `authorMaxDistanceMiles` fallback | ✅ | |
| 12 | Backfill command reconciles outbound where set | ✅ | |
| 13 | Copy change ("take account of geography") | ✅ | |
| 14 | Rebuild apiv2/spatial + re-migrate worktree DB | ✅ | tree is 113 files ahead of containers |
| 15 | Tests: vitest | ✅ | |
| 16 | Tests: Go | ✅ | |
| 17 | Tests: Laravel | ✅ | |
| 18 | Docs freshness (4 pages) | ✅ | members/04-your-account, rippling-algorithm, rippling-out, first-reply |
| 19 | Visual review, headless Chrome, GPU off | ✅ | linked / split / dead zone / dual map shading |
| 20 | PR with screenshots | ✅ | #1415 |

## Risks

- A narrower outbound cannot unsend spooled mail nor un-ripple a post. It hides posts from
  far viewers' feeds and search immediately.
- The linked state is NOT "same number both sides": most members have no `browseMaxDistance`
  at all (band default lives in `browseReachMaxDistance`), so their real outbound is already
  unlimited while inbound is their band radius. Copy must say "also limits", never claim equality.

## Results

- Frontend: **16172 passed, 0 failed** (was 16135✓/35✗ before the stale-`constants.js` fix below).
- Go: **4275 passed, 0 failed**.
- Laravel: **6055 passed, 0 failed**.
- Docs freshness: OK (51 pages).

### Environment traps hit (neither is a defect in this change)

1. **`iznik-nuxt3/constants.js` never syncs into the container that runs the frontend suite**
   (`*-modtools-dev-local`): it is a root-owned image copy, so `DISTANCE_AXES` read as
   `undefined` and took the whole of `useReachDistance.spec.js` down with a single
   `TypeError`. Fix: `docker cp` it in. Saved as a memory.
2. The worktree's containers and DB were built from a tree 113 files behind `origin/master`
   (local `master` was missing the #1406 cellset merge), giving 8 unrelated Go failures
   including a panic that aborted the package binary. Fixed by
   `scripts/setup-test-database.sh` plus rebuilding `spatial-knn`/`apiv2`.

### Not verifiable locally

The dual map shading. `/town/near` in this seeded environment returns
`{"cap_minutes":30,"density_band":"unknown","towns":[]}` - no `reach_polygon`, no
`reach_radius_miles` - so **neither** overlay draws here, on this branch or on master, and the
towns hint is blank in the screenshots for the same reason. Covered by three new PostMap specs
instead (linked draws one layer; split draws two, inbound filled and outbound dashed-unfilled;
clearing one slot leaves the other). The inbound slider capping at 30 rather than 45 in the
screenshots is the same cause, and is correct behaviour: density unknown means the flat fallback
cap applies.
