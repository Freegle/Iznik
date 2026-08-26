---
last_reviewed: 2026-08-26
owner: Freegle dev team
covers:
  - iznik-server-go/changes/**
  - iznik-batch/app/Console/Commands/TrashNothing/**
  - iznik-batch/app/Services/TrashNothing/Verify/**
  - iznik-batch/app/Models/UserDeletion.php
  - iznik-batch/app/Services/Mail/Incoming/IncomingMailService.php
  - iznik-batch/app/Console/Commands/Dedup/**
  - iznik-batch/app/Services/UnifiedDigestService.php
---

# TrashNothing Integration Documentation

This document describes how Freegle integrates with TrashNothing (TN), including technical details, user experience differences, synchronization mechanisms, and areas for improvement.

## Overview

TrashNothing is a partner platform that syndicates with Freegle groups. TN users can post messages to Freegle groups and interact with Freegle members without needing a Freegle account. The integration is primarily **email-based** for message delivery, with **API-based** synchronization for user profiles, ratings, and offer syndication through LoveJunk.

## User Identification

### Email-Based Detection

TN users are identified by their email addresses matching the pattern `*@user.trashnothing.com`.

TN emails follow a specific format:
```
{username}-g{groupid}@user.trashnothing.com
```

Example: `john-g1234@user.trashnothing.com`

The `-g{groupid}` suffix indicates which Freegle group the user joined through TN. This suffix is stripped when displaying user names to avoid confusion.

### Database Linking

Each TN user is linked to Freegle via:
- `users.tnuserid` - The TrashNothing user ID (BIGINT)
- Email address in `users_emails` table

When processing TN messages, the system:
1. Checks for TN user ID from message header (`x-trash-nothing-user-id`)
2. Falls back to Freegle user ID header (`x-iznik-from-user`)
3. Looks up user by email address
4. Creates new user if not found

### Email Canonicalization

To prevent duplicate user accounts when the same TN user joins multiple groups:
1. Strip `-g{groupid}` suffix: `john-g123@user.trashnothing.com` → `john@user.trashnothing.com`
2. Strip plus addressing
3. Remove dots (Gmail-style normalization)

**Key functions**:
- `User::isTN()` - Check if user is from TN
- `User::findByTNId($id)` - Look up user by TN ID
- `User::removeTNGroup($name)` - Remove `-gxxx` suffix from display names
- `User::canonMail($email)` - Normalize TN email addresses

## Integration Mechanisms

### Message Delivery (Email-Based)

TN sends messages to Freegle via email with special headers:

| Header | Purpose |
|--------|---------|
| `x-trash-nothing-source` | Message source (Facebook, Web, Mobile) |
| `x-trash-nothing-user-id` | TN user identifier |
| `x-trash-nothing-post-id` | Unique TN post ID |
| `x-mailer` | May indicate TN app |

The `sourceheader` field stores the message origin:
- `TN-Facebook` - Posted via TN Facebook integration
- `TN-Web` - Posted via TN website
- `TN-Mobile` - Posted via TN mobile app
- `Platform` - Posted via Freegle directly

### Post Ingestion via API, and the email cutover

Posts are moving from the email path above to the TN public API. `tn:sync`
(`TNSyncCommand`) drives `PostSyncer` → `GroupPostIngestionService`. The email
path (`IncomingMailService`) is deliberately frozen and untouched.

**`FREEGLE_TN_INGEST_POSTS_VIA_API` is the single switch for the whole cutover.**
It is one flag rather than a staged pair because neither half is safe alone: the
API on with email still routing double-writes every post, and email off with the
API off drops TN posts entirely. Pre-cutover comparison uses `tn:sync --dry-run`
and `tn:parity-check`, neither of which needs the email path switched off. On, it:

| Effect | Where |
|---|---|
| `tn:sync` ingests posts from the TN API | `TNSyncCommand` |
| The email path stops **routing** TN group posts (it still **archives** them) | `TnEmailRoutingGate`, called by `IncomingMailController` / `IncomingMailCommand` |
| `tn:verify-email-coverage` starts running hourly | `routes/console.php`, and the command's own guard |
| The `tn:sync (posts)` scheduled-outcome check goes live | `ScheduledOutcomeRegistry` |

Five differences from the email path are intentional, not bugs, and all matter
when reading any coverage report:

- **Group placement is by coordinates**, via `Location::groupsNear()` on the
  post's own lat/lng — never TN's `group_id`, which is TN's internal ID and
  drifts from Freegle's boundaries.
- **Crossposts collapse.** TN gives every per-group copy of a post its own post
  id and emails each one, so the email path creates N messages for an item
  crossposted to N groups. The API path ingests only the source post (empty
  `group_id`) and discards the copies, letting Freegle's own rippling do the
  cross-posting — see `GroupPostIngestionService::REASON_CROSSPOST`. Note the
  flag does **not** reach into rippling to say so: `ExpandService` never reads
  it, and holds back only a message whose post id another live message still
  carries ([rippling-algorithm.md §4b](rippling-algorithm.md)). So an
  API-ingested post that lands beside unmerged email-era copies of the same item
  sits out until `tn:merge-crossposts` collapses the set, flag or no flag —
  during the cutover, watch `tn_duplicate_sat_out` in the `ripple:expand
  complete` stats, and run the merge (by hand on the batch host, as below) when
  it climbs.
- **The subject's type prefix is normalized.** The email path keeps whatever
  prefix TN put in the email subject, which is what the member typed — `OFFERED:`
  is common. The API path always synthesizes `strtoupper(type) . ': '` from TN's
  own `type` field, so the same post reads `OFFER:`. Both resolve to the same
  `Message::determineType()`, so this is a naming convention rather than a
  content difference, and `ParityComparer` canonicalizes it before comparing
  subjects — otherwise every such post fails parity twice (same-group content
  and Loki entry) and buries the real mismatches.
- **Deletion is final.** The API path's idempotency guard
  (`GroupPostIngestionService::existingMessageForGroup()`) counts a *deleted*
  message as already ingested, unlike the email path's `findLiveTnMessage()`,
  which skips deleted ones. Deleting is a decision — a moderator, the member, or
  a user purge — and every overlapping sync window re-fetches the post, so
  ignoring `deleted` here would resurrect it repeatedly. The skip is reported in
  Loki with `existing_deleted` set, so it is distinguishable from a plain
  idempotent skip. To deliberately re-ingest one, clear its `tnpostid` (and
  `messageid`) on the deleted row and re-run the sync for that window — which is
  exactly what the two paths that soft-delete a TN message as a *duplicate*
  (the email path's lost-create-race branch and `TnMergeCrosspostsCommand`)
  already do.
- **`sourceheader` is `TN-API`**, not the email path's `TN-Web` / `TN-Facebook` /
  `TN-Mobile` (the API returns no posting-client field). Only the `TN-` prefix is
  load-bearing, and it has to be there: `LoveJunkInvoiceService` splits the monthly
  TN invoice on `sourceheader LIKE 'TN-%'`, `LoveJunkService` attributes the post's
  source by it, and `ProcessBackgroundTasksCommand` uses it to skip creating
  freebie alerts for TN posts, which TN syndicates itself.

**Routing matches the email path**, including approval. A post from an unmoderated
poster (`ourPostingStatus` `DEFAULT`/`UNMODERATED`, which is also the fallback used
when the poster isn't a member of the group its coordinates resolved to) is *not*
approved on arrival: it lands Pending, and `messages:contentcheck` promotes it
within a minute if clean, or holds it for a moderator if a concern keyword or
content rule matches. `GroupPostIngestionService` deliberately does not notify mods
or add such a post to the spatial index — both belong to the content check, so a
clean post makes no mod work and a flagged one is never live in the meantime.
Because these posts often have no membership row, `ContentCheckService::isUserModerated()`
applies the same `DEFAULT` fallback for a post with a `tnpostid`; without that they
would sit in the mod queue with nothing able to promote them.

Photos come from the API's own `photos[].images` array rather than being scraped
out of the post body. TN documents that array as ordered *smallest to largest*
(`PublicApi/docs/Model/Photo.md`), so `GroupPostIngestionService::bestPhotoUrl()`
takes the **last** entry — taking the first ingested a thumbnail where the email
path got the full-size image.

`tn:parity-check` reclassifies two families of TN-side mutation rather than
failing on them, because nothing on the Freegle side can prevent either: a post
whose `date` was bumped out of the query window, and a post whose **title** TN
edited after the partner email was sent (the email path records the subject at
send time, the API path records the title as it stands now). Both are detected
the same way — `expiration` is pinned at original-publish + 90 days while `date`
moves on a repost or edit, so `expiration - date != 90 days` means TN mutated the
post. The title check is deliberately narrow: it applies only where the subject
is the *sole* disagreement on every layer, since a subject difference is also
what a genuine truncation or encoding bug would look like.

**Run it against a database that has the groups.** The email side is driven by
TN's post-log CSV, whose `To` is `<nameshort>@groups.ilovefreegle.org` — the
group's Freegle `nameshort`, not TN's numeric `group_id` (that appears only in
the API path's post JSON). `IncomingMailService` resolves it by `nameshort` and
drops the post as "Post to unknown group" if there is no such row, before
writing anything, so on a disposable parity database cloned without those groups
every post vanishes and Layers 3-5 compare zero pairs — a PASS that checked
nothing. `ParityComparer::parseUnknownGroupDrops()` counts those drops per
group, the report lists them, and `tn:parity-check` fails outright when they
account for the whole email side.

### Verifying nothing is dropped after the cutover

Once the email path stops routing, `tn:parity-check` no longer works: it compares
what each path *wrote*, and only one path writes. `tn:verify-email-coverage`
replaces it, checking coverage only.

| Piece | Role |
|---|---|
| `TnEmailRoutingGate` | Decides which inbound mail stops being routed. Narrow by design — a wider predicate silently drops mail. It mirrors `IncomingMailService::route()` phase by phase and matches only what would have reached Phase 5, so chat replies, bounces, digest replies, auto-replies and volunteer mail all keep routing. |
| `ArchiveInventoryService` | Lists the TN posts that arrived in a window — the independent witness. Walks the archive via the shared `IncomingArchiveReader` (also used by `mail:recover-dropped-merged`); only the TN-specific selection and keying live here. |
| `CoverageVerifier` | Checks each against `messages.tnpostid` and classifies the absences. |
| `TNVerifyEmailCoverageCommand` | Orchestrates, backfills, reports. |

Both mail entry points archive the raw email *before* routing
(`IncomingMailController::receive()`, `IncomingMailCommand`), which is what makes
the archive usable once routing stops. Archive retention is 48h
(`mail:cleanup-archive`) and that is the hard bound on how far behind real time
verification can run — the default lag is 8h.

**Most absences are expected**, and a report that treated them as misses would be
unusable. `CoverageVerifier` separates crosspost copies (the largest category),
posts outside every group boundary, deleted posts, resolved outcomes
(`PostSyncer::RESOLVED_OUTCOMES`) and posts whose TN `date` was bumped out of the
window, from genuine gaps. Distinguishing them needs a single-post
`GET /posts/{id}` per absence — `PostSyncer::lookupPostById()`.

Genuine misses can be backfilled automatically (`FREEGLE_TN_VERIFY_AUTO_INGEST`,
off by default) via `PostSyncer::ingestFetchedPost()`, which reuses the normal
ingestion path and tags the resulting Loki entry `backfill`. The rails are in the
command: source posts only, a per-run cap that alerts rather than backfilling en
masse, an age guard, and escalation when a post we already backfilled is still
missing. Note a persistent gap between TN's partner email feed and their public
API means a small residue of genuine misses is the expected steady state, so the
command fails only on those escalations, not on any miss at all.

Design rationale and the live evidence behind each rule are in
`plans/tn-api-post-ingestion.md` section S.

### Photo Handling

TN sends photo links as URLs like `https://trashnothing.com/pics/{id}`. Freegle:
1. Detects these URLs in message content
2. Scrapes and downloads photos locally (120 second timeout)
3. Stores photos in Freegle's image system
4. Replaces TN URLs with local attachments

### API Synchronization (Daily Cron)

The `tn_sync.php` cron job runs daily to synchronize:

#### Ratings API
```
https://trashnothing.com/fd/api/ratings?key={TNKEY}&page={page}&per_page=100
```
- Syncs ratings given to Freegle users by TN community members
- Updates `ratings` table with `tn_rating_id`

#### User Changes API
```
https://trashnothing.com/fd/api/user-changes?key={TNKEY}&page={page}&per_page=100
```
Syncs:
- Reply time statistics
- About me text
- Username changes
- Location updates
- Account removal notifications

### Changes Feed (TN pulls from Freegle)

The traffic above goes TN to Freegle. The other direction is a single polling endpoint,
`GET /api/changes?partner={key}&since={timestamp}` (Go, `iznik-server-go/changes/`),
authenticated with a row in `partners_keys`. It answers "what has moved since?" with
three arrays: `messages`, `users` and `ratings`. `since` defaults to an hour ago.

**`since` is clamped to at most 90 days ago** (`changes.MaxSinceLookback`). None of the
queries behind the endpoint carry a `LIMIT`: every matching row is materialised into a Go
slice and then into a JSON body, so the cost of one request is set entirely by how far
back the caller asks. On 2026-08-17 a single call with a `since` of 1947 made the six-way
`UNION` examine 17.9M rows over 130s and the OOM killer took apiv2 - and monit with it -
on that node. At 90 days the same request cost about 1GB of apiv2 RSS when measured on
2026-08-18, which the node absorbs without noticing. A partner who asks for more is not
rejected; they are answered from the clamped window, and the response reports which
window that was in `changes.since`, so "I asked for a year and got 90 days" is
detectable rather than silent.

Nothing serialises the endpoint, so that is the per-call cost, not a ceiling: concurrent
catch-ups multiply it, and a dozen at once would be as fatal as the single unclamped call
was. The clamp bounds one request, not the endpoint. Expect a full catch-up to take tens
of seconds and return tens of MB - slow is normal here, and not by itself a fault worth
chasing when a partner reports a timeout.

Each entry in `users` carries a `type`:

| type | Means | What the partner should do |
|------|-------|----------------------------|
| `Modified` | The user's profile has changed (`users.lastupdated` moved) | Re-read the user |
| `Deleted` | The user has been forgotten or hard-deleted | Delete their copy - the `id` is all they get |

`Deleted` entries are read from `users_deletions`, a tombstone table written by every
path that destroys a user:

- `UserManagementService::forgetUser()` - the GDPR wipe, reached from the 14-day limbo
  expiry (`processForgets`), the inactive-user sweep, and support purges queued as
  `user_forget` background tasks.
- `User::forget()` - the Eloquent equivalent, used by the TN sync when TN tells us one
  of their accounts has gone.
- `UserManagementService::deleteFullyForgottenUsers()` and `deleteYahooGroupsUsers()` -
  the hard deletes, where the `users` row itself goes.

The tombstone exists precisely because `users` entries are otherwise derived from
`users.lastupdated`, which needs a row to read. Without it a purged member simply stops
appearing in the feed, and the partner keeps a copy of someone who asked to be gone.
Rows have no foreign key to `users` for the same reason, and are pruned after
`UserManagementService::DELETION_RETENTION_DAYS` (90 days) - deliberately the same window
as the `since` clamp above, so a partner catching up over the longest window the endpoint
will answer still sees every tombstone that survives. Moving either number without the
other opens a gap in which a purged member stops appearing in the feed while the partner
still holds a copy.

Deletions are appended after the modified users, so a partner applying the array in
order ends on the deletion rather than resurrecting a user who was also edited inside
the same window.

### LoveJunk Offer Syndication

LoveJunk acts as a bridge for offer syndication to TN users. When a Freegle OFFER is posted:

1. Creates draft on LoveJunk API: `POST /freegle/drafts`
2. Updates draft: `PUT /freegle/drafts/{draftId}`
3. Tracks in `lovejunk` table with `ljofferid`

Chat messages between Freegle members and TN/LoveJunk users are synced via:
```
POST /freegle/chats/{ljofferid}
```

Group setting `groups.onlovejunk` controls whether offers are syndicated (default: YES).

## Functional Differences for TN Users

### Restrictions

| Feature | Native Freegle | TN User |
|---------|---------------|---------|
| Chat notification settings | Editable | Disabled (managed by TN) |
| Auto-repost messages | Available | Disabled (TN has own reposting) |
| Email notification preferences | Editable | Server-controlled |
| Profile image editing | Direct upload | Via TN profile |

### TN-Specific Behaviors

1. **Removal Notifications**: TN users ALWAYS receive email notification when removed/banned from a group (native users get optional notification). This prevents confusion when users are subscribed on both platforms.

2. **Spam Filtering**: TN email addresses (`@trashnothing.com`) are excluded from some spam checks since messages are already vetted by TN.

3. **Profile Images**: Retrieved from TN API: `https://trashnothing.com/api/users/{username}/profile-image`

## How TN Users Appear to Members

### On the Freegle Website

TN users appear largely the same as native users:
- Display name shows without `-g{groupid}` suffix
- Profile image loaded from TN if available
- Ratings and reply time synced from TN
- Messages appear with normal formatting

### Differences Members May Notice

- Email addresses may show `@user.trashnothing.com` domain
- Profile links may redirect to TN profile
- Some messages may include TN-specific formatting

## How TN Users Appear to Moderators

### ModTools Display

#### Member View (`ModMember.vue`)

- Shows LoveJunk user ID if present: `LoveJunk user #{ljuserid}`
- Hides chat notification settings section
- Disables auto-repost settings toggle
- Shows warning if attempting to merge accounts with TN emails

#### Message History (`MessageHistory.vue`)

- Source displayed as "TrashNothing" instead of "Email"
- Detects TN messages by checking `fromaddr` for `trashnothing.com`

#### Chat Messages (`ChatMessageText.vue`)

- TN links (`https://trashnothing.com/fd/`) are automatically converted to clickable hyperlinks
- Allows mods to easily access TN posts referenced in chat

### Detection Methods

Mods can identify TN users by:
1. Email domain `@user.trashnothing.com`
2. Message source showing "TN-*" values
3. Presence of `tnuserid` in user data
4. LoveJunk ID displayed in member panel

## Database Schema

### Users Table
```sql
users.tnuserid  BIGINT UNSIGNED  -- TrashNothing user ID
users.ljuserid  BIGINT UNSIGNED  -- LoveJunk user ID
```

### Messages Table
```sql
messages.sourceheader  VARCHAR(80)  -- e.g., "TN-Facebook", "TN-Web"
messages.tnpostid      VARCHAR(80)  -- TN post identifier
```

## Cross-posts and reposts

TN lets a member send one item to several Freegle groups. That arrives as **one inbound
email per group**, each carrying the same `X-Trash-Nothing-Post-Id`. A **repost** - the
member offering the same thing again days later - is a different thing: TN allocates it a
**new** post id, so the two cannot be told apart by id.

The two are handled at different layers, deliberately.

| Case | Same `tnpostid`? | Handled where | Result |
|------|------------------|---------------|--------|
| Cross-post: one item, N groups, N emails | Yes | Ingestion, `IncomingMailService::createGroupPostMessage` | One `messages` row with N `messages_groups` rows |
| Repost: same item offered again later | No - new id each time | `UnifiedDigestService` content key | Collapsed within a digest; both remain live posts on the site |

### Cross-posts: one message, many groups

The first email for a post id creates the message as usual. A later email carrying a post
id we already hold does **not** create a second message - `attachGroupToTnMessage()` adds
a `messages_groups` row to the existing one, along with that group's own
`messages_history` and `logs` rows. Per-message work (the `messages_items` link, the TN
image attachments) is not repeated.

This makes a TN cross-post structurally identical to a Freegle-native one, which matters
because everything downstream already collapses on `msgid` - `isochrone/message.go` uses
`DISTINCT ms.msgid` for the browse feed and `COUNT(DISTINCT ms.msgid)` for the navbar
badge. No read-side special-casing is needed, and none should be added.

Two emails for one post id arriving together can both pass the lookup and both create a
message. No lock is used to prevent that. Each insert autocommits, so id order is commit
order: whichever row got the higher id was written after the lower one had committed, and
sees it on the check straight after its own insert. That one is soft-deleted and its group
attached to the winner, so a single message is left. This holds across cluster nodes
because it only reads committed rows - unlike `GET_LOCK`, which Galera does not
replicate and which would only appear to work while writes happen to be pinned to one
node.

There is deliberately no unique index on `messages.tnpostid`. It cannot be added while
duplicates remain, and there are a great many: ~656k sets covering ~1.87M live messages,
up to 30 copies each.

Before this, each email created its own message, so one item became N messages sharing
only a post id - each with its own `messages_spatial` and `rippling_reach` rows, and so
shown once per copy to anyone whose reach or membership covered more than one of the
groups (Discourse 9808/689).

### Reposts: content, not id

`UnifiedDigestService::getDeduplicationKey()` keys on `fromuser` + normalised subject +
location, with an equal `tnpostid` treated as a definitive match in `bodiesMatch()`.
Keying on the post id alone was tried and reverted (`423c6b0e6`): because a repost gets a
fresh id, the digest listed the same item once per posting - "Small lamp" four times,
27 such items in four days (Discourse 9808/#233).

Note the deliberate asymmetry: a repost is **not** collapsed on the browse feed. Two
postings days apart are two real posts, and the member meant to make both.

### Merging copies created before this

`php artisan tn:merge-crossposts` collapses a set onto its lowest-id message. It derives
every `msgid`-bearing column from `information_schema` rather than a hand-written list,
moves what it can onto the canonical message, and deletes rows that cannot move because
the canonical already has an equivalent - notably `messages_spatial`, whose `msgid` is
UNIQUE and which is what the feed reads.

It defaults to `--days=90`. Only recent posts are in `messages_spatial`, so only those
can appear on a feed or ripple; older sets are left alone because merging them would touch
an enormous number of rows to no visible effect. Over 90 days there are ~11k sets and ~24k
messages to merge, against ~656k sets across all time. `--limit` runs it in batches, and
`--dry-run` reports without writing.

**This is a command, not a migration.** Laravel migrations are the source of truth for
dev and CI only - production does not run them - so this is run by hand on the batch host.
Nothing depends on it having been run: until a set is collapsed, its messages are simply
excluded from rippling (below).

### Copies and mail

A member gets one immediate email per post, however many of their groups it is on.
`UnifiedDigestService::processGroupImmediate()` runs once per group, so without a check
across groups a cross-posted item would be mailed to the same member once per group they
share with it. It records each send in `rippling_reach_notified` and reads that back on a
later group's pass, which is the same ledger that stops the reach mailer re-mailing
someone this path has already reached.

### Copies and rippling

A message sharing its post id with another live message does not ripple into new groups.
Each copy would otherwise ripple on its own account, so one item would reach people once
per copy. The check is self-limiting: once a set is collapsed there is no other live
message to match, and the post ripples like any other.

### Groups Table
```sql
groups.ontn        TINYINT  -- Whether group is syndicated to TN
groups.onlovejunk  TINYINT  -- Whether offers go to LoveJunk (default: 1)
```

### Ratings Table
```sql
ratings.tn_rating_id  -- TrashNothing rating ID for sync deduplication
```

## GDPR / Account Deletion

When a TN user deletes their account:
1. TN calls Freegle's `/api/session` with `action=Forget`
2. Uses partner authentication key
3. Sets `users.forgotten` timestamp
4. Clears `tnuserid` to NULL

## Maintenance Scripts

The following one-off maintenance scripts existed in the legacy V1 PHP implementation (retired):

| Script | Purpose |
|--------|---------|
| `fix_tn_ids.php` | Map/fix TN user IDs |
| `fix_tn_members.php` | Verify TN user memberships |
| `fix_tn_emails.php` | Correct TN email addresses |
| `fix_tn_multiples.php` | Detect duplicate TN users |
| `fix_tn_renames.php` | Handle TN username changes |
| `fix_tnatts.php` | Fix TN attachment handling |
| `fix_tn_public_locations.php` | Fix location data for TN users |

## Areas for Possible Improvement

### 1. Real-Time Synchronization

**Current State**: Profile and rating sync runs daily via cron.

**Improvement**: Implement webhook-based real-time sync to ensure TN profile changes appear immediately on Freegle.

### 2. Bidirectional Chat Sync Latency

**Current State**: Chat messages to LoveJunk users are synced via API calls, but there may be delays.

**Improvement**: Implement WebSocket or push notification for faster chat delivery.

### 3. User Merge Warning Enhancement

**Current State**: ModTools shows a warning when merging accounts with TN emails.

**Improvement**: Add more detailed guidance about which account should be the primary and what data might be affected.

### 4. Photo Scraping Reliability

**Current State**: Photos are scraped with 120-second timeout; failures result in missing images.

**Improvement**: Implement retry queue for failed photo downloads and provide fallback to TN-hosted images.

### 5. Source Tracking Granularity

**Current State**: Source header shows basic platform (TN-Web, TN-Facebook, etc.).

**Improvement**: Track TN app version and client type for better debugging and analytics.

### 6. TN User Experience Parity

**Current State**: TN users cannot edit chat notifications or auto-repost settings in Freegle.

**Improvement**: Consider API integration to allow these settings to be managed through TN's interface and synced back.

### 7. Duplicate User Detection

**Current State**: Email canonicalization helps, but duplicate TN users can still occur.

**Improvement**: More aggressive de-duplication when TN user ID is known, automatic merging when same TN user creates multiple Freegle accounts.

### 8. Error Handling for TN API Failures

**Current State**: API failures logged but may cause incomplete sync.

**Improvement**: Implement alerting for sync failures and automatic retry with backoff.

### 9. Go API Server TN Support

**Current State**: Go API (v2) has minimal TN-specific code, relies on PHP backend.

**Improvement**: Add TN user detection and handling to Go API for future migration.

### 10. TN-Specific Analytics

**Current State**: Basic source tracking in messages.

**Improvement**: Dashboard showing TN vs native user engagement, message volume, response rates.

## Data Flow Summary

```
TrashNothing User Posts Message
            ↓
Email sent to Freegle with TN headers
            ↓
MailRouter processes email
  - Extract TN headers
  - Find/create user with tnuserid
  - Scrape TN photos
  - Store message with sourceheader="TN-*"
            ↓
Message appears on Freegle (website/app)
            ↓
Daily Sync (tn_sync.php)
  - Fetch ratings from TN API
  - Fetch profile changes
  - Handle account removals
            ↓
If OFFER: Syndicate to LoveJunk
  - Create draft on LoveJunk API
  - Track ljofferid for chat sync
            ↓
Responses/Chats synced bidirectionally
```

## Key File References

TN user identification, message parsing, LoveJunk integration, daily sync and the memberships API originally lived in the legacy V1 PHP implementation (retired). Daily sync now runs via `iznik-batch`'s `TrashNothing\TNSyncCommand`; TN-aware routes (message-by-TN-post-id, partner-key auth for group join/leave) live in `iznik-server-go`.

| Component | File Path |
|-----------|-----------|
| ModTools member display | `iznik-nuxt3-modtools/modtools/components/ModMember.vue` |
| Message history | `iznik-nuxt3-modtools/components/MessageHistory.vue` |
| Chat message parsing | `iznik-nuxt3-modtools/components/ChatMessageText.vue` |

## Configuration

### Environment Variables / Constants

```php
define('TNKEY', '...');        // TN API Key (in defines.php)
define('TN_ADDR', '...');      // TN email address
define('LOVE_JUNK_API', '...'); // LoveJunk API endpoint
define('LOVE_JUNK_SECRET', '...'); // LoveJunk API secret
```

### Group Settings

Per-group TN integration can be controlled via:
- `groups.ontn` - Whether group is syndicated to TrashNothing
- `groups.onlovejunk` - Whether offers are sent to LoveJunk
