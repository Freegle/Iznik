---
last_reviewed: 2026-11-12
last_reviewed: 2026-11-12
owner: Freegle dev team
covers:
  - iznik-server-go/user/merge.go
  - iznik-server-go/merge/merge.go
  - iznik-server-go/sso/relink.go
  - iznik-server-go/test/user_merge_test.go
  - iznik-batch/app/Services/ChatMergedRoomRepairService.php
  - iznik-batch/app/Console/Commands/Chat/RepairMergedRoomsCommand.php
---

# Merging two accounts

One member, two accounts. A merge moves everything from the account being discarded to the
account being kept, then deletes the discarded one. It happens in four ways:

| Who starts it | Where | Who is recorded as doing it |
|---|---|---|
| A moderator or Support, in ModTools | `handleMerge` in `iznik-server-go/user/user.go` (`POST /api/user`, action `Merge`) | the moderator |
| The member, by confirming an email address that belongs to their other account | the `key` branch of `PatchSession` in `iznik-server-go/session/session.go` | the member |
| The member, by accepting a merge offer on the `/merge` page, where they choose which account to keep | `PostMerge` in `iznik-server-go/merge/merge.go` (`POST /api/merge`, action `Accept`; `user1` is kept, `user2` is merged into it) | the kept account |
| The TrashNothing sync, when it finds a member's twin accounts | `HealTNDivergence` in `iznik-server-go/user/partner.go` | the kept account |

Admin can merge any two accounts. Support can merge any two accounts except an Admin account. A
moderator can merge two accounts of communities they moderate, and either may be their own. A merge
keeps the higher system role, so neither account may hold a higher system role than the person
merging: a merge never raises anyone above the person doing it. A moderator cannot absorb a Support
or Admin account, and Support cannot absorb an Admin account, as source or target. Two Moderator
accounts may be merged. Logins, emails and memberships move across, which is why
the system role is the limit.
The same rules apply when a moderator offers a merge (`CreateMerge`, `PUT /api/merge`), because
accepting the offer merges the accounts. `MayMergeAccounts` in `iznik-server-go/user/user.go`
holds them for both paths.

All four call the same function, `MergeUsersTx` in `iznik-server-go/user/user.go`. Its first
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
   `added_origin`. The flags follow `MergedMembershipFlags` (`iznik-server-go/user/user.go`; the
   PHP merge uses `User::mergedMembershipFlags`): an ordinary row on either side makes the result
   ordinary, since either account having chosen the community is enough. If both rows are
   implicit and either is a TrashNothing placement, the result is a placement (`implicit = 1`,
   `rippled = 0`, `tn_unaddressed`), never a ripple join, because a ripple join is later cleared
   and a placement is not. Two ripple joins keep the older row's flags together. When one row
   was placed (by rippling or TrashNothing) and the other chosen, the merge writes the "became
   ordinary" Group/Joined log for the kept account, worded from the placed row's `added_origin`,
   with the reason `merged with an account that chose this community`.
3. **Posts, post history and membership history** move.
4. **Existing log rows** about or by the discarded account are re-pointed at the kept one.
5. **Chat rooms.** `mergeChatRooms` in `iznik-server-go/user/merge.go`. A room between the two
   accounts is deleted, because it would be a chat with oneself. Where both accounts have a room
   with the same other person, or the same community's moderators, the two rooms are folded into
   one: messages, references to the room, roster rows and the latest-message time move, then the
   spare room is deleted. The rest are handed to the kept account. Roster rows follow.
6. **Sessions and logins** move, so the member stays signed in.
7. **Name, system role, join date.** Blank fields on the kept account are filled from the
   discarded one. The higher system role and the older join date win. A TrashNothing id never
   moves, because an account holding one is never merged (see Refusals).
8. **About forty other tables** that reference a user: addresses, donations, images, stories,
   ratings, notifications, volunteering, events and so on. The list is in `MergeUsersTx`; the
   V1 comment there says how it was found. One of them, `ux_experiment_exposures` (which experiment
   arm a send used), is analytics, so it is moved only if the table exists: a merge must not depend
   on it, and a row that clashes with one the kept account already has stays where it is.
9. **Bans** move, and the kept account is removed from any community it is now banned from.
   Both the bans on the discarded account and the bans it made as a moderator keep their
   original dates; `users_banned.date` updates itself on any change, so the merge sets it
   explicitly.
10. **Gift Aid.** The most generous declaration is kept. Every row in `giftaid_asks` (each time
    the donor was asked and did not answer) moves to the kept account.
11. **Two log rows** are written, type `User`, subtype `Merged`, text `Merged <discarded> into
    <kept>`, one against each account, with `byuser` set to whoever started the merge.

After the transaction commits, the discarded account's `users` row is deleted outright. That is
what V1 did, and it is what every table without an explicit move relies on: their rows go with
the foreign key.

## Refusals

A TrashNothing account (one holding a TrashNothing id, or with a TrashNothing address as its
preferred email) is never merged, with another TrashNothing account or with a Freegle account.
`MergeUsersTx` refuses it, so the moderator merge and the email-verify merge both do. That includes
a member who renamed on TrashNothing and so has two accounts: only a TrashNothing id says who
someone is, and `users.tnuserid` is unique, so no two accounts can hold the same one. Their
TrashNothing address goes to the account holding the id TrashNothing names for it, and the other
account keeps its posts and chats (`moveTNAlias`, see "Database Linking" in `trashnothing.md`). See
`.claude/rules/conventions.md`.

An account that is not a TrashNothing account can still hold a TrashNothing address that is not its
preferred one. Two accounts holding two different TrashNothing usernames may be two people, and a
merge cannot be undone, so `MergeUsersTx` refuses that too.

The Related Members list and its count leave out a pair with a TrashNothing account in it
(`MergeableSidesSQL`), and `users:detect-related` does not link one, since the merge would be
refused.

A member can set `canmerge` to false in their settings. If either account has, the merge is
refused.

In the email-verify case a refusal is logged and the confirmation still succeeds: the address
moves to the member's current account and the other account is left as it was.

A TrashNothing address (`@user.trashnothing.com`, bare or a per-group alias) is the exception,
because it belongs to the account holding the TN id TrashNothing names for it. A member cannot
add one: `PatchSession` answers `ret: 12` with the reason and sends no mail, and the batch's
`email_verify` task skips one queued before that. A key already mailed for one is refused when
it is confirmed (403 with the reason, the key spent), with nothing merged and nothing moved.

## The PHP copy

`iznik-batch/app/Models/User.php` has `merge()`, a second port of the V1 function in PHP.
Nothing in `app/` calls it now that `tn:sync` no longer merges. Nothing checks it against
`MergeUsersTx`, so when you add a table to one, add it to the other. Compared on 2026-10-15,
column by column:

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
- **Refusals.** Both refuse a TrashNothing account, two different TrashNothing usernames and
  either account's settings saying `canmerge` is false. PHP reports the first two as a
  `TnUserIdMergeConflict`. Forcing a PHP merge passes `canmerge` and nothing else.
- **Log text.** PHP appends the reason in brackets.

Everything else is the same: one transaction, deletion of the discarded account after commit,
highest role and oldest join date, system role, join date, bans, the best Gift
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
