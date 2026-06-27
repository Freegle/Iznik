# Repost reminders & chase-ups: once per item under rippling-out

**Date:** 2026-06-25
**Branch:** `fix/repost-reminder-dedup-rippling`

## Problem

Rippling-out puts a single post on many groups (`messages_groups` rows with
`rippled_in = 1`). Auto-reposting runs per group, which is correct for the repost
itself (it keeps the item fresh in each community). But the poster-facing
*notification* emails were not deduplicated across groups:

- **Auto-repost warning ("Will Repost: …")** — `AutoRepostService` stamped
  `lastautopostwarning` per group (`WHERE msgid = ? AND groupid = ?`, a deliberate
  "multi-group fix"). So each group fired its own warning. A widely-rippled item could
  email the poster once per group, per cycle. Because rippled-in rows get a fresh
  `arrival` as the reach expands over hours/days, their repost windows are *staggered*,
  so even a simple "stamp all groups" 24h dedup leaks repeat emails days apart.
- **Chase-up ("What happened to: …")** — `ChaseUpService` already stamps `lastchaseup`
  on every group of the message when one is sent, so it was largely deduped, but it
  shared the same staggered-window hole (a rippled row reaching max-reposts later could
  initiate a second chase-up).

## Key insight

Every action a poster can take from these emails acts on the **whole item**, not one
group. The buttons are `…/mypost/{msgid}/completed|withdraw|promise`:

- "Mark completed" → `messages_outcomes` row keyed by `msgid` (Taken/Received).
- "Withdraw" → `Withdrawn` outcome on the `msgid`; `MessageSpatialService` also deletes
  the spatial row, so the ripple engine drops the reach and pulls the post from
  rippled-in groups.
- "Promise" → `messages_promises` row keyed by `msgid`.

Both the auto-repost and chase-up candidate queries exclude any message that has an
outcome (`whereNull('messages_outcomes.msgid')`) or promise, so a single response stops
reposting and chasing on **every** group at once. Therefore one email per item loses
nothing — and sending more than one is pure noise.

## Design

Anchor the poster-facing notification to the post's **home posting**
(`messages_groups.rippled_in = 0`), while leaving the repost itself per-group.

1. **Auto-repost warning** — only a `rippled_in = 0` row may send the warning. Rippled-in
   rows are still reposted (kept fresh) but never warn. When the warning is sent, stamp
   `lastautopostwarning` on **every** group of the message (revert to V1's
   `WHERE msgid = ?`) so a rare multi-home cross-post (TN / manual) is also deduped.
2. **Chase-up** — only a `rippled_in = 0` row may initiate a chase-up; keep the existing
   all-groups `lastchaseup` stamp. This closes the staggered-window hole and makes both
   emails behave identically.
3. **Reposting itself stays per-group** — unchanged
   (`test_multiple_groups_each_repost_independently` still passes).

`rippled_in` is a stable home marker (unlike `arrival`, which a repost resets).

### Not changed (deliberately)

- `notifyLanguishing` counts a languishing post once per group, but only ever creates one
  `OpenPosts` notification per user per day with no count stored — no user-visible
  duplication. Left as-is.

## Tests

- `AutoRepostServiceTest::test_warning_sent_once_across_rippled_groups` — home + 2 rippled
  rows, all in the window → `warned == 1`, every group stamped.
- `AutoRepostServiceTest::test_rippled_in_row_reposts_without_warning` — rippled row past
  the repost window reposts (`reposted == 1`) with no warning.
- `ChaseUpServiceTest::test_rippled_item_chased_up_once_from_home` — home + rippled, both
  "max reposts" → `chased == 1`, every group stamped.
- `ChaseUpServiceTest::test_rippled_only_item_is_not_chased_up` — rippled-only row → not
  chased.
- All existing assertions kept (per-group reposts; existing cross-post chase-up dedup).

## Docs

`RIPPLING-OUT-FOR-MODERATORS.md` gains a "repost reminders & chase-ups are once per item"
note; `RIPPLING-OUT-FOR-MEMBERS.md` gains a member-facing line. A separate accuracy pass
verifies both docs against the current rippling implementation.
