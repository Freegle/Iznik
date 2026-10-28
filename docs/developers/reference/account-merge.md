---
last_reviewed: 2026-10-28
owner: Freegle dev team
covers:
  - iznik-server-go/user/merge.go
  - iznik-server-go/sso/relink.go
  - iznik-server-go/test/user_merge_test.go
  - iznik-batch/app/Services/ChatMergedRoomRepairService.php
  - iznik-batch/app/Console/Commands/Chat/RepairMergedRoomsCommand.php
---

# Merging two accounts

One member, two accounts. A merge moves everything from the account being discarded to the
account being kept, then deletes the discarded one. It happens in three ways:

| Who starts it | Where | Who is recorded as doing it |
|---|---|---|
| A moderator or Support, in ModTools | `handleMerge` in `iznik-server-go/user/user.go` (`POST /api/user`, action `Merge`) | the moderator |
| The member, by confirming an email address that belongs to their other account | the `key` branch of `PatchSession` in `iznik-server-go/session/session.go` | the member |
| The TrashNothing sync, when it finds a member's twin accounts | `HealTNDivergence` in `iznik-server-go/user/partner.go` | the kept account |

All three call the same function, `MergeUsersTx` in `iznik-server-go/user/user.go`. Its first
argument is the account to discard and its second the account to keep. V1 had a single
`User::merge()`, and `MergeUsersTx` is its port. **Do not write a second merge path.** The Go
API had a cut-down one for the email-verify case until October 2026, and it is how chats went
missing (see below).

## What a merge does

Everything runs in one transaction. In order:

1. **Emails.** The kept account's preferred address stays preferred. The discarded account's
   addresses move across with their preferred flag cleared.
2. **Memberships.** Where only one account is a member, the membership moves. Where both are,
   the kept account takes the higher role and the older join date, with that older row's
   `added_origin`. It is `implicit` only if both rows were, since either account having chosen
   the community is enough; `rippled` is merged the same way. The TrashNothing sync's PHP merge
   does the same.
3. **Posts, post history and membership history** move.
4. **Existing log rows** about or by the discarded account are re-pointed at the kept one.
5. **Chat rooms.** `mergeChatRooms` in `iznik-server-go/user/merge.go`. A room between the two
   accounts is deleted, because it would be a chat with oneself. Where both accounts have a room
   with the same other person, or the same community's moderators, the two rooms are folded into
   one: messages, references to the room, roster rows and the latest-message time move, then the
   spare room is deleted. The rest are handed to the kept account. Roster rows follow.
6. **Sessions and logins** move, so the member stays signed in.
7. **Name, system role, join date, TrashNothing id.** Blank fields on the kept account are filled
   from the discarded one. The higher system role and the older join date win.
8. **About forty other tables** that reference a user: addresses, donations, images, stories,
   ratings, notifications, volunteering, events and so on. The list is in `MergeUsersTx`; the
   V1 comment there says how it was found. One of them, `ux_experiment_exposures` (which experiment
   arm a send used), is analytics, so it is moved only if the table exists: a merge must not depend
   on it, and a row that clashes with one the kept account already has stays where it is.
9. **Bans** move, and the kept account is removed from any community it is now banned from.
   Both the bans on the discarded account and the bans it made as a moderator keep their
   original dates; `users_banned.date` updates itself on any change, so the merge sets it
   explicitly.
10. **Gift Aid.** The most generous declaration is kept.
11. **Two log rows** are written, type `User`, subtype `Merged`, text `Merged <discarded> into
    <kept>`, one against each account, with `byuser` set to whoever started the merge.

After the transaction commits, the discarded account's `users` row is deleted outright. That is
what V1 did, and it is what every table without an explicit move relies on: their rows go with
the foreign key.

## Refusals

A TrashNothing account is never merged with a Freegle account, and two accounts carrying two
different TrashNothing ids are two different people. `handleMerge` and `MergeUsersTx` refuse
both. See `.claude/rules/conventions.md`.

A member can set `canmerge` to false in their settings. If either account has, the merge is
refused.

In the email-verify case a refusal is logged and the confirmation still succeeds: the address
moves to the member's current account and the other account is left as it was.

## The PHP copy

`iznik-batch/app/Models/User.php` has `merge()`, a second port of the V1 function in PHP. It
is used only by the TrashNothing sync command. Nothing checks it against `MergeUsersTx`, so
when you add a table to one, add it to the other. Compared on 2026-10-15, column by column:

- **Tables.** The same, except that the PHP merge never moves `volunteering` or
  `communityevents`. `volunteering` has no foreign key, so after a PHP merge its rows point at
  a user id that no longer exists.
- **Preferred email.** When the kept account has no preferred address, PHP makes the discarded
  account's preferred address the preferred one. Go promotes the kept account's own oldest
  address instead. The Go behaviour is deliberate: the kept account is the one the member, or
  the moderator, chose, and its address should not be displaced by a flag inherited from the
  other account.
- **Membership settings** when both accounts are in the same community. PHP overwrites the kept
  membership's `configid`, `settings` and `heldby` with the discarded one's. Go keeps the kept
  membership's values and only fills blanks, for the same reason.
- **Chat rooms.** PHP has the logic Go had before October 2026: a room between the two accounts
  is left as a chat with oneself, messages that refer to a folded room are not updated, and the
  other person's roster row is lost when rooms are folded.
- **Refusals.** Go refuses a TrashNothing and Freegle mix and two different TrashNothing ids.
  PHP has neither check. Both refuse when either account's settings say `canmerge` is false.
- **Log text.** PHP appends the reason in brackets.

Everything else is the same: one transaction, deletion of the discarded account after commit,
highest role and oldest join date, system role, join date, TrashNothing id, bans, the best Gift
Aid declaration, the two log rows.

## A moderator's Discourse account

Discourse knows a moderator by their Freegle user id. A merge keeps one of the two accounts, and
if the one that goes is the one Discourse was linked to, Discourse no longer knows the moderator.
Their email and name stop updating, and their next sign-in would make them a new, empty Discourse
account. This is not rare: the email-verify merge keeps the account the member is logged in as,
which is often the new one.

`sso.RelinkMerged` repairs it. It finds the Discourse account linked to an account merged into
the kept one, using the merge log rows, which travel with the kept account. Then it links that
Discourse account to the kept id with a `sync_sso` carrying the email Discourse already has, so
Discourse matches it by email. A second `sync_sso` brings the email, name and avatar up to date.
The moderator does not need to sign out of ModTools or Discourse.

It runs in three places:

- **After every merge** whose kept account is a moderator, in the background (`MergeUsersTx`).
- **When a moderator signs in to Discourse**, before the sign-in is answered (`DiscourseSSO`), so a
  merge from before this existed is repaired the next time they use Discourse.
- **On request**, `POST /api/discourse/relink` with `{"userid": <kept id>}`, for Support and Admin.

All three need `DISCOURSE_API`, `DISCOURSE_APIKEY` and `DISCOURSE_SECRET` in the Go API, and do
nothing without them.

## Finding a merge afterwards

Search `logs` for type `User`, subtype `Merged`. The text names both ids, so a search on the
discarded id finds it even though that account no longer exists. Merges done by email
verification before October 2026 wrote no log row; the only traces are the discarded account's
`users.deleted` time matching the kept account's `users_emails.validated` time, and posts
re-attributed to the kept account.

## Repairing an old merge

Email-verify merges before July 2026 could strand chat rooms on the discarded account.
`chat:repair-merged-rooms` in iznik-batch finishes such a merge from a file of
discarded,kept pairs. `ChatMergedRoomRepairService` does what `mergeChatRooms` now does, in the
same order.
