---
paths:
  - "iznik-nuxt3/modtools/**"
  - "iznik-server-go/user/**"
  - "iznik-server-go/chat/**"
---

# Traps in ModTools

Most of these reach us as a moderator saying something "isn't showing". The cause is almost
never the thing they point at.

## A moderator sees nothing, and the data is fine

- **Email history for a member in no communities.** The endpoint is gated on the moderator and
  the member sharing an active membership, so a member who has left everything returns a refusal
  and the tab looks empty. The emails were in the log the whole time. Support reports this as
  "ModTools isn't showing this email as being sent, or any others".
- **The pending list disagrees with the menu count.** A remembered community filter can hide the
  work while the count still includes it. The tell is that changing community and changing back
  fixes it permanently, and the phone is fine.
- **Another moderator's hold never appears.** The pending list's auto-refresh drops, rather than
  parks, a change that leaves the total the same, and a hold never changes the total. Two
  moderators then act on the same post half an hour apart with no clash.

When triaging any of these, get the timing from production logs before reading code. Each of
these was root-caused from logs and the live database, and in one case an earlier diagnosis from
code alone was simply wrong.

## Queued per group, addressed per person

Push notifications are queued **per group**, but the payload is built **per user**: it is the
aggregate work summary across every community that moderator covers. A post touching several of
one moderator's communities therefore sends several byte-identical banners at the same instant.
Any repeat is pure duplication and never carries extra information.

## "Mark all read" is not scoped to moderator chats

The mark-all-seen action updates the whole roster with no chat-type scoping, so a moderator
using it in the ModTools chat list, which only *shows* moderator chats, also marks their
personal member chats read. Their member-side unread badge then never appears.

When a moderator reports a missing unread badge, look for a mark-all-seen call made from
ModTools shortly before.

## Rippled-in copies leak into per-group queues

A post that rippled **into** a group has an approved row there. Queues that filter on the
collection are fine; a queue that joins on the message id **without** excluding rippled-in rows
shows the receiving group work belonging to somebody else's community. That is what put other
communities' posts in a moderator's Edit queue, and an earlier diagnosis blamed backup-moderator
access, which was wrong.

Any per-group moderation query needs to say what it wants about rippled-in rows.

## See also

- `.claude/rules/rippling.md` - one post, many group rows.
- `docs/moderators/` - what moderators are told these screens do.
