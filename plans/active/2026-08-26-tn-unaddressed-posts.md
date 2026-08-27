# TN unaddressed posts: reporting, mod messaging and member restrictions

Branch: `feature/tn-unaddressed-posts` (worktree `FreegleDocker-tn-unaddressed`), stacked on
PR #1013 (`tn-posts-api`). Completes the TODO left open by that PR's section P.

## Problem

PR #1013 ingests TN posts via the API and places them on a Freegle group chosen from the
post's own lat/lng, not from anything the TN poster asked for. When the resolved group is
not in TN's `freegle_group_ids` for that poster, the poster has consented to nothing on
Freegle: they are not really a member of that community, and its moderators have no
relationship with them. `messages_groups.mod_messaging_allowed = 0` records this at
ingestion; nothing reads it yet.

Three consequences have to be handled.

1. **Reports must not land on a local mod team.** Today a report is a User2Mod chat message
   referencing the post, and two distinct reporters pull the post back to Pending on every
   group (`microvolunteering.RecordReportVerdict` → `SendForReviewAllGroups`). For these
   posts there is no local mod team to ask, so the post is removed from the platform at the
   same threshold of two reports instead.
2. **Moderators must not be able to message the poster.** No chat button, no standard
   messages, no Blank Reply, no Edit - the same shape as a rippled-in copy, where the mod
   gets the queue actions but not the ones that talk to the freegler.
3. **The restriction is per MEMBER on the members page**, not per post: a TN user who has
   only ever posted this way cannot be chatted to at all. A user who has *also* posted
   normally is a real member and is unaffected.

## Definitions

Both derived, no new columns:

- **Unaddressed post**: the message has a `messages_groups` row with `rippled_in = 0` and
  `mod_messaging_allowed = 0`. Only the TN API ingestion ever writes 0, so this is false for
  every other post. `rippled_in = 0` restricts the test to the post's ORIGIN row: the ripple
  engine inserts its copies without the column and they take the table default (1).
- **Unaddressed-only member**: the user has at least one unaddressed post and no origin row
  with `mod_messaging_allowed = 1`. Mixed posters (one unaddressed post plus any ordinary
  post) are NOT restricted, per the requirement.

## Decisions taken

- Chat block is **moderator-facing only**. Ordinary members can still reply to the post; that
  is the point of ingesting it, and the reply relays back to TN as today.
- "Removed from the platform" = **soft delete everywhere** - all `messages_groups` rows
  deleted, `messages.deleted = NOW()`, ripple frozen, freebie-alerts removal queued, and a
  per-group `logs` Message/Deleted audit row so the removal is not silent. Recoverable by
  Support. The TN user account is untouched.
- **Reject stays available** on these posts, forced onto the existing no-message path used
  for rippled-in copies. Mods keep the ability to take a post off the queue without
  deleting it platform-wide, and no message reaches the poster.
- **No new eligibility gate on reporting.** Report stays open to any logged-in user, exactly
  as it is today, rather than inventing a membership requirement. Abuse is bounded by the
  distinct-reporter unique key, the poster-can't-report-self rule, the soft (recoverable)
  delete and the audit log. Flagged in the PR for a product call.

## Tasks

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Go `modmessaging` package: `PostIsUnaddressed`, `UserIsUnaddressedOnly`, `UsersUnaddressedOnly`, `RemoveUnaddressedPost` | ✅ | New package so `chat`, `message`, `membership`, `microvolunteering` can all import it without a cycle |
| 2 | Report outcome: `RecordReportVerdict` deletes instead of pending for unaddressed posts | ✅ | Same quorum of 2, different terminal action |
| 3 | Report route: new `Report` action on POST /message, and suppress the modmail path server-side | ✅ | Stale app bundles must not be able to reach a mod team |
| 4 | Expose `mod_messaging_allowed` at message level in both message payloads | ✅ | `message.go` GET and `message_list.go` mod queue |
| 5 | Expose the member-level flag on membership + user payloads | ✅ | Batch query in `enrichMembers` so the members page stays one query |
| 6 | Server guards: `PutChatRoom` User2Mod-on-behalf-of, message `Reply`/`Edit`, membership `Leave (Approved) Member` | ✅ | Defence in depth behind the UI |
| 7 | Frontend: `MessageReportModal` uses the new action and drops the mod-chat wording | ✅ | |
| 8 | Frontend: `ModMessage` / `ModMessageButtons` - no Edit, no Blank Reply, no stdmsgs, Reject no-message, explanatory notice | ✅ | |
| 9 | Frontend: `ModMember` / `ModMemberButtons` - no Chat, no Mail, no stdmsgs | ✅ | |
| 9b | Frontend: warning notice on the member card - "Trash Nothing user who has not opted into Freegle, so they cannot be contacted" | ✅ | Explicit requirement: the mod must be told WHY the buttons are gone, not just find them missing |
| 10 | Tests: Go (modmessaging, report quorum, guards), vitest (modal + mod components), Laravel if touched | ✅ | Go 4288 pass, vitest 16170 pass, Laravel 6183 pass. |
| 11 | Docs: `docs/moderators/02-moderating-posts.md`, `docs/developers/reference/trashnothing.md` | ✅ | Freshness check covers these paths |
| 12 | CI red on this branch AND its base: stale spatial-index location loses the whole post on the email path | ✅ | `IncomingMailService::createGroupPostMessage()` now checks the id is in `locations` before writing `users.lastlocation`, as `GroupPostIngestionService` already did. Regression test seeds a postcodes point absent from `locations`; red without the guard, green with it |

## Test plan

- Go: `curl -X POST http://localhost:12066/api/tests/go`
- Vitest: `curl -X POST http://localhost:12066/api/tests/vitest`
- Laravel: `curl -X POST http://localhost:12066/api/tests/laravel`
- Docs: `node scripts/check-docs-freshness.mjs`

All three green: Go 4288 pass / 0 fail, vitest 16170 pass / 0 fail, Laravel 6183 pass /
0 fail, docs freshness OK.

Run the suites ONE AT A TIME. Running Go and Laravel together starves
`MigrateReachBoundsSchemaCommandTest`, whose spatial-index DDL then exceeds phpunit.xml's
`defaultTimeLimit="30"` and errors. It passes on its own.

No browser pass: this worktree's dev database is unseeded (no groups, users or messages),
so ModTools has nothing to render. The Go tests drive the real endpoints against a real
database and the vitest component tests assert the notice text.
