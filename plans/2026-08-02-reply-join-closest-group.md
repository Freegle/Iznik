# Reply auto-join: pick the closest group, not an arbitrary one

Status: **implemented and committed, awaiting Edward's push/PR decision** (2026-08-02).

Branch `fix/reply-join-closest-group`, commit `85433db5e`, in worktree `/home/edward/FreegleDocker-replyjoin`
(based on master tip `f9eeef481`). Not pushed, no PR. Frontend unit suite green: **14705 passed, 0 failed**,
run via that worktree's own status API on port 12087. eslint clean on both changed files. Not verified in a
browser - unit coverage was judged the right level for this change.

What landed, against the requirement below:

- Rule 1 was **already correct** and is unchanged - the membership loop still breaks on the first overlap, so
  a member of any of the post's groups is never re-joined. Now pinned by a test rather than left implicit.
- Rule 2 is the fix: a new module-level `closestGroupToReplier()` picks by distance from the replier to each
  candidate group's centre, using the existing `milesAway()` from `~/composables/useDistance` (no new
  haversine). `groupToJoin` is no longer reassigned on every loop iteration.
- `msg.groups` does **not** carry lat/lng - only the full Group object does. So each candidate is looked up
  via `groupStore.fetch()`, parallelised with `Promise.all` and cached by the store. This matches the
  existing pattern in useChat.js / MessageTag.vue / OurMessage.vue.
- Unknown replier location falls back to the previous behaviour (last group in the list), now an explicit
  tested branch rather than an accident of the loop.
- Single-group posts short-circuit before both the location check and any group-store fetch - the answer is
  forced regardless of distance, so the common case costs no extra API calls.

Open questions from the original capture, as resolved: distance to group **centre** (polygon/nearest-edge
noted as a possible future refinement, not built); decided **client-side** in the composable, no new
endpoint; no-location falls back to previous behaviour. Known minor wrinkle: `milesAway()` rounds, so two
groups within the same rounded mile tie-break on array order - immaterial at the distances involved.

The rest of this document is the original capture, kept for the reasoning and the evidence.

## Requirement (Edward, 2026-08-02)

> If you reply to a post that is in multiple groups, then we should only join you if you have
> no groups in common, and then we should join you to the closest group to you, not the home
> group or a random one.

Two rules:

1. **Only join when there is no overlap.** If the replier is already a member of *any* group the
   post is on, join nothing.
2. **When we do join, join the group closest to the replier** - not the post's origin/home group,
   and not whichever group happens to come first or last in the list.

## Current behaviour

`iznik-nuxt3/composables/useReplyStateMachine.js:972-990`:

```js
for (const messageGroup of msg.groups) {
  groupToJoin = messageGroup.groupid        // overwritten every iteration
  for (const key of Object.keys(myGroups.value || {})) {
    const group = myGroups.value[key]
    if (messageGroup.groupid === group.id) { isMember = true; break }
  }
  if (isMember) break
}
if (!isMember && groupToJoin) {
  await authStore.joinGroup(myid.value, groupToJoin, false)   // manual=false -> logs text='Auto'
}
```

- **Rule 1 is already satisfied.** The loop checks every group on the message and breaks on the
  first overlap, so an existing member of any of them is never joined again.
- **Rule 2 is not.** `groupToJoin` is reassigned on every pass, so when there is no overlap it ends
  up as the **last** entry in `msg.groups` - arbitrary, driven by whatever order the API returned.
  This is the "random one" to replace.

## What "closest" should mean

Groups expose `lat`/`lng` (group centre) from the Go API - `iznik-server-go/group/group.go:44-45`.
The replier has their own location. Simplest correct-enough rule: pick the message group whose
`lat`/`lng` is nearest the replier's location.

Open questions to settle before building:

- Distance to the group **centre** or to its **polygon** (nearest edge)? Centre is easy and
  available client-side; polygon is more accurate for large or oddly-shaped areas.
- Should this be decided **client-side** in the composable, or should the API pick? Client-side
  keeps it in one place and needs no new endpoint, but the member's location has to be reliably
  available at reply time.
- What if the replier has no known location? Fall back to current behaviour, or to the post's
  origin group.
- Does this interact with the reach gate (`isNotInReachError`, same file, line ~1002)?

## Why this matters (the case that prompted it)

Glen (user 43767304, ChitChat post 616010, 2 Aug 2026):

- 8 Jun: someone posts `OFFERED: outdoor bulkhead lamp (Runcton PO20)` to **Portsmouth_Freegle**.
  Runcton is near Chichester - close to Glen; Portsmouth is not his nearest community.
- 14 Jun 08:42:55: Glen replies "Interested" (chat_messages 108946403) and is auto-joined to
  Portsmouth in the same second (`Group/Joined`, `text='Auto'`).
- 19 Jun: he leaves Portsmouth.

That post was only on one group, so this change alone would not have altered Glen's case - but it
is the same complaint ("automatically added to groups an unreasonable distance from their home
town"), and for any multi-group post the current code can pick the furthest of several.

## Test plan

- Unit tests in `iznik-nuxt3/tests/unit/composables/useReplyStateMachine.spec.js` (the file already
  exists and covers `joinGroup`):
  - post on 3 groups, replier is a member of one -> no join at all
  - post on 3 groups, replier a member of none -> joins the nearest by the replier's location, not
    the first or last in the list
  - replier has no location -> defined fallback, whatever we choose above
- Check the existing spec's current expectations - they may assert the present arbitrary choice and
  need updating.

## Related, deliberately NOT in scope

Rippling re-adding people to groups they explicitly left (Glen's other complaint, same ChitChat
post). Investigated 2026-08-02: `ExpandService` only treats a leave as an opt-out when the most
recent `Group/Joined` was `text='Rippled'`, so leaving an ordinary or `Auto` membership does not
stop rippling re-adding you. 2,406 current rippled memberships across 1,454 users were created
after that user had left the group. Edward reviewed and decided **not** to change this. Recorded
here only so the finding is not rediscovered from scratch.
