# Mobile-friendly Explore (community finder)

Date: 2026-06-13
Branch: `feature/explore-mobile-finder` (iznik-nuxt3)

## Problem

`/explore` (with no group id) is the "find a community near you / join" page. It is
**map-first**: a full-UK Leaflet map with a tree marker per group. To find their local
community a user pans and pinch-zooms the tiny map, then taps small markers.

On mobile this is poor UX:

- Panning/pinch-zooming a UK-scale map to your town is fiddly and error-prone.
- The "Search for a place..." box is a cramped Leaflet geocoder control crammed into the
  map corner; it only *moves the map* — you then still have to hunt for and tap markers.
- The "Join X" closest-group buttons key off the **map centre** (initially the centre of
  the UK), so they suggest communities in the middle of the country, not near the user,
  until the map is moved.
- Leaflet + tiles are loaded on mobile just to act as a clumsy picker.

## Goal

Make finding and joining a local community easy on mobile, without harming the desktop
experience (where a map with a mouse works well).

## Approach

Responsive split on the `/explore` (no-id) page:

- **Mobile (`xs`/`sm`)** — a new search-first, list-based finder, **no map**.
- **Desktop (`md`+)** — the existing `PostMapAndList` map+list, unchanged.

Switching uses the existing `VisibleWhen` / `BreakpointFettler` mechanism
(`miscStore.breakpoint`, mounted app-wide in `LayoutCommon.vue`).

### New: `ExploreNearby.vue` (mobile finder)

1. Heading + a prominent `PlaceAutocomplete` ("Enter your town or postcode"). This existing
   component returns `{ name, lat, lng, bbox }` and already handles postcodes via the
   location store.
2. On selection (or, if logged in with a saved location, immediately on mount) compute the
   nearby communities and render them as a **list**, reusing `MapGroup.vue` (profile image,
   tagline, **Join** + **Explore** buttons).
3. Empty state: if no community covers that area, show the existing "would you like to start
   one?" prompt with the `newgroups@` contact link.
4. Region browse buttons (reusing the existing region list) remain available as a secondary
   way to browse, plus the existing "Need help?" link.

### New: `nearbyGroups()` pure helper (in `composables/useMap.js`)

`nearbyGroups(point, groups, { limit, isMember })` — pure, unit-testable:

- skip groups that are not `onmap && publish`;
- skip groups the user is already a member of (`isMember(id)`);
- distance from `point` to the group centre (`getDistance`, metres), taking the nearer of
  the main `lat/lng` and any secondary `altlat/altlng` centre;
- respect `showjoin` (miles): include only if `!showjoin || distance <= showjoin * 1609.34`
  — same rule the map's closest-groups logic uses;
- sort ascending by distance, return up to `limit` groups.

This mirrors the semantics of the existing `closestGroups` computed in `PostMapAndList.vue`,
but parameterised by an arbitrary point (the searched place) rather than the map centre.

## Why this shape

- Reuses proven building blocks (`PlaceAutocomplete`, `MapGroup`, `getDistance`,
  `VisibleWhen`) — small, focused change.
- The fiddly map is removed from the mobile critical path (and Leaflet/tiles no longer load
  on mobile explore — a perf win).
- Desktop is untouched, so no regression risk there.
- The distance/sort/filter logic is isolated as a pure function, so it is cheap to test and
  reason about.

## Testing

- `tests/unit/composables/useMap.spec.js` — unit tests for `nearbyGroups` (sorting,
  `onmap`/`publish` filtering, `showjoin` radius, member exclusion, `altlat/altlng`, limit).
- `tests/unit/components/ExploreNearby.spec.js` — renders search box; on `selected` shows
  the nearby list; empty state when none; member exclusion.
- Browser verification on the worktree URL at mobile and desktop widths.
