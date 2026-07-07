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

Located in `/iznik-server/scripts/fix/`:

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

| Component | File Path |
|-----------|-----------|
| TN user identification | `iznik-server/include/user/User.php` |
| Message parsing | `iznik-server/include/message/Message.php` |
| LoveJunk integration | `iznik-server/include/integrations/LoveJunk.php` |
| Daily sync | `iznik-server/scripts/cron/tn_sync.php` |
| Memberships API | `iznik-server/http/api/memberships.php` |
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
