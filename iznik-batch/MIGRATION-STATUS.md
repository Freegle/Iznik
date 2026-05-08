# Migration Status

This document tracks progress migrating cron scripts from `iznik-server/scripts/cron/` to Laravel services in this application.

**Before migrating any email:** Read [EMAIL-MIGRATION-GUIDE.md](./EMAIL-MIGRATION-GUIDE.md) for lessons learned from previous migrations.

## Status Legend

- **Done** - Fully migrated and tested
- **In Progress** - Partially implemented
- **Not Started** - Not yet begun
- **Skip** - Not needed in Laravel (external tool, deprecated, etc.)

## Artisan Command Naming

All email-related commands use the `mail:` prefix. Other batch commands use descriptive prefixes.

### Email Commands

| Command | Description |
|---------|-------------|
| `mail:chat:user2user` | Send user-to-user chat notifications |
| `mail:chat:user2mod` | Send user-to-moderator chat notifications |
| `mail:chat:mod2mod` | Send moderator-to-moderator chat notifications |
| `mail:digest` | Send message digests (per-group, legacy) |
| `mail:digest:unified` | Send unified Freegle digests (user-centric, replaces mail:digest) |
| `mail:donations:thank` | Send donation thank-you emails |
| `mail:donations:ask` | Send donation request emails |
| `mail:bounced` | Process bounced emails |
| `mail:welcome:send` | Send pending welcome emails |
| `mail:welcome:recover` | Recover failed welcome emails |
| `mail:spool:process` | Process email spool queue |
| `mail:test` | **Test any email type** without database side effects |

### Other Commands

| Command | Description |
|---------|-------------|
| `messages:process-expired` | Process expired messages |
| `messages:auto-approve` | Auto-approve pending messages after 48h |
| `messages:auto-repost` | Auto-repost messages based on group settings |
| `messages:chase-up` | Chase up messages with replies but no outcome |
| `purge:all` | Run all purge operations |
| `purge:messages` | Purge old messages |
| `purge:chats` | Purge old chat rooms |
| `users:update-kudos` | Update user kudos scores |
| `users:retention-stats` | Generate retention statistics |
| `data:update-cpi` | Update CPI data |
| `data:git-summary` | Generate git summary |
| `purge:sessions` | Purge old sessions and login links |
| `cleanup:search-duplicates` | Remove duplicate consecutive searches |
| `cleanup:chat-duplicates` | Remove duplicate consecutive chat messages |
| `data:classify-app-release` | Classify app release versions |
| `groups:update-counts` | Update group member/moderator counts |
| `chats:update-counts` | Update chat message counts, reopen closed User2Mod |
| `chats:process-incoming` | Process pending chat messages (processingrequired=1): spam check, roster update |
| `memberships:process` | Process pending membership history: per-group welcome emails, flag reviewed members |
| `users:update-lastaccess` | Fallback update of user last access timestamps |
| `users:update-support-roles` | Grant/remove support tools access |
| `donations:update-ads-target` | Update ads-off donation target |
| `purge:logs` | Purge old log entries from various tables |
| `purge:sessions` | Purge old sessions and login links |
| `cleanup:search-duplicates` | Remove duplicate consecutive searches |
| `cleanup:chat-duplicates` | Remove duplicate consecutive chat messages |
| `emails:validate` | Validate emails and delete invalid ones |
| `locations:fix-skewed` | Fix swapped lat/lng coordinates |
| `users:update-ratings` | Update rating visibility based on chat interactions |
| `memberships:process` | Process membership history entries (send welcome emails, flag mod comments) |
| `users:process-exports` | Process GDPR data export requests + purge old export data |

## Testing Emails (mail:test)

The `mail:test` command allows sending any email type without making persistent database changes.

```bash
# List available email types
docker exec freegle-batch php artisan mail:test --list

# Preview email content without sending (dry-run)
docker exec freegle-batch php artisan mail:test welcome --user=123 --dry-run

# Send test email to a specific user
docker exec freegle-batch php artisan mail:test welcome --user=123 --to=test@example.com

# Use real user data but deliver to test address (--send-to)
docker exec freegle-batch php artisan mail:test chat:user2user --to=realuser@example.com --send-to=mytest@example.com

# Test chat notification with AMP enabled
docker exec freegle-batch php artisan mail:test chat:user2user --to=user@example.com --amp=on

# Test all chat message types
docker exec freegle-batch php artisan mail:test chat:user2user --to=user@example.com --all-types

# Override From header for AMP testing (must be registered with Google)
docker exec freegle-batch php artisan mail:test chat:user2user --to=user@example.com --amp=on --from=noreply@ilovefreegle.org

# Test digest email
docker exec freegle-batch php artisan mail:test digest --group=789 --to=test@example.com
```

The command uses database transactions that are rolled back after the email is generated, so no permanent changes are made.

## AMP Email Support

AMP for Email is supported for chat notifications. This enables interactive features like inline replies directly from Gmail.

### Current Status

| Email Type | AMP Support | Status |
|------------|-------------|--------|
| Chat notifications (user2user) | Yes | **Live** |
| Chat notifications (user2mod) | Yes | **Live** |
| Chat notifications (mod2mod) | Yes | Code written |
| Digest emails | No | Code written |
| Welcome emails | No | **Live** |
| Donation thank-you emails | No | Code written |
| Donation request emails | No | Code written |

### AMP Features Implemented

- **Inline reply**: Users can reply to chat messages without leaving Gmail.
- **Message history**: Shows "Earlier in this conversation" with previous messages.
- **Profile images**: Displays sender avatars in conversation history.
- **HMAC token authentication**: Secure, reusable tokens for AMP API calls.

### AMP Token System

AMP emails use HMAC-SHA256 tokens for authentication:
- Single token used for both read and write operations.
- Token is computed from user ID, chat ID, and a shared secret.
- Tokens don't expire (no database storage needed).
- Go API validates tokens using the same HMAC algorithm.

### Testing AMP Emails

1. Send test email with `--amp=on`:
   ```bash
   docker exec freegle-batch php artisan mail:test chat:user2user --to=user@gmail.com --amp=on
   ```

2. AMP HTML is saved to `/tmp/amp-email-*.html` for validation.

3. Validate AMP HTML at: https://amp.gmail.dev/playground/

4. For Google AMP registration, use `--from=noreply@ilovefreegle.org` (registered sender).

### AMP Configuration

Set in `config/freegle.php`:
- `freegle.amp.enabled` - Enable/disable AMP emails globally.
- `freegle.amp.secret` - Shared secret for HMAC token generation.
- `freegle.sites.apiv2` - Go API endpoint for AMP requests.

## Scheduled and Running

These commands are active in `routes/console.php` and running in production:

| Original Script | Artisan Command | Schedule | Notes |
|-----------------|-----------------|----------|-------|
| `welcome.php` | `mail:welcome:send` | Every minute | Welcome emails (.env: `Welcome`) |
| `chat_notifyemail_user2user.php` | `mail:chat:user2user` | Every minute | User-to-user notifications (.env: `ChatNotification`) |
| `chat_notifyemail_mod2mod.php` | `mail:chat:mod2mod` | Every minute | Mod-to-mod notifications (.env: `ChatNotificationMod2Mod` **needs adding**) |
| `chat_notifyemail_user2mod.php` | `mail:chat:user2mod` | Every minute | User-to-mod notifications (.env: `ChatNotificationUser2Mod`) |
| - | `data:update-cpi` | Monthly | CPI inflation data from ONS |
| `spool.php` | `mail:spool:process --cleanup` | Daily 04:00 | Clean up old sent emails |
| `git_summary_ai.php` | `data:git-summary` | Weekly Wed 18:00 | Git summary for Discourse |

## Code Written (Scheduler Disabled)

These have code implemented but the scheduler entry is commented out in `routes/console.php`:

| Original Script | Artisan Command | Email Type in .env | Notes |
|-----------------|-----------------|-------------------|-------|
| `digest.php` | `mail:digest` | - | Message digests (per-group, legacy) |
| `digest.php` | `mail:digest:unified` | UnifiedDigest | Unified Freegle digests (user-centric) |
| `donations_email.php` | `mail:donations:ask` | - | Donation reminders |
| `donations_thank.php` | `mail:donations:thank` | - | Donation thank-you emails |
| `bounce.php` + `bounce_users.php` | `mail:bounced` | - | Bounced email handling + user suspension (PR #390) |
| `messages_expired.php` | `messages:process-expired` | - | Deadline expiry handling |
| `autoapprove.php` | `messages:auto-approve` | - | Auto-approve pending messages after 48h |
| `autorepost.php` | `messages:auto-repost` | - | Auto-repost messages based on group settings |
| `chaseup.php` | `messages:chase-up` | - | Chase up messages with replies but no outcome |
| `purge_messages.php` | `purge:messages` | - | Message purging |
| `purge_chats.php` | `purge:chats` | - | Chat purging |
| `users_kudos.php` | `users:update-kudos` | - | User kudos |
| `users_retention.php` | `users:retention-stats` | - | User retention stats |
| `membercounts.php` | `groups:update-counts` | - | Group member/mod counts |
| `chat_latestmessage.php` | `chats:update-counts` | - | Chat message counts + reopen closed User2Mod |
| `lastaccess.php` | `users:update-lastaccess` | - | Fallback lastaccess update |
| `supporttools.php` | `users:update-support-roles` | - | Support tools role management |
| `donations_ads_target.php` | `donations:update-ads-target` | - | Ads-off donation target |
| `purge_logs.php` | `purge:logs` | - | Log purging (16 purge operations) |
| `purge_sessions.php` | `purge:sessions` | - | Session + login link purging |
| `searchdups.php` | `cleanup:search-duplicates` | - | Consecutive search dedup |
| `chatdups.php` | `cleanup:chat-duplicates` | - | Consecutive chat message dedup |
| `email_validate.php` | `emails:validate` | - | Delete invalid emails |
| `locations_skewwhiff.php` | `locations:fix-skewed` | - | Fix swapped lat/lng |
| `user_ratings.php` | `users:update-ratings` | - | Rating visibility |
| `check_spammers.php` | `users:remove-spammers` | - | Remove spam members + content cleanup (PR #395) |
| `chat_spam.php` | `chats:process-spam` | - | Warn innocent users + auto-mark spam (PR #397) |
| `chat_expected.php` | `chats:update-expected` | - | Chat reply expectation tracking (PR #396) |
| `users_modmails.php` | `users:update-modmails` | - | Sync mod actions into users_modmails (PR #392) |
| `mod_notifs.php` | `mail:mod-notifs` | - | Moderator notification emails (PR #391) |
| `donations_giftaid.php` | `donations:update-giftaid` | - | Gift Aid identification + chase-ups (PR #394) |
| `message_spatial.php` | `messages:update-spatial-index` | - | Spatial index updates (PR #398) |
| `message_unindexed.php` | `messages:update-index` | - | Index missing messages (PR #393) |
| `message_deindex.php` | `messages:deindex` | - | De-index old messages (PR #393) |
| `chat_process.php` | `chats:process-incoming` | - | Process pending incoming chat messages: spam check, roster update, reopen closed chats |
| `memberships_processing.php` | `memberships:process` | - | Process pending membership history: per-group welcome emails, flag reviewed members |
| `exports.php` | `users:process-exports` | - | GDPR data export processing + purge old export data |
| `engage_update.php` | `users:update-engagement` | - | Update user engagement classifications (New/Occasional/Frequent/Obsessed/Inactive/Dormant) |
| `users_remap.php` | `users:remap-locations` | - | Update cached location names in user settings |
| `messages_remap.php` | `messages:remap-subjects` | - | Update message subjects when location names change |
| `group_stats.php` | `groups:update-stats` | - | Fix repost settings, polyindex, activity/funding, mod counts |
| `domains_common.php` | `domains:update-common` | - | Common email domains |
| `get_app_release_versions.php` | `data:fetch-app-versions` | - | Fetch app versions from iOS App Store and Google Play |
| `jobs_illustrations.php` | `jobs:generate-illustrations` | - | AI illustrations for canonical job categories |
| `messages_illustrations.php` | `messages:generate-illustrations` | - | AI illustrations for messages without photos |
| `whatjobs_spam.php` | `cleanup:whatjobs-spam` | - | Delete spammy WhatJobs postings |

## Code Written - Running via CircleCI (Not Scheduler)

These have artisan commands and are invoked by CircleCI rather than the batch scheduler:

| Original Script | Artisan Command | Status | Notes |
|-----------------|-----------------|--------|-------|
| - | `data:classify-app-release` | **Live** | Called by CircleCI for app release promotion decisions |

Note: `data:classify-app-release` is NOT a migration of `get_app_release_versions.php`. They serve different purposes:
- `get_app_release_versions.php` - Fetches app version info from app stores and stores in database (dashboard display)
- `data:classify-app-release` - Classifies commits to decide if release should be promoted immediately or batched

---

## Code Written (No Scheduler Entry - Not Yet Migrated)

These original scripts need to be migrated to Laravel artisan commands:

| Original Script | Artisan Command | Notes |
|-----------------|-----------------|-------|
| `get_app_release_versions.php` | - | Needs migration - fetches app versions for dashboard display |

---

## High Frequency Scripts (Every 1-5 min) - Not Started

| Script | Frequency | Priority | Description |
|--------|-----------|----------|-------------|
| `background.php` | Every 1 min | High | Background job processor — **Covered: `queue:background-tasks` (Go API background tasks)** |
| ~~`chat_process.php`~~ | ~~Every 1 min~~ | ~~High~~ | ~~Chat message processing~~ — **Migrated: `chats:process-incoming`** |
| `admins.php` | Every 1 min | Medium | Admin notifications — **Covered: `mail:admin:copy` + `mail:admin:send` + `mail:admin:chase`** |
| `tryst.php` | Every 1 min | Medium | Meeting coordination |
| ~~`memberships_processing.php`~~ | ~~Every 1 min~~ | ~~Medium~~ | ~~Membership processing~~ — **Migrated: `memberships:process`** |
| ~~`donations_ads_target.php`~~ | ~~Every 1 min~~ | ~~Medium~~ | ~~Donation ad targeting~~ — **Migrated: `donations:update-ads-target`** |
| `user_exhort.php` | Every 1 min | Medium | User encouragement (parameterized one-off tool, not a regular cron) |
| ~~`lovejunk.php`~~ | ~~Every 1 min~~ | ~~Medium~~ | ~~LoveJunk integration~~ — **Migrated: `integrations:sync-lovejunk`** |
| ~~`exports.php`~~ | ~~Every 1 min~~ | ~~Low~~ | ~~Data exports~~ — **Migrated: `users:process-exports`** |
| ~~`notification_chaseup.php`~~ | ~~Every 5 min~~ | ~~Medium~~ | ~~Notification reminders~~ — **Migrated: `mail:notifications:chaseup`** |
| `previews.php` | Every 5 min | Medium | Link preview generation |
| `check_cgas.php` | Every 5 min | Low | CGA checking |
| ~~`message_spatial.php`~~ | ~~Every 5 min~~ | ~~Medium~~ | ~~Spatial index updates~~ — **Migrated: `messages:update-spatial-index` — PR #398** |
| ~~`messages_illustrations.php`~~ | ~~Every 1 min~~ | ~~Medium~~ | ~~Message illustrations~~ — **Migrated: `messages:generate-illustrations`** |
| ~~`messages_remap.php`~~ | ~~Every 5 min~~ | ~~Low~~ | ~~Message remapping~~ — **Migrated: `messages:remap-subjects`** |
| ~~`chat_expected.php`~~ | ~~Every 5 min~~ | ~~Medium~~ | ~~Expected chat responses~~ — **Migrated: `chats:update-expected` — PR #396** |
| ~~`chat_spam.php`~~ | ~~Every 5 min~~ | ~~Medium~~ | ~~Chat spam detection~~ — **Migrated: `chats:process-spam` — PR #397** |
| ~~`check_spammers.php`~~ | ~~Every 5 min~~ | ~~Medium~~ | ~~Spam detection~~ — **Migrated: `users:remove-spammers` — PR #395** |
| ~~`users_modmails.php`~~ | ~~Every 5 min~~ | ~~Medium~~ | ~~Mod mail processing~~ — **Migrated: `users:update-modmails` — PR #392** |
| `visualise.php` | Every 5 min | Low | Data visualisation |
| `microvolunteering.php` | Every 5 min | Low | Micro-volunteering — **Migrated: `microvolunteering:score`** |
| `newsfeed_link_previews.php` | Every 1 min | Low | Newsfeed link previews |
| `tn_sync.php` | Every 1 min | Medium | Trash Nothing sync |

## Medium Frequency Scripts (Every 10-60 min) - Not Started

| Script | Frequency | Priority | Description |
|--------|-----------|----------|-------------|
| ~~`donations_giftaid.php`~~ | ~~Every 10 min~~ | ~~Medium~~ | ~~Gift Aid processing~~ — **Migrated: `donations:update-giftaid` — PR #394** |
| `alerts.php` | Every 10 min | Medium | System alerts |
| ~~`user_ratings.php`~~ | ~~Every 10 min~~ | ~~Low~~ | ~~User ratings~~ — **Migrated: `users:update-ratings`** |
| `eximlogs.php` | Every 10 min | Low | Exim mail logs — **Skip: external (mail server logs)** |
| ~~`whatjobs_spam.php`~~ | ~~Every 10 min~~ | ~~Low~~ | ~~WhatJobs spam~~ — **Migrated: `cleanup:whatjobs-spam`** |
| ~~`jobs_illustrations.php`~~ | ~~Every 30 min~~ | ~~Low~~ | ~~Job illustrations~~ — **Migrated: `jobs:generate-illustrations`** |
| ~~`message_unindexed.php`~~ | ~~Every 30 min~~ | ~~Low~~ | ~~Unindexed messages~~ — **Migrated: `messages:update-index` — PR #393** |
| ~~`chat_latestmessage.php`~~ | ~~Every 60 min~~ | ~~Low~~ | ~~Chat latest message~~ — **Migrated: `chats:update-counts`** |
| `pledge.php` | Every 60 min | Low | Pledges |
| ~~`lastacces.php`~~ | ~~Every 59 min~~ | ~~Low~~ | ~~Last access tracking~~ — **Migrated: `users:update-lastaccess`** |
| ~~`mod_notifs.php`~~ | ~~Every 60 min~~ | ~~Medium~~ | ~~Moderator notifications~~ — **Migrated: `mail:mod-notifs` — PR #391** |
| ~~`supporttools.php`~~ | ~~Every 60 min~~ | ~~Low~~ | ~~Support tools~~ — **Migrated: `users:update-support-roles`** |
| ~~`membercounts.php`~~ | ~~Every 60 min~~ | ~~Low~~ | ~~Member counts~~ — **Migrated: `groups:update-counts`** |
| ~~`autorepost.php`~~ | ~~Every 60 min~~ | ~~Medium~~ | ~~Auto-repost messages~~ — **Migrated: `messages:auto-repost`** |
| ~~`chaseup.php`~~ | ~~Every 60 min~~ | ~~Medium~~ | ~~Message chase-up~~ — **Migrated: `messages:chase-up`** |
| ~~`searchdups.php`~~ | ~~Every 60 min~~ | ~~Low~~ | ~~Search duplicates~~ — **Migrated: `cleanup:search-duplicates`** |
| ~~`autoapprove.php`~~ | ~~Every 60 min~~ | ~~Medium~~ | ~~Auto-approve messages~~ — **Migrated: `messages:auto-approve`** |
| ~~`bounce_users.php`~~ | ~~Every 60 min~~ | ~~Medium~~ | ~~User bounce processing~~ — **Migrated: `mail:bounced` — PR #390** |
| ~~`chatdups.php`~~ | ~~Every 120 min~~ | ~~Low~~ | ~~Chat duplicates~~ — **Migrated: `cleanup:chat-duplicates`** |

## Daily Scripts - Not Started

| Script | Time | Priority | Description |
|--------|------|----------|-------------|
| `chat_chaseup_expected.php` | 06:00 | Medium | Chat expected response chase-up |
| `birthday.php` | 12:00 | Low | Birthday notifications |
| `relevant.php` | 14:30 | Medium | Relevant message matching |
| `chat_chaseupmods.php` | 15:30 | Medium | Moderator chat chase-up |
| `newsfeed_digest.php` | 15:30 | Low | Newsfeed digest |
| `newsfeed_modnotif.php` | 13:30 | Low | Newsfeed mod notifications |
| `noticeboards.php` | 15:30 | Low | Noticeboards |
| `group_welcomereview.php` | 01:00, 15:00 | Low | Group welcome review |
| ~~`message_deindex.php`~~ | ~~01:00~~ | ~~Low~~ | ~~Message de-indexing~~ — **Migrated: `messages:deindex` — PR #393** |
| ~~`group_stats.php`~~ | ~~02:00~~ | ~~Low~~ | ~~Group statistics~~ — **Migrated: `groups:update-stats`** |
| `doogal` | 03:00 | Low | Doogal data import — **Skip: external data import** |
| ~~`engage_update.php`~~ | ~~03:00~~ | ~~Low~~ | ~~Engagement update~~ — **Migrated: `users:update-engagement`** |
| ~~`purge_sessions.php`~~ | ~~03:00~~ | ~~Low~~ | ~~Session purging~~ — **Migrated: `purge:sessions`** |
| ~~`purge_logs.php`~~ | ~~04:00~~ | ~~Low~~ | ~~Log purging~~ — **Migrated: `purge:logs`** |
| ~~`email_validate.php`~~ | ~~04:00~~ | ~~Low~~ | ~~Email validation~~ — **Migrated: `emails:validate`** |
| `messages_popular.php` | 05:00 | Low | Popular messages |
| ~~`users_remap.php`~~ | ~~05:00~~ | ~~Low~~ | ~~User remapping~~ — **Migrated: `users:remap-locations`** |
| ~~`locations_skewwhiff.php`~~ | ~~05:00~~ | ~~Low~~ | ~~Location fixes~~ — **Migrated: `locations:fix-skewed`** |
| `nearby.php` | 14:05 | Medium | Nearby items |
| `chat_review.php` | 11:00 | Medium | Chat review queue |
| `engage.php` | 16:00 | Medium | User engagement emails |
| `user_askdonation.php` | 17:00 | Medium | Donation requests |
| `facebook_chaseup.php` | 18:00 | Low | Facebook chase-up |
| `whatjobs.php` | Hourly 08:00-22:00 | Low | WhatJobs |
| `microactions_score.php` | 23:00 | Low | Microactions scoring |
| `restartproject.php` | 23:00 | Low | Restart project |
| `repaircafewales.php` | 23:00 | Low | Repair Cafe Wales |
| `archive_attachments.php` | 22:30 | Low | Attachment archiving |

## Weekly Scripts - Not Started

| Script | Schedule | Priority | Description |
|--------|----------|----------|-------------|
| `events.php` | Thu 23:00 | Low | Community events email |
| `volunteering.php` | Mon 23:00 | Low | Volunteering opportunities email |
| `stories.php` | Sat 11:00 | Low | Success story requests |
| `groups_closed.php` | Sun 08:00 | Low | Closed groups check |
| `stories_tocentral.php` | Fri 14:00 | Low | Stories to central |
| ~~`domains_common.php`~~ | ~~Fri 07:00~~ | ~~Low~~ | ~~Common domains~~ — **Migrated: `domains:update-common`** |
| `mod_active.php` | Mon 15:00 | Low | Active moderators |

## Monthly Scripts - Not Started

| Script | Schedule | Priority | Description |
|--------|----------|----------|-------------|
| `stories_newsletter.php` | 12th 23:00 | Low | Stories newsletter |
| `lovejunk_tn_invoice.php` | 1st 15:00 | Low | LoveJunk/TN invoice |

## Scripts to Skip

| Script | Reason |
|--------|--------|
| `sa_train` | SpamAssassin training - external |
| `cron_checker_iznik.php` | Monitoring - external tool |
| `discourse_checkusers.php` | Discourse integration - separate system |
| `discourse_not_signed_up.php` | Discourse integration - separate system |
| `locations_pgsql` | PostgreSQL locations - external |
| `doogal` | External data import script |
| `eximlogs.php` | Mail server logs - external |
| `facebook_share.php` | Disabled in crontab |
| `tweet_*.php` | Twitter - commented out |
| `sms.php` | Retired |
| `badnumber.php` | Retired |
| `spam_toddlers.php` | Commented out |

## Known Issues

### Schema Discrepancies

When migrating, we've found cases where the database schema (from migration generator) differs from iznik-server constants. Always verify against iznik-server source code.

Example: `messages_outcomes.outcome` enum - the migration generator may not capture all values defined in `Message::OUTCOME_*` constants. The iznik-server defines:
- `OUTCOME_TAKEN`
- `OUTCOME_RECEIVED`
- `OUTCOME_WITHDRAWN`
- `OUTCOME_REPOST`
- `OUTCOME_EXPIRED`
- `OUTCOME_PARTIAL`

## Adding New Migrations

When starting work on a new script:

1. Update this file to mark it "In Progress"
2. Read the original PHP script in `iznik-server/scripts/cron/`
3. Check related PHPUnit tests in `iznik-server/test/ut/php/` (see sections above)
4. Verify any constants/enums against iznik-server source
5. Write tests first based on expected behavior
6. Implement the service
7. Update status to "Done" when tests pass
