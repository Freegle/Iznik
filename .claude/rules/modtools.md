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

## A Mod2Mod chat has no user1 or user2

A moderators' chat (`chattype = 'Mod2Mod'`) belongs to its group and has `user1` and `user2`
NULL. Any check that treats "no user1 and no user2" as "the chat does not exist" returns 404 to
every moderator, with the room and the access rules both fine. `GET /chat/:id/message` did this
from 2026-03-20 (fd3a6c755), and posting from 2026-03-28 (70bdee1f4). Test existence by the row's
`id`, then gate with `canSeeChatRoom` plus `mod2ModAllowed` (active moderators only, as
`listChats`).

## A chat that "never arrived" may sit on a merged-away account

An account merge has to move chat rooms *and* their `chat_roster` rows. Before July 2026 the
email-verify merge did neither reliably: the room update failed outright when the survivor
already had a room with the same person (the unique key on user1, user2, chattype), leaving
every room in that column on the soft-deleted loser, and roster rows were never moved at all.
The member sees a conversation vanish, or sees messages arrive in the chat but is never
emailed. No error is logged anywhere.

Until October 2026 the Go API had two merges: the moderator merge, `user.MergeUsersTx`, and a
cut-down copy for the email-verify case in `session/merge.go`, which moved fewer tables, wrote
nothing to `logs` and soft-deleted the loser. There is now one. Confirming an email that
belongs to another account calls `user.MergeUsersTx`, so it moves the same tables, deletes the
loser outright and writes the same `User`/`Merged` pair of log rows, one per account with
`byuser` the member. Do not add a second merge path; the cut-down one is how chats got stranded.
`docs/developers/reference/account-merge.md` says what a merge does. The PHP `User::merge()`
in iznik-batch is a separate copy used only by the TrashNothing sync, and nothing checks it
against the Go one.

For merges before October 2026 the loser is not findable from `logs` by name. Two traces
survive: the loser's `users.deleted` is the same second as the survivor's
`users_emails.validated` for the email that moved, and the loser's messages were re-attributed
to the survivor, so a stranded room holds messages from someone who is neither participant.
The second also matches support accounts that write into members' chats, so use it only to
confirm the first. `chat:repair-merged-rooms` finishes such a merge from a loser,survivor file;
`user/merge.go` `mergeChatRooms` is what a merge does now.

To a partner such as TrashNothing this looks like the poster changing account mid-chat: a member
posts with one email, signs in with Google under another (two accounts), then confirms the first
address, and the poster's Freegle id changes under the conversation. That is the member joining
their own accounts, not misrouted mail.

## "A freegler" on screen does not mean the account has no name

`user/namevalidation.go` (`SanitizeDisplayName`, Discourse 9587) rewrites a non-volunteer's
display name to "A freegler" when it trades on the Freegle brand ("freegle", "ilovefreegle",
"freecycle"), pairs "freegler" with an authority word, or is made only of authority words
("Admin", "Support Team"). `iznik-batch/app/Support/NameSanitiser.php` is the copy the mail
uses. The rewrite happens on every read; `users.fullname` is untouched and nothing on the
account records that it was rewritten, so the API, ModTools, chat and the spam warning email
all show "A freegler" with no sign that a name was ever set. `utils.TidyName` produces the same
string for an empty name, so the two cases are indistinguishable on screen.

Every place that shows a member's name has to apply the rewrite itself; nothing does it centrally.
The member-site chat list and chat header (`chat/chatroom.go` `listChats`, which `GetChatRoom`
reuses) built the name from `users.fullname` directly until October 2026, so the scam accounts
showed as "ilovefreegle" in Chats beside a profile saying "A freegler" (Discourse 10173/52).
A new name path needs `SanitizeDisplayName` with `IsNameExempt`, and a test with a brand name.

Read the stored name before saying what a member chose. On 2026-09-20 the scam that mailed the
group forwarders created 4,580 accounts from its own From header, every one stored as
"ilovefreegle"; members, moderators and the warning email all saw "A freegler", and a reply on
Discourse (10174/40) then said the scammer might have picked that name. They had picked
"ilovefreegle"; "A freegler" was the sanitiser.
