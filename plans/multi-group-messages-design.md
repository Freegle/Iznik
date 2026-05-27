# Multi-Group Messages Design

**Date**: 2026-04-09
**Status**: Design approved, pending implementation plan

## Summary for Volunteers and Moderators

### The problem

When someone cross-posts an item to multiple groups — for example, a charity like Mind Brighton clearing 50 items across three nearby Freegle groups — the current system creates three completely separate posts for each item. That's 150 posts the user has to manage instead of 50. Each one is independent: promising an item to someone on one group doesn't promise it on the others. Marking it as Taken on one group doesn't mark it on the others. The user has to manually manage outcomes on every copy separately. At scale, this becomes impossible.

Trash Nothing works around this by creating multiple copies internally and then marking all copies as Taken when appropriate. But because Freegle treats each copy as a separate post, keeping Freegle and Trash Nothing in sync is fragile — our integration has to track and update multiple independent posts that are really the same item.

### The solution

This change makes it so a single post can appear on multiple groups at once. There's one post, one set of promises, one outcome — but it's visible on several groups. When the poster marks something as Taken, it's taken everywhere, because it's a physical item.

### What changes for moderators

- **Moderation stays per-group.** If a post is pending on your group, you approve or reject it on your group. What another group's mods do with the same post doesn't affect you.
- **Holding a post is per-group.** If you hold a post, only your group's copy is held. Other groups' mods can still act on theirs.
- **Marking as spam or deleting removes it from your group only.** The post stays on other groups unless their mods also remove it.
- **You'll see an indicator** when a post is on multiple groups (e.g. "Also on: Freegle Cambridge").
- **Outcomes are global.** When the poster marks something as Taken or Received, it's taken everywhere — because it's a physical item.
- **Reporting a post** sends the report to all groups' mods, since the content is the same everywhere.

### What changes for freeglers

Nothing changes in how you post. You still post to one group. The system may show your post on nearby groups automatically in future, but that's a separate piece of work.

## Overview

Allow a single message to exist on multiple Freegle groups simultaneously. The database schema (`messages_groups` with composite unique key `(msgid, groupid)`) already supports this, but all code layers (V1 PHP, V2 Go API, Nuxt3 client) currently assume a message belongs to exactly one group.

## Goals

1. Make all code layers correctly handle messages on multiple groups
2. Deduplicate Trash Nothing cross-posts via `tnpostid`
3. Preserve statistics accuracy (a message on N groups counts as N in group-level stats)
4. Support independent per-group moderation
5. Prepare the foundation for future "ripple-out" posting (out of scope for this plan)

## Out of Scope

- Ripple-out mechanism (system-driven cross-posting to adjacent groups) — future work
- Multi-group selection at compose time — posting stays single-group
- Retrospective merging of existing TN duplicates
- V1 PHP bug fixes (V1 is being retired; audit only)

## Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Moderation state | Per-group | Each group is sovereign; `messages_groups.collection` already per-group |
| Hold/release | Per-group | `heldby` moves from `messages` to `messages_groups` |
| Spam classification | Per-group | `spamtype`/`spamreason` move to `messages_groups` |
| Delete/withdraw by mod | Per-group | Mod only has authority over their group |
| Outcomes (Taken/Received) | Global | Physical item — taken everywhere |
| Promises/reneged | Global | Physical item commitment |
| Edits | Global | Message content is the same everywhere |
| Likes/views/microactions | Global | User engagement with content |
| Reposting | Per-group | Each group has own schedule (`autoreposts`, `lastautopostwarning`) |
| Message reports | Single group | Report to the shared group (user + message) with most recent posting |
| Stats | Per `messages_groups` row | Preserves current counts when deduplicating |
| Chat | Global | Chat is about the physical item, not the group |
| New group arrival state | Fresh (Pending, unheld) | No state inherited from other groups |
| Search/browse dedup | Deduplicate by msgid | Message appears once with all groups in array |
| Digest dedup | Per-user msgid set | Message appears once per digest, shows all groups |
| Mod notifications | Per-group | Each group's mods need their own notification |
| Draft vs TN conflict | N/A | Freegle users and TN users are separate; can't both post same item |

## Schema Changes

### Columns moving from `messages` to `messages_groups`

| Column | Type | Notes |
|--------|------|-------|
| `heldby` | `bigint unsigned, nullable` | FK to `users.id` |
| `spamtype` | `varchar(50), nullable` | |
| `spamreason` | `varchar(255), nullable` | |

### Migration steps

1. **Add columns** to `messages_groups` (additive, non-breaking)
2. **Copy data**: For messages with non-null values, populate all their `messages_groups` rows
3. **Deploy code** that reads/writes from new locations
4. **Drop columns** from `messages` (cleanup after confidence period)

### Tables that stay global (no changes)

- `messages` (core content: subject, body, attachments, location, type)
- `messages_outcomes`, `messages_outcomes_intended` — physical item state
- `messages_promises`, `messages_reneged` — physical item commitments
- `messages_edits` — content changes
- `messages_attachments`, `messages_items`, `messages_related`
- `messages_likes`, `messages_by`, `microactions`
- `messages_spamham`, `messages_ai_declined`
- `messages_drafts` (already has groupid)

### Tables already per-group (no changes needed)

- `messages_groups` (the junction table itself)
- `messages_history` (already has groupid, unique on `(msgid, groupid)`)
- `messages_postings` (already has groupid)
- `messages_popular` (already has groupid)
- `messages_spatial` (already has groupid)
- `messages_index` (already has groupid)
- `newsfeed` (already has groupid)

## Go API Changes

### Struct changes

- `MessageGroup` struct: add `Heldby *uint64`, `Spamtype *string`, `Spamreason *string`
- `MessageSummary.Groupid`: remains as-is for backwards compat in list views (represents context group)

### Function changes

| Function | Current | New |
|----------|---------|-----|
| `handleHold()` | `UPDATE messages SET heldby = ?` | `UPDATE messages_groups SET heldby = ? WHERE msgid = ? AND groupid = ?` |
| `handleRelease()` | `UPDATE messages SET heldby = NULL` | `UPDATE messages_groups SET heldby = NULL WHERE msgid = ? AND groupid = ?` |
| `handleDeleteMessage()` | `DELETE FROM messages_groups WHERE msgid = ?` (all groups) | `DELETE FROM messages_groups WHERE msgid = ? AND groupid = ?`. If last group row, delete the `messages` row |
| `handleSpam()` | Global spam marking | Per-group: set `spamtype`/`spamreason` on `messages_groups`, set `collection = 'Spam'` |
| `handleMove()` | DELETE all groups, INSERT one | Redesign as add-to-group / remove-from-group |
| `logAndNotifyMods()` | Logs to primary group, notifies all | Log to the specific group the action was taken on |
| `getPrimaryGroupForMessage()` | Used as fallback everywhere | Reduce usage; most callers should use explicit groupid from request context |
| Repost scheduling (`message.go:727`) | Uses `MessageGroups[0].Arrival` | Evaluate per-group — each group has its own arrival and `autoreposts` counter |
| `convertToDraft()` (`message.go:2225`) | Uses primary group, deletes ALL `messages_groups` rows | Use `req.Groupid`; delete only that group's row; soft-delete message only when last group removed |
| Edit subject keyword rebuild (`message.go:2695`) | Uses primary group's keyword | Use contextual `req.Groupid` — keywords vary across groups (e.g. OFFER vs OFFERED) |
| Mod-delete audit log (`message.go:3067`) | Logs to primary group | Log to the group the action was taken on |

### Functions already correct (no changes)

- `handleApprove()` — already takes groupid, includes in WHERE
- `handleReject()` — already takes groupid
- `isModForMessage()` — checks ANY group
- `getAllGroupsForMessage()` — returns all groups
- Repost settings fetch — iterates all groups

### TN dedup on message intake

When a message arrives via email:
1. Extract `tnpostid` from message
2. If `tnpostid` is non-null, check for existing message with same `tnpostid`
3. If match: INSERT new `messages_groups` row for the new group, skip creating new `messages` row
4. If no match: create message as normal

**Race condition**: Two TN copies arriving simultaneously could both create new messages. Handled by background dedup job (see below). Race goes away when TN moves from email to API submission.

### Message reports

When a user reports a message, the report is made in the context of a single group — the group that both the user and the message are on, choosing the most recently posted one. The report opens a chat with that group's mods. It is NOT fanned out to all groups.

### Message lifecycle after last group removal

When a per-group delete removes the last `messages_groups` row, the `messages` row is soft-deleted immediately (`deleted = NOW()`). This makes it invisible to queries. The existing `purge:messages` job then handles hard deletion:
- `purgeDeletedMessages()` hard-deletes soft-deleted messages after 2 days
- `purgeStrandedMessages()` hard-deletes messages with no `messages_groups` rows after 2 days (safety net)

We do NOT change the purge logic. The existing jobs already handle orphaned messages correctly.

## Nuxt3 Client Changes

### Core principle

**Use contextual groupid, not `groups[0]`.** Every ModTools view operates in the context of a specific group. Pass that group's ID down via props for all per-group actions.

### Store changes (`message.js`)

- `getByGroup()`: Change from `groups[0].groupid === groupid` to `message.groups.some(g => parseInt(g.groupid) === parseInt(groupid))`

### ModTools components

| Component | Change |
|-----------|--------|
| `ModMessageButton.vue` | Accept `groupid` prop from parent instead of extracting `groups[0]` |
| `ModMessage.vue` | Pass contextual groupid to child components |
| `ModMessageCrosspost.vue` | Use contextual groupid |
| `ModStdMessageModal.vue` | Use contextual groupid |
| `ModMessageDuplicate.vue` | Use contextual groupid |
| `ModLog.vue` / `ModLogGroup.vue` | Show all groups, highlight current context group |
| Sorting in `useModMessages.js` | Sort by arrival of the contextual group |

### ModTools display additions

- Badge/indicator when a message is on multiple groups (e.g. "Also on: Freegle Cambridge")
- Withdraw warning: "This will remove the message from [Group X] only. It remains on [Group Y, Z]."

### Non-mod components

| Component | Change |
|-----------|--------|
| `MyMessage.vue` | Show all groups. Edit/outcome actions remain global. |
| `OutcomeModal.vue` | Outcomes are global — no groupid needed |
| `MessageReportModal.vue` | Report to a single group: the one the user and message share, most recently posted |
| `ExportPost.vue` | Show all group names |

### Compose flow

No change — stays single-group posting.

## Background TN Dedup Job

Periodic job (Laravel artisan command or Go background task):

1. Find messages with the same non-null `tnpostid` that have different `messages.id`
2. For each set of duplicates:
   - Pick the oldest message as canonical
   - Move `messages_groups` rows from duplicates to canonical message
   - Move `messages_history`, `messages_postings` rows
   - Update `chat_messages.refmsgid` references
   - Delete duplicate `messages` rows
3. Log all merges

Only processes new arrivals going forward — no retrospective merging of historical data.

## V1 PHP Audit (No Fixes)

V1 is being retired. These are documented for awareness — V2 must handle them correctly:

| V1 Issue | V2 Coverage |
|----------|-------------|
| `reject()` updates ALL groups (missing groupid in WHERE) | V2 correct — takes groupid |
| `sendForReview()` updates ALL groups | V2 must handle per-group |
| `autoapprove()` deletes all groups then re-inserts | V2 must handle per-group |
| `move()` deletes all, inserts one | V2 redesigned as add/remove |
| `spam()` is global delete | V2 redesigned as per-group |
| `ModBot` uses first group for rules | V2 must evaluate rules per-group |

## Stats Impact

**Unresolved — requires investigation.**

All stats queries across Go, PHP, and Laravel must be audited to determine:
- Which count `messages.id` (distinct messages) vs `messages_groups` rows
- Impact of TN dedup on each stat
- Whether group-level vs platform-level stats need different counting

**Design principle**: Group-level stats should count `messages_groups` rows (a message on 3 groups = 3 in group stats). Platform-level stats need case-by-case assessment.

## Deduplication in Listings and Notifications

### API list/search dedup

When a user is a member of Groups A and B and a message is on both, the message must appear **once** in list/search results, not twice. API queries that fetch messages across multiple groups must `GROUP BY msgid` or equivalent, returning the message once with all its groups in the `groups[]` array.

**Affected code:**
- `message_list.go` — `ListMessages` queries `messages_groups` per group; must deduplicate across groups
- `search.go` — `GetWordsExact()` and `GetWordsStarts()` return per-group rows; must deduplicate
- Group pending/spam counts should continue counting per `messages_groups` row (correct for per-group mod work counts)

### Digest dedup

Digests are becoming cross-group. When building a digest for a user, maintain a "seen msgids" set. If a message has already been included (from another group), skip it. The message should appear once, showing all groups it's on.

### Push notification dedup

- **Mod notifications** (e.g. new pending message): Per-group is correct. If a message is pending on Groups A and B, both groups' mods need to know.
- **User notifications**: If user-facing push notifications for new messages are added in future, they must deduplicate by msgid per user. Currently no user-level push notifications exist for new posts, so this is a future consideration only.

### Chat dedup

Chat (`chat_messages.refmsgid`) links to the message, not to a group. This is correct — a chat about an item is global, like outcomes. No group context needed on chat.

## Adding a Message to a New Group

When a message is added to a new group (via TN dedup or future ripple-out):
- New `messages_groups` row gets `collection = 'Pending'`, `heldby = NULL`, `spamtype = NULL`
- The message arrives fresh on the new group — no state inherited from other groups
- Each group's mods evaluate it independently

## Microvolunteering

`microvolunteering.go` currently writes `spamreason` to `messages.spamreason`. This must be updated to write to `messages_groups.spamreason` for the relevant group context. The microvolunteering system already filters messages by the volunteer's group memberships (via `groupid` query param and `memberships` join), so the group context is available — the volunteer only sees messages from groups they're on.

## Implementation Order (Bottom-Up)

1. Schema migrations (add columns to `messages_groups`, copy data)
2. Go API changes (hold/release/spam/delete per-group, TN dedup on intake, microvolunteering fix)
3. API list/search dedup across groups
4. Background dedup job
5. Nuxt client changes (contextual groupid, multi-group display)
6. Digest dedup
7. Stats audit and fixes
8. Schema cleanup (drop old columns from `messages`)
9. V1 audit confirmation (verify V2 covers all identified gaps)
