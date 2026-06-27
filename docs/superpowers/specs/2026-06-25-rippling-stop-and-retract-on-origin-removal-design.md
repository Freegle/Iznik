# Rippling: stop-and-retract on origin removal (incl. scoped runs) — design

Date: 2026-06-25

## Problem

When a post leaves `messages_spatial` because it was **rejected/removed on its origin
group** (or withdrawn / expired / deleted), rippling currently does the wrong thing:

1. **`ExpandService::removeStale` stops but never retracts.** It is the only reactor to
   "post left `messages_spatial`", and it merely deletes the `rippling_reach` row. The
   already-rippled `messages_groups` copies on neighbouring groups stay `Approved`/live,
   so a rejected (or withdrawn) post keeps showing everywhere it had rippled. In the live
   Stroud "Fish" incident the 6 rippled copies only vanished because the poster happened
   to *withdraw* later; a mod's spam-reject on the home group would have left them live on
   all 6 neighbours.

2. **All retraction is dark during the scoped (group-experiment) run.** Both `removeStale`
   and `pullRippledPostsFromLeftGroups` are gated `if (!$scoped)`. For a scoped post
   neither runs, so the reach row lingers `status='expanding'` and the scoped cron keeps
   rippling the rejected post into *more* groups.

`Taken`/`Received` posts intentionally **stay** in `messages_spatial`
(`MessageSpatialService` marks them `successful=1` rather than removing them), so this
trigger fires only on reject / withdraw / expire / delete — exactly the set where the post
is no longer a live offer.

## Fix

Turn "post left `messages_spatial`" into a single **stop-and-retract** step that also runs
for scoped runs. In `ExpandService`:

- Replace `removeStale` with `removeStaleAndRetract($dryRun, &$stats, $onlyMsgid,
  $withinPolyWkt)`. For each `rippling_reach` row whose `msgid` is no longer in
  `messages_spatial` (scoped to the in-scope subset when scoped):
  - **Retract** — soft-delete (`deleted=1`) every `rippled_in=1` `messages_groups` copy of
    that `msgid` (`retractRippledCopiesForRemovedPost`), writing one `Message/Deleted`
    audit log per group (`text='Rippling: removed on origin removal'`). Stat:
    `pulled_on_removal`.
  - **Remove now-purposeless rippled memberships** — for each group a copy was pulled from,
    if the poster has **no other live (`deleted=0`) post on that group** and their
    membership there is a ripple-join (`memberships.rippled=1`), `DELETE` that membership.
    Stat: `memberships_removed`.
    - **Deliberately log NO `Group/Left`.** The re-ripple guard keys on any `Group/Left`
      after a `Group/Joined text='Rippled'` (text-agnostic), so logging a `Left` here would
      permanently bar this poster's *future* legitimate posts from ever rippling into that
      group — wrong, especially since the trigger also fires on withdraw/expire. The
      removal is a system cleanup, not a user opt-out; the retraction is already audited by
      `Message/Deleted`. The dangling `Group/Joined text='Rippled'` (no `Left`, no
      membership) is exactly the state that lets a later ripple re-add the membership.
  - **Stop** — delete the `rippling_reach` row (existing behaviour; also releases held
    replies, since `ripple:release-replies` treats a missing reach row as "gone"). Stat:
    `removed` (unchanged meaning: reach rows dropped).
- Make `pullRippledPostsFromLeftGroups` scope-aware too and run it for scoped runs (same
  dark-during-experiment flaw; the poster-leaves trigger).
- Run both retraction steps for scoped runs, restricted to the in-scope subset
  (`onlyMsgid`, or origin point within `withinPolyWkt`, matching `advanceDue`).

### Scope filters
- `onlyMsgid`: `AND mr.msgid = ?` (and `AND mg.msgid = ?` for the leave-pull).
- `withinPolyWkt`: `ST_Contains(ST_GeomFromText(?,3857), ST_SRID(POINT(lng,lat),3857))` on
  the reach origin (for the leave-pull, on `messages.lng/lat`).

## Out of scope
- Organic memberships (`rippled` not `1`) and memberships where the poster still has a live
  post on the group are never touched.
- Outcome propagation for Taken/Received is unchanged (those posts stay in spatial).

## Tests (Laravel, `ExpandServiceTest`)
1. Origin removal soft-deletes the `rippled_in` copies, writes a `Message/Deleted` log per
   group, and drops the reach row.
2. The poster's `rippled=1` membership on a pulled group is removed when they have no other
   live post there; kept when another live post exists; an organic membership is never
   removed.
3. No `Group/Left` is logged, and a later post by the same poster still ripples into the
   group and re-adds the membership (future re-ripple not poisoned).
4. Scoped (`onlyMsgid`) run retracts only the in-scope post.
5. Scoped (`withinPolyWkt`) run retracts only posts whose origin is inside the polygon.
6. `pullRippledPostsFromLeftGroups` runs under a scoped run.

## Docs
`RIPPLING-OUT-FOR-MODERATORS.md`: rejecting/removing a post on its origin group now pulls it
from every rippled group and stops it spreading; the poster is removed from groups they were
only on because of that post.
