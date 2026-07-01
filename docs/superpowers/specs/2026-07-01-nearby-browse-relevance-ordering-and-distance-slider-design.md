# Nearby browse: relevance ordering + distance slider

Date: 2026-07-01
Branch: `fix/popular-posts-ripple-aware`
Status: design for review

## Problem

A member's "Nearby" browse feed shows distant posts first (reported: first post 16 miles
away for user 36757945 / Tony Almond, home AL6 9RF Welwyn, while hundreds of 0-mile posts
exist).

Root cause, confirmed against live data and code:

1. "Nearby" (`settings.browseView = 'nearby'`) selects posts by the rippling **reach** model:
   a post appears when its grown `rippling_reach.polygon` covers the viewer
   (`ST_Contains`). For this user that is ~766 posts spanning 0 to ~17 miles. A post whose
   origin is 16 miles away legitimately reaches him, because the ripple engine grows each
   post's reach out to a drive-time isochrone. So "Nearby" means "has rippled to reach you",
   not "nearest".
2. The Go feed endpoint (`GET /isochrone/message`, handler `isochrone.Messages`) returned
   the reach set with **no ordering** (only a `pinClosestTwo` hack), and the client then
   **re-sorted** it in `PostMapAndList.vue::sortMessages`. The user's `browseSort` is
   `Newest` (and the default `Unseen`/"New to you" behaves similarly): both order by
   arrival/recency and **never consult distance**. So the newest post that reaches the
   viewer lands first, however far away it is.

A proper relevance ranking already exists in the codebase — the rippling "digest score"
(`DigestPostScorer` in `iznik-batch`, `scoreDigestPost` in `iznik-routing-go`) — but it was
wired only into the unified digest email and the `/rippling` moderator preview, never into
the on-site browse feed.

## Goals

- Order the Nearby feed by the existing rippling relevance score, server-side.
- Keep novelty as the primary axis on the default view: unseen posts first, then seen, with
  each bucket ordered by relevance (score), not raw recency.
- Give members a simple, pretty **distance slider** to narrow the feed to their own
  preference (e.g. only within a couple of miles), without exposing travel-time complexity
  or absolute numbers.
- Keep the unseen **count/badge** consistent with the filtered feed.
- Make **all** browse filters sticky (persisted in `settings`).
- Update the member/moderator rippling docs to describe the new behaviour.

## Non-goals (this phase)

- Applying the distance preference to digest **emails**. The setting is persisted so a later
  phase can read it server-side in the digest pipeline; no email change is made now.
- Server-side pre-filtering of the feed payload by distance (the feed still returns the full
  reach set; the client filters the list locally for instant slider response). This can be a
  later optimisation.
- Changing the reach model itself, the `mygroups` view, or the travel-time mechanism.

## Key correctness property: deterministic blur

Post coordinates are privacy-blurred before they leave the API (`utils.Blur`,
`BLUR_USER = 400` m). `utils.Blur` is **deterministic**: the offset direction is a pure
function of the coordinates (`dir = ((int)(lat*1000)+(int)(lng*1000)) % 360`), followed by a
fixed geodesic step, rounded to 3 dp. The same post always yields the same blurred point,
hence the same blurred distance.

Consequences we rely on:

- We expose a **blurred** `distance` (miles) per post. Blurred distance cannot be used to
  triangulate the true location, and is sufficient for a coarse "Nearer/Further" slider.
- Because blur is deterministic, the **client list filter** (on the exposed blurred
  distance) and the **server count filter** (on the same blurred distance) agree exactly —
  no off-by-one at the boundary between "shown" and "counted".
- The score's `close` term is computed from the same blurred distance, so ordering and the
  exposed distance are mutually consistent.

## Architecture overview

Three parts: Go API, Nuxt client, docs.

```
rippling_reach (ST_Contains) ─► Go feed: score each post, order by score desc,
                                 expose {score, distance(blurred miles), unseen}
                                          │
                    settings.browseMaxDistance (miles, null = no limit)
                                          │
Go count: same reach set, filter blurred distance ≤ max, count unseen ─► badge (app-wide)
                                          │
Client: load full feed ─► sortMessages (unseen bucket, then seen; score desc within)
                       ─► filter list by distance ≤ max (instant, local)
                       ─► render unseen grid / divider / seen grid
Slider (settings.browseMaxDistance) ─► save + refetch count; list re-filters locally
```

## Server changes (Go, `iznik-server-go`)

### A. Feed ordering + fields (in progress, near complete)

In `isochrone/message.go` (`Messages`, the `browseView = nearby` path):

- Port the rippling score as a pure, unit-tested function (mirrors
  `iznik-batch/.../DigestPostScorer.php` and `iznik-routing-go/digest_simulator.go`):
  `close = clamp(1 - distM/reachRadiusM, 0, 1)`, `fresh = clamp(1 - ageH/window, 0, 1)`,
  `engagement = (views + 3*replies)/max(1, ageH)`, `budget = exp(-engagement/(decay/12))`,
  `anchor = homeGroup?1:0` (stubbed false), `total = wClose*close + wFresh*fresh +
  wBudget*budget + wAnchor*anchor`.
- Browse-specific, env-tunable weights (defaults mirror the digest):
  `RIPPLE_BROWSE_W_CLOSE=1.0`, `W_FRESH=0.0`, `W_BUDGET=1.0`, `W_ANCHOR=0.0`,
  `WINDOW_HOURS=24`, `BUDGET_DECAY=25`, `DEFAULT_REACH_M=30000`.
- Extend the reach query to fetch per-post `views` (messages_likes View) and `replies`
  (chat_messages Interested), plus the reach polygon to derive `reachRadiusM`.
- For each post: blur coords once (`utils.Blur`), compute the **blurred** distance
  (viewer → blurred post, miles), use it for both the score's `close` term and the exposed
  `distance` field. Order the result by `score` desc, tie-break `arrival` desc.
- `MessageSummary` gains `Score float64 json:"score"` and `Distance float64 json:"distance"`
  (neither `omitempty`; 0 is meaningful for distance).
- Remove `pinClosestTwo` (proximity is now in the score).

### B. Count endpoint distance filter (new)

`GET /message/count` / `nearbyCount`:

- Accept the viewer's `settings.browseMaxDistance` (miles; null/absent = no limit). Prefer
  reading it from the user's own settings server-side so the app-wide navbar badge honours
  it without every client call site having to pass it; also accept an explicit `maxDistance`
  query param so the browse page can force a fresh value immediately after a slider change.
- When a limit is set, count unseen reach posts whose **blurred** distance ≤ limit (same
  blur/formula as the feed, so it matches the client list exactly). When no limit, keep the
  existing fast count.
- Refactor the reach row production into a shared helper so feed and count cannot drift.

## Client changes (`iznik-nuxt3`)

### C. Bucketed relevance ordering

`PostMapAndList.vue::sortMessages`, `Unseen` branch: keep "unseen (and not successful)
first", but change the within-bucket tiebreak from `arrival` desc to `score` desc
(`(b.score ?? 0) - (a.score ?? 0)`). `MessageList.vue`'s `unseenMessages`/`seenMessages`
are plain filters over the already-sorted array, so this single change orders both buckets
by score. Note the `lockedSortOrder` cache (`PostMapAndList.vue:519-542`) only re-sorts when
the set of message IDs changes; that is desirable (stable order within a session). No extra
invalidation is needed because score is stable within a session for a given ID.

### D. Distance slider

- New control in `PostFilters.vue`, **left** of the sort row; the "Sort by" dropdown moves
  to the **right** of that row.
- A hand-styled native `<input type="range">` matching the existing donation slider
  (`MyPostsDonationAsk.vue`) but using the green panel palette (`$color-success`/
  `$color-green--darker`) instead of blue. Rounded thumb, gradient track, hover/active
  states. Accessible (`aria-label="Maximum distance"`, keyboard operable).
- Range: min 0.5 mile (left), max 30 miles (right). Step 0.5. End labels only:
  **"Nearer"** (left) and **"Further"** (right). **No numeric readout.**
- The far-right position ("Further", = the max) means **no limit**. Stored value:
  `settings.browseMaxDistance` in **miles**, where the max/`null` = unlimited. Default =
  unlimited (slider sits at "Further"), so existing users see no change until they pull it
  left.
- Persistence: a `computed` with get/set mirroring `sort` — read
  `me.value?.settings?.browseMaxDistance`, write by mutating `settings` and calling
  `authStore.saveAndGet({ settings })`, then emit `update:selectedMaxDistance`. Debounce the
  save/refetch so dragging does not thrash the API.
- Only shown in the `nearby` browse view (distance is meaningless for `mygroups`).

### E. List distance filter

In `PostMapAndList.vue::messagesForList` (right after the existing `selectedGroup` filter):
`msgs = msgs.filter(m => max == null || m.distance == null || m.distance <= max)`. Everything
downstream (dedup, unseen/seen buckets, grids) inherits it. Own posts (distance ≈ 0) always
pass. This is instant and needs no refetch when the slider moves.

### F. Count threading

`stores/message.js::fetchCount(browseView, maxDistance, log)` gains the distance argument;
`api/MessageAPI.js::count` passes it through. Update the call sites to pass
`me.settings.browseMaxDistance`: `useNavbar.js:288`, `PostFilters.vue:220,233`,
`pages/browse/[[term]].vue:377`, `MessageList.vue:605`. After a slider change, refetch the
count so the badge tracks the list. (Server may also read the setting directly; passing the
param keeps the browse page immediate.)

### G. "Filters active" badge

Add `hasNonDefaultFilters` in `PostFilters.vue` and render a small red badge on the
"Map & Filters" toggle button (`PostFilters.vue:94-105`, the `!showFilters` state).
Definition (to confirm): active when `type !== 'All'` OR a specific group is selected OR
`browseMaxDistance` is set below unlimited. Sort order and nearby-vs-mygroups view are
treated as view/order choices, not "filters" (open decision below).

### H. Copy changes

- Sort option label "Nearby" → **"Closest"** (`PostFilters.vue:279`), display text only;
  keep the internal value `'Nearby'` so `sortMessages`'s `selectedSort === 'Nearby'` branch
  still matches.
- "unread post" → **"new post"** in `MessageListCounts.vue:31`; also change
  `useNavbar.js:175` `'unseen post'` → `'new post'` for consistency.

### I. Sticky-filter audit + fix

Ensure every browse filter persists in `settings`:

- `browseView` (Nearby/mygroups) — already sticky. ✓
- `browseSort` — already sticky. ✓
- **post type ("Show these posts")** — currently **not** sticky (the `type` ref only emits).
  Add `settings.browseType` (default `'All'`): read it into the `type` ref on mount, and
  write via `saveAndGet` when it changes, mirroring `sort`.
- `browseMaxDistance` — new, sticky by construction.
- Verify specific-group selection persists as expected via `browseView`.

### J. Layout

Slider left, sort right, in the same filter row. Add a `.distance` grid area to the
`.filters` CSS grid (`PostFilters.vue:335-392`) alongside `.group`/`.type`/`.sort`.

## Settings schema additions

- `settings.browseMaxDistance`: number (miles) or null. null/absent = no limit. Default null.
- `settings.browseType`: string, one of `All` | `Offer` | `Wanted`. Default `All`.

## Docs updates

Update `RIPPLING-OUT-FOR-MEMBERS.md` and `RIPPLING-OUT-FOR-MODERATORS.md` (and the rollout
`plans/rippling-out-rollout/CHANGES-FOR-MEMBERS.md` / `CHANGES-FOR-MODERATORS.md`) to explain:
the Nearby feed now shows the most relevant posts first (closest/newest/quietest balance,
unseen first), and members can use the distance slider to limit how far away posts come from.
Check the "How does this work?" help link target (`PostFilters.vue:51`) points at the right
explainer.

## Edge cases

- **No known location** (`me.lat`/`lng` both 0): reach block is skipped; feed falls back as
  today; the slider has nothing to filter — hide/disable it.
- **`mygroups` view**: slider hidden; count threading unchanged for that view.
- **Own posts**: always included regardless of distance (distance ≈ 0).
- **Successful/taken posts**: unchanged — still excluded from "unseen" treatment.
- **Mark seen**: `MessageList.vue::markSeen` marks the whole underlying feed seen (not just
  the visible subset). Decision: keep marking the whole nearby feed (matches today) — flag
  for confirmation whether it should mark only the within-distance visible set.
- **Locked sort order**: unaffected; score is stable within a session per ID.

## Testing

- **Go**: unit test the scorer (formula + clamps + reachRadius=0). Extend
  `test/isochrone_reach_test.go` to assert score-desc order and presence/non-negativity of
  `distance`. Run via the status API (`POST /api/tests/go`); never `go test` directly.
- **Client**: vitest for the new slider persistence, the `messagesForList` distance filter,
  the bucketed score ordering, `hasNonDefaultFilters`, and the copy changes. Update
  `PostFilters.spec.js`.
- **Live container**: rebuild `freegle-apiv2-live` (points at `db-live`) and hit
  `/isochrone/message` for user 36757945 against live data; confirm the top posts are now the
  closest/most-relevant rather than the farthest-newest, and that `distance` is present and
  blurred.

## Deferred / future

- Digest/email honouring `settings.browseMaxDistance` server-side.
- Optional server-side feed payload filtering by distance.

## Open decisions (confirm before build)

1. Badge definition: include sort/view changes, or only narrowing filters (type, distance,
   group)? Proposed: only narrowing filters.
2. Slider max = 30 miles (with the top = unlimited). Acceptable, or different cap?
3. Mark-seen with an active distance filter: mark the whole feed (current) or only the
   visible within-distance set?
