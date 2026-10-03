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

- **Another moderator's hold never appears.** The pending list's auto-refresh drops, rather than
  parks, a change that leaves the total the same, and a hold never changes the total. Two
  moderators then act on the same post half an hour apart with no clash.

When triaging any of these, get the timing from production logs before reading code. Each of
these was root-caused from logs and the live database, and in one case an earlier diagnosis from
code alone was simply wrong.

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

## ModTools is national

There is no community anywhere in it since `2026_09_20_000001_remove_group_model.php`: every
queue lists everything, and a moderator is anyone whose `users.systemrole` is Moderator,
Support or Admin. A screen that appears to filter by community is a bug, not a setting.

## See also

- `.claude/rules/rippling.md` - the reach is the only spread mechanism.
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

## The sender's address is not in the database

`chat_roster.lastip` is written only by the roster-status call (`chatroom.go`, the Blocked /
Online / mark-as-read path). A member, or a script, that only sends messages never touches it,
so for those senders every roster row is NULL, `logs_events` has nothing, and
`messages.fromip` only covers posts. A "which addresses did these accounts use" query returns
an empty set with no error.

The record is the apiv2 request log in Loki: `{app="freegle",source="api"}`, JSON fields `ip`,
`user_id`, `endpoint`, `session_id` and `request_id`. The `api_headers` stream, kept seven
days, has the request headers under `request_headers` and joins on `request_id`. Pull with
`query_range`, `direction=forward`, at most 5000 lines per call; a line filter over the whole
retention is slow and a paged pull across the busy hours will time out silently, so bound each
call to a day or an hour.
