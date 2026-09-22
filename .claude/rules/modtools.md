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

## Holds are advisory in most places

Only a couple of the surfaces that show a hold actually enforce it, and several never clear it.
A held post can therefore still be acted on elsewhere, and a stale hold can pin a row after the
post was rejected. The hold flag also leaks across communities, so counts, notifications and
chase-ups under-report.

Do not assume a hold blocks anything unless you have checked that specific path.

The badge has its own version of this: it required a content check to have been recorded, so
held posts that had never been checked were missing from the count entirely.

## The all-communities list is one row per post

Unlike the per-community queues, the all-communities message list is keyed by the message, so a
post in several communities appears once. That is deliberate. A moderator asking why a post
"only shows once" there and several times elsewhere is seeing two different lists, not a bug.

## Rippled-in copies leak into per-group queues

A post that rippled **into** a group has an approved row there. Queues that filter on the
collection are fine; a queue that joins on the message id **without** excluding rippled-in rows
shows the receiving group work belonging to somebody else's community. That is what put other
communities' posts in a moderator's Edit queue, and an earlier diagnosis blamed backup-moderator
access, which was wrong.

Any per-group moderation query needs to say what it wants about rippled-in rows.

## Things that look broken because they are not wired up

- **Community boundary editing on the map page does nothing**: the editing bodies are commented
  out, so the page loads and saves nothing.
- **A newly drawn area looks unsaved.** The remap runs some seconds after the write, and the
  page reads before it lands.
- **A moderator route is a redirect shim that is load-bearing.** Links in moderator emails go
  through it, so removing it breaks those mails rather than tidying a route.
- **A post that vanishes after editing** is a server-side effect of editing a pending post, not
  the member's browser.
- **A rejected post can stay pinned** by a hold that the reject never cleared, and a reported
  post below the quorum stays live in browse and digests.

## Keyword lists replaced in name only

Concern keywords were meant to replace worry words, but the old per-community setting is still
consulted, so both lists are live and they disagree. Changing one does not change behaviour the
way you expect.

Separately, TrashNothing re-subscribes its members by mail, which can reinstate someone who was
banned.

## See also

- `.claude/rules/rippling.md` - one post, many group rows.
- `docs/moderators/` - what moderators are told these screens do.

## The roster date is not when anything happened

`chat_roster.date` is rewritten to now by **every** roster call: mark-as-read, Away or Offline
presence, and Closed all bump it, not just the status change you are looking for. A Blocked row
dated today may have been blocked months ago and merely opened today. Counting "blocks made
today" from it overstated a day by a third.

The moment a member pressed Block is in the API request log, not the table:
`{api_version="v2"} |= "/apiv2/chatrooms" |= "\"status\":\"Blocked\""` in Loki, one per distinct
member and room. The same applies to any "when did they do X" question answered from a roster
watermark: `lastmsgseen` and friends move forward on ordinary viewing.
