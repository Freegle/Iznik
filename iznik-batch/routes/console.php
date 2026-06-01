<?php

use Illuminate\Support\Facades\Schedule;

/**
 * Define the application's command schedule.
 *
 * IMPORTANT: Most commands are disabled for now. Only enable when ready to go live.
 * Commands are gradually being enabled as we migrate from iznik-server crontab.
 */

// Helper to build a per-command log path for output capture.
$cronLogDir = storage_path('logs/cron');
if (!is_dir($cronLogDir)) {
    mkdir($cronLogDir, 0755, true);
}

if (!function_exists('cronLog')) {
    function cronLog(string $command): string
    {
        $safe = str_replace([':', ' ', '/', '--'], ['_', '_', '_', '_'], $command);

        return storage_path('logs/cron/'.$safe.'.log');
    }
}

// =============================================================================
// ACTIVE SCHEDULED COMMANDS
// =============================================================================

// Deployment watch - disabled (not used in Docker environment).
// Was: detect code updates via version.txt and auto-refresh application.
// Schedule::command('deploy:watch')
//     ->everyMinute()
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('deploy:watch'))
//     ->runInBackground();

// Welcome mail processing - check for pending welcome mails every minute.
// Uses PreventsOverlapping trait for flock-based locking (released on process death).
// Uses runInBackground() so it doesn't block other scheduled commands.
// Uses --spool to write to file for resilient async processing.
Schedule::command('mail:welcome:send --limit=100 --spool')
    ->everyMinute()
    ->sendOutputTo(cronLog('mail:welcome:send'))
    ->runInBackground();

// Chat notifications - run continuously with internal looping.
// Uses PreventsOverlapping trait for flock-based locking (released on process death).
// User2User notifications.
Schedule::command('mail:chat:user2user --max-iterations=60 --spool')
    ->everyMinute()
    ->sendOutputTo(cronLog('mail:chat:user2user'))
    ->runInBackground();

// Mod2Mod notifications.
Schedule::command('mail:chat:mod2mod --max-iterations=60 --spool')
    ->everyMinute()
    ->sendOutputTo(cronLog('mail:chat:mod2mod'))
    ->runInBackground();

// User2Mod notifications.
Schedule::command('mail:chat:user2mod --max-iterations=60 --spool')
    ->everyMinute()
    ->sendOutputTo(cronLog('mail:chat:user2mod'))
    ->runInBackground();


// Fetch UK CPI inflation data from ONS - runs monthly.
// Used to inflation-adjust the "benefit of reuse" value from the 2011 WRAP report.
// Sends alert email to GeekAlerts if fetch fails.
Schedule::command('data:update-cpi')
    ->monthly()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('data:update-cpi'))
    ->runInBackground();

// Content check — run all content checks on unprocessed pending messages.
// Promotes clean messages from non-moderated users to Approved; keeps others
// in Pending with failure reasons stored, then notifies group mods.
Schedule::command('messages:contentcheck')
    ->everyMinute()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('messages:contentcheck'))
    ->runInBackground();

// Auto-approve pending messages after 48 hours.
// V1: cron/autoapprove.php
Schedule::command('messages:auto-approve')
    ->hourly()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('messages:auto-approve'))
    ->runInBackground();

// Auto-repost messages based on group repost settings.
// V1: cron/autorepost.php
Schedule::command('messages:auto-repost')
    ->hourly()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('messages:auto-repost'))
    ->runInBackground();

// Chase up messages with replies but no outcome.
// V1: cron/chaseup.php
Schedule::command('messages:chase-up')
    ->hourly()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('messages:chase-up'))
    ->runInBackground();

// Deduplicate searches.
// V1: cron/searchdups.php
Schedule::command('cleanup:search-duplicates')
    ->hourly()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('cleanup:search-duplicates'))
    ->runInBackground();

// Deduplicate chat messages.
// V1: cron/chatdups.php
Schedule::command('cleanup:chat-duplicates')
    ->everyTwoHours()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('cleanup:chat-duplicates'))
    ->runInBackground();

// Archive old duplicate profile images, keeping latest per user.
// V1: cron/archive_attachments.php — disabled pending sign-off
Schedule::command('cleanup:archive-profile-images')
    ->dailyAt('22:30')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('cleanup:archive-profile-images'))
    ->runInBackground();

// Clean up old sessions.
// V1: cron/purge_sessions.php
Schedule::command('cleanup:sessions')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('cleanup:sessions'))
    ->runInBackground();

// Remove spam members from groups and clean up their content.
// V1: cron/check_spammers.php (every 5 minutes)
Schedule::command('users:remove-spammers')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('users:remove-spammers'))
    ->runInBackground();

// Process bounced emails — mark as invalid.
// V1: cron/bounce.php + bounce_users.php
Schedule::command('mail:bounced')
    ->hourly()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('mail:bounced'))
    ->runInBackground();

// Moderator work notifications — tells mods about pending messages, events, etc.
// Only runs 08:00–21:00; deduplicates against last sent summary.
// V1: cron/mod_notifs.php (hourly)
Schedule::command('mail:mod-notifs')
    ->hourly()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('mail:mod-notifs'))
    ->runInBackground();

// Email health monitor — alerts if incoming or outgoing email flow drops below
// configurable thresholds during daytime hours.
Schedule::command('monitor:email-health')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('monitor:email-health'))
    ->runInBackground();

// Notification chaseup - send emails for unseen, unmailed site notifications.
// V1: cron/notification_chaseup.php (every 5 minutes)
Schedule::command('mail:notifications:chaseup')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('mail:notifications:chaseup'))
    ->runInBackground();

// Daily purge of spam chat messages, empty rooms, orphaned chat images.
// V1: cron/purge_chats.php
Schedule::command('purge:chats')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('purge:chats'))
    ->runInBackground();

// Daily log/bounce/likes purge.
// V1: cron/purge_logs.php
Schedule::command('purge:logs')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('purge:logs'))
    ->runInBackground();

// Daily syntactic email validation (last 30 days only).
// V1: cron/email_validate.php
Schedule::command('emails:validate')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('emails:validate'))
    ->runInBackground();

// Daily kudos recalculation for users active in last 2 days.
// V1: cron/users_kudos.php
Schedule::command('users:update-kudos')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('users:update-kudos'))
    ->runInBackground();

// Hourly group member/mod count refresh.
// V1: cron/membercounts.php
Schedule::command('groups:update-counts')
    ->hourly()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('groups:update-counts'))
    ->runInBackground();

// Hourly chat-room message count refresh + reopen User2Mod chats with mod
// replies that the user closed before seeing.
// V1: cron/chat_latestmessage.php
Schedule::command('chats:update-counts')
    ->hourly()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('chats:update-counts'))
    ->runInBackground();

// Sync recent mod actions into users_modmails and prune old entries.
// V1: cron/users_modmails.php (every 5 minutes)
Schedule::command('users:update-modmails')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('users:update-modmails'))
    ->runInBackground();

// Hourly fallback users.lastaccess update from chat / membership activity.
// V1: cron/lastaccess.php
Schedule::command('users:update-lastaccess')
    ->hourly()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('users:update-lastaccess'))
    ->runInBackground();

// Update chat reply-expectation tracking and per-user reply-time metrics.
// V1: cron/chat_expected.php (every 5 minutes)
Schedule::command('chats:update-expected')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('chats:update-expected'))
    ->runInBackground();

// Send calendar invites and chat reminders for arranged handover trysts.
// V1: cron/tryst.php (every 1 minute)
Schedule::command('chats:send-tryst-reminders')
    ->everyMinute()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('chats:send-tryst-reminders'))
    ->runInBackground();

// Chase up mods about User2Mod chats with no mod reply older than 6.55 days.
// V1: cron/chat_chaseupmods.php (daily 15:30)
Schedule::command('chats:chaseup-mods')
    ->dailyAt('15:30')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('chats:chaseup-mods'))
    ->runInBackground();

// Email users who are expected to reply in a User2User chat but have not.
// V1: cron/chat_chaseup_expected.php (daily 06:00)
// Scheduler disabled pending go-live, consistent with the other migrated mail
// jobs (mail:alerts:send, mail:digest, etc.). Enable when the team is ready.
// Schedule::command('chats:chaseup-expected')
//     ->dailyAt('06:00')
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('chats:chaseup-expected'))
//     ->runInBackground();

// Warn innocent users who chatted with spammers; auto-mark spam chat messages.
// V1: cron/chat_spam.php (every 5 minutes)
Schedule::command('chats:process-spam')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('chats:process-spam'))
    ->runInBackground();

// Sync data from TrashNothing.
// This command can be called more frequently if the "kick" API is called by TN,
// e.g. to reduce latency by requesting an immediate sync after sending a chat message.
Schedule::command('tn:sync')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// =============================================================================
// DISABLED COMMANDS (to be enabled when ready)
// =============================================================================

// Digest and immediate mail are still handled by V1 (cron/digest.php on
// bulk3-internal). Per MIGRATION-STATUS.md, digest emails are "Code written"
// not "Live". Keep these scheduled commands disabled until the V1 cron is
// retired and we cut over here, to avoid duplicate sends.
//
// Schedule::command('mail:digest 1')
//     ->hourly()
//     ->withoutOverlapping()
//     ->runInBackground();
//
// Schedule::command('mail:digest 2')
//     ->everyTwoHours()
//     ->withoutOverlapping()
//     ->runInBackground();
//
// Schedule::command('mail:digest 4')
//     ->everyFourHours()
//     ->withoutOverlapping()
//     ->runInBackground();
//
// Schedule::command('mail:digest 8')
//     ->cron('0 0,8,16 * * *')
//     ->withoutOverlapping()
//     ->runInBackground();
//
// Daily digests — V1 ran two parallel workers sharded by MOD(groupid, 2)
// (cron/digest.php -i 24 -m 2 -v 0 / -v 1). Preserved as two entries so a
// re-enable keeps the throughput.
// Schedule::command('mail:digest 24 --mod=2 --val=0')
//     ->dailyAt('08:00')
//     ->withoutOverlapping()
//     ->runInBackground();
// Schedule::command('mail:digest 24 --mod=2 --val=1')
//     ->dailyAt('08:00')
//     ->withoutOverlapping()
//     ->runInBackground();
//
// Unified digest - one email per user covering all their communities.
// Schedule::command('mail:digest:unified --mode=daily')
//     ->dailyAt('08:00')
//     ->withoutOverlapping()
//     ->runInBackground();
//
// Immediate mode - V1-parity per-group iteration, sharded 8-way.
//
// A single worker manages ~250 emails/min; arrival rate × avg
// immediate-frequency members per group is ~308/min, so one worker
// falls ~60/min behind permanently. 8 shards (groups partitioned by
// MOD(groupid, 8)) give substantial headroom with no group ever
// touched by more than one worker — disjoint partitions mean no
// advisory locking needed between shards. Each shard has its own
// flock file via the lockKeySuffix() hook on the command.
//
// Container has 6 cores; 8 shards slightly oversubscribes but each
// shard spends significant time in SMTP I/O so the extra concurrency
// still buys throughput. Raise further if backlog persists; lower if
// CPU saturates.
//
// Walks V1's groups_digests cursor and defers messages whose AI-
// generated attachment hasn't arrived yet (up to
// ATTACHMENT_WAIT_DEADLINE_MINUTES, then falls back to the type-
// specific placeholder).
//
// Daily mode is intentionally NOT enabled here — V1's bulk3
// `digest.php -i 1/2/4/8/24` crons still own daily. Our daily code
// references users_digests which doesn't exist on prod.
$immediateShardCount = 8;
foreach (range(0, $immediateShardCount - 1) as $shardIndex) {
    // --max-iterations=60 keeps the worker iterating internally for up to
    // ~one minute so we don't sit idle between cron ticks (a single pass
    // takes ~25s and the cron is every-minute, which left ~35s of dead
    // time per cycle — observed as procs=0 in mid-tick samples). The
    // flock self-bounces overlap so we can't double-up if a tick fires
    // before the previous one's loop has exited.
    Schedule::command("mail:digest:unified --mode=immediate --shard={$shardIndex} --shards={$immediateShardCount} --max-iterations=60")
        ->everyMinute()
        ->sendOutputTo(cronLog("mail:digest:unified.shard{$shardIndex}"))
        ->runInBackground();
}

// Donation-related commands. V1 equivalents on bulk3 disabled 2026-05-12.
Schedule::command('mail:donations:thank')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('mail:donations:thank'))
    ->runInBackground();

Schedule::command('mail:donations:ask')
    ->dailyAt('17:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('mail:donations:ask'))
    ->runInBackground();

// Daily donation summary email to fundraising — running total of today's
// donations. V1 (cron/donations_email.php) ran hourly 06:00-22:00 so the
// team gets intraday visibility; matching that here.
Schedule::command('mail:donations:summary')
    ->hourly()
    ->between('06:00', '22:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('mail:donations:summary'))
    ->runInBackground();

// User management commands (users:cleanup still parked — no V1 cutover).
// Schedule::command('users:cleanup')
//     ->weekly()
//     ->sundays()
//     ->at('06:00')
//     ->withoutOverlapping()
//     ->runInBackground();

// Email spool processing - runs continuously in daemon mode via supervisor.
// See docker/supervisor.conf for the mail-spooler program.

// Background task queue - processes tasks queued by Go API server.
// Runs continuously with internal looping. Handles push notifications and emails.
Schedule::command('queue:background-tasks --max-iterations=60 --spool')
    ->everyMinute()
    ->appendOutputTo(cronLog('queue:background-tasks'))
    ->runInBackground();

// Clean up old sent emails - run daily.
Schedule::command('mail:spool:process --cleanup --cleanup-days=7')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('mail:spool:process'))
    ->runInBackground();

// Clean up incoming email archives older than 48 hours.
Schedule::command('mail:cleanup-archive')
    ->hourly()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('mail:cleanup-archive'))
    ->runInBackground();

// Send birthday emails to members of groups founded on today's date.
// V1: cron/birthday.php (daily 12:00)
Schedule::command('birthday:send-emails')
    ->dailyAt('12:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('birthday:send-emails'))
    ->runInBackground();

// Check for inactive mods and notify group owners / mentors.
// V1: cron/mod_active.php (Monday 15:00)
Schedule::command('groups:check-mod-welfare')
    ->weeklyOn(1, '15:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('groups:check-mod-welfare'))
    ->runInBackground();

// Send a copy of each group's welcome mail to mods once a year for review.
// V1: cron/group_welcomereview.php (daily 15:00; service dedupes by
// groups.welcomereview timestamp so each group only fires on its anniversary).
// V1 had a second identical crontab entry at 01:00 — likely accidental
// duplicate; not preserved here since the service is idempotent across runs
// on the same day.
Schedule::command('groups:welcome-review')
    ->dailyAt('15:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('groups:welcome-review'))
    ->runInBackground();

// Calculate and send the monthly LoveJunk/TrashNothing invoice split to TN.
// V1: cron/lovejunk_tn_invoice.php (1st of month at 15:00)
Schedule::command('lovejunk:send-tn-invoice')
    ->monthlyOn(1, '15:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('lovejunk:send-tn-invoice'))
    ->runInBackground();

// Engagement emails to at-risk and inactive users.
// V1: cron/engage.php (daily 16:00). Slow by design — pulls every user with
// engagement='Inactive' and runs per-user eligibility queries.
Schedule::command('mail:engage')
    ->dailyAt('16:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('mail:engage'))
    ->runInBackground();

// Ask eligible users with outcomes/offers to share their Freegle story.
// V1: cron/stories.php (weekly Saturday 11:00)
Schedule::command('stories:ask')
    ->weeklyOn(6, '11:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('stories:ask'))
    ->runInBackground();

// =============================================================================
// ADMIN EMAILS
// =============================================================================

// Copy suggested admins to per-group copies and clean up old pending admins.
// Creates per-group copies (pending=1) for moderator approval,
// then marks the suggested admin as complete.
Schedule::command('mail:admin:copy')
    ->everyMinute()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('mail:admin:copy'))
    ->runInBackground();

// Send approved admin emails to group members.
// Only processes admins that are approved (pending=0) and not yet complete.
Schedule::command('mail:admin:send --spool')
    ->everyMinute()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('mail:admin:send'))
    ->runInBackground();

// Chase moderators about pending suggested admins.
// Sends reminder emails after 48h, once per day, up to 7 days.
Schedule::command('mail:admin:chase')
    ->hourly()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('mail:admin:chase'))
    ->runInBackground();


// =============================================================================
// NOT YET ENABLED — enable individually after testing
// =============================================================================

// Process pending chat messages (processingrequired=1): spam check, roster update, reopen closed chats.
// V1: cron/chat_process.php (continuous daemon, restarted by cron every 2 minutes)
// IncomingMailService creates messages with processingrequired=1; this makes them visible to notifications.
Schedule::command('chats:process-incoming')
    ->everyMinute()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('chats:process-incoming'))
    ->runInBackground();

// Process pending membership history entries: send per-group welcome emails, flag reviewed members.
// V1: cron/memberships_processing.php (every 1 minute)
// Go API creates memberships_history with processingrequired=1; this sends welcome emails + review flags.
Schedule::command('memberships:process')
    ->everyMinute()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('memberships:process'))
    ->runInBackground();

// Process pending GDPR data export requests and purge old completed data.
// V1: cron/exports.php (every 1 minute)
Schedule::command('users:process-exports')
    ->everyMinute()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('users:process-exports'))
    ->runInBackground();

// Update user engagement classifications based on activity.
// V1: cron/engage_update.php (daily at 03:00)
Schedule::command('users:update-engagement')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('users:update-engagement'))
    ->runInBackground();

// Remove search index entries for messages older than 30 days.
// V1: cron/message_deindex.php (daily at 01:00)
Schedule::command('messages:deindex')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('messages:deindex'))
    ->runInBackground();

// Score microvolunteering actions and promote accurate users to Moderate trust.
// V1: cron/microactions_score.php (daily at 23:00)
// Note: Laravel uses correct SUM() aggregation in promote() — V1 had a longstanding aggregation
// bug (bare score_positive/_negative under GROUP BY userid) that masked promotions. First Laravel
// run after migration may bulk-promote backlog (catch-up).
Schedule::command('microvolunteering:score')
    ->dailyAt('23:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('microvolunteering:score'))
    ->runInBackground();

// Notify Moderate+ members of pending messages awaiting microvolunteering review,
// and Basic members of approved messages eligible for rating/thanks.
// V1: cron/microvolunteering.php (every 5 minutes).
Schedule::command('microvolunteering:notify')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('microvolunteering:notify'))
    ->runInBackground();

// Update cached location names in user settings when the canonical name has changed.
// V1: cron/users_remap.php (daily at 05:00)
Schedule::command('users:remap-locations')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('users:remap-locations'))
    ->runInBackground();

// V1: cron/tn_names.php — fix display names for TN users whose email encodes their name.
Schedule::command('users:fix-tn-names')
    ->dailyAt('06:30')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('users:fix-tn-names'))
    ->runInBackground();

// Update message subjects when associated location names have changed.
// V1: cron/messages_remap.php (every 5 minutes)
Schedule::command('messages:remap-subjects')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('messages:remap-subjects'))
    ->runInBackground();

// Record giver/taker visualise pairs for offers with photos (distance ≤ 30 km).
// V1: cron/visualise.php (every 5 minutes) — disabled pending sign-off
// Note: V1 called ensureAvatar() as a side effect to refresh TN user avatars; omitted here
//       since it involved external HTTP calls and is unrelated to the visualise insert.
Schedule::command('messages:update-visualise')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('messages:update-visualise'))
    ->runInBackground();

// Update messages_spatial with recent messages, outcomes, and remove stale entries.
// V1: cron/message_spatial.php (every 5 minutes)
// Note: V1 also pushed freebie-alert jobs to Pheanstalk — that mechanism is retired in the new stack.
Schedule::command('messages:update-spatial-index')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('messages:update-spatial-index'))
    ->runInBackground();

// Update common email domains table (domains used by > 1000 users).
// V1: cron/domains_common.php (weekly, Friday 07:00)
Schedule::command('domains:update-common')
    ->weeklyOn(5, '07:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('domains:update-common'))
    ->runInBackground();

// Generate AI illustrations for messages with no photos.
// V1: cron/messages_illustrations.php (every 1 minute) — V1 cron already disabled on bulk3.
Schedule::command('messages:generate-illustrations')
    ->everyMinute()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('messages:generate-illustrations'))
    ->runInBackground();

// Generate AI illustrations for canonical job categories (pre-caching).
// V1: cron/jobs_illustrations.php (every 30 minutes) — V1 cron already disabled on bulk3.
Schedule::command('jobs:generate-illustrations')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('jobs:generate-illustrations'))
    ->runInBackground();

// Fetch app versions from iOS App Store and Google Play - runs every 6 hours.
// V1: cron/get_app_release_versions.php
Schedule::command('data:fetch-app-versions')
    ->everySixHours()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('data:fetch-app-versions'))
    ->runInBackground();

// Sync WhatJobs job listings from XML feeds into the jobs table.
// V1: cron/whatjobs.php (hourly 08:00-22:00). With the Photon geocoder now
// wired up, a cold-cache run hits Photon for ~60k unique (city,state,country)
// tuples and can take hours; even warm runs comfortably exceed an hour. Run
// every 3 hours instead of hourly so a long run doesn't queue up overlapping
// scheduled invocations (the in-command Cache::lock TTL is matched to this).
Schedule::command('integrations:sync-whatjobs')
    ->cron('0 */3 * * *')
    ->between('08:00', '22:00')
    ->withoutOverlapping(240)
    ->sendOutputTo(cronLog('integrations:sync-whatjobs'))
    ->runInBackground();

// Sync Freegle offers with LoveJunk - runs every minute.
// V1: cron/lovejunk.php
Schedule::command('integrations:sync-lovejunk')
    ->everyMinute()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('integrations:sync-lovejunk'))
    ->runInBackground();

// Sync upcoming Restart Project repair events into group events.
// V1: cron/restartproject.php (23:00 daily)
Schedule::command('integrations:sync-restartproject')
    ->dailyAt('23:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('integrations:sync-restartproject'))
    ->runInBackground();

// Sync upcoming Repair Cafe Wales events into group events.
// V1: cron/repaircafewales.php (23:00 daily)
Schedule::command('integrations:sync-repaircafewales')
    ->dailyAt('23:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('integrations:sync-repaircafewales'))
    ->runInBackground();

// Message expiry - process deadline-expired messages and spatial index expiry.
// V1: cron/messages_expired.php (was hourly; daily is sufficient since deadline < CURDATE() only changes daily).
// Fixed: clears messages_outcomes_intended before creating outcome (matches V1 mark()).
// Spatial pass mirrors V1 Message::processExpiry(): only acts on messages already marked OUTCOME_EXPIRED.
Schedule::command('messages:process-expired --spatial')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('messages:process-expired'))
    ->runInBackground();

// V1: cron/purge_messages.php
// Fixed: messages_history default corrected to 31 days (matches V1 MessageCollection::RECENTPOSTS).
Schedule::command('purge:messages')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('purge:messages'))
    ->runInBackground();

// V1: cron/locations_skewwhiff.php
Schedule::command('locations:fix-skewed')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('locations:fix-skewed'))
    ->runInBackground();

// V1: cron/locations_pgsql (locations_pgsql_update.php + locations_pgsql_map.php)
// Full sync of MySQL locations to PostgreSQL/PostGIS, then remap postcodes to areas.
Schedule::command('locations:sync-pgsql')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('locations:sync-pgsql'))
    ->runInBackground();

// V1: cron/user_ratings.php
Schedule::command('users:update-ratings')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('users:update-ratings'))
    ->runInBackground();

// V1: cron/supporttools.php
// Note: safer than V1 — never downgrades Admin users, only Support.
Schedule::command('users:update-support-roles')
    ->hourly()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('users:update-support-roles'))
    ->runInBackground();

// Validate group boundary geometry (CGA/DPA polygons).
// V1: cron/check_cgas.php (every 5 minutes) — disabled pending sign-off
Schedule::command('groups:check-boundaries')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('groups:check-boundaries'))
    ->runInBackground();

// Update group stats: fix repost settings, polyindex, activity/funding, mod counts, stats_outcomes.
// V1: cron/group_stats.php (daily at 02:00) — metadata-maintenance portion only.
// The per-day per-type Stats::generate() rows come from stats:generate-daily below.
// TrashNothing group sync is intentionally not migrated (V1 keyed off TNKEY constant).
Schedule::command('groups:update-stats')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('groups:update-stats'))
    ->runInBackground();

// Per-group daily stats (Outcomes, Approved/Spam counts, feedback, breakdowns, replies, weight, ...).
// V1: cron/group_stats.php Stats::generate(yesterday) loop. Runs after groups:update-stats so the
// activity/funding rollup it does in the 02:00 job uses today's freshly-written ApprovedMessageCount
// rows on the NEXT day's run (V1 had the same one-day-stale property).
Schedule::command('stats:generate-daily')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('stats:generate-daily'))
    ->runInBackground();

// V1: cron/groups_closed.php
Schedule::command('groups:remind-closed')
    ->weeklyOn(1, '09:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('groups:remind-closed'))
    ->runInBackground();

// V1: cron/group_customisation.php — script existed in scripts/cron/ but no
// crontab entry, so it never ran in V1. Migrating to Laravel adds the schedule.
Schedule::command('groups:remind-customisation')
    ->monthlyOn(1, '08:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('groups:remind-customisation'))
    ->runInBackground();

// V1: cron/donations_thank.php
// Schedule::command('mail:donations:thank')
//     ->dailyAt('09:00')
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('mail:donations:thank'))
//     ->runInBackground();

// V1: cron/donations_ads_target.php
Schedule::command('donations:update-ads-target')
    ->everyMinute()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('donations:update-ads-target'))
    ->runInBackground();

// =============================================================================
// AI IMAGE REVIEW
// =============================================================================

// Update usage counts for AI images (how many posts use each image).
Schedule::command('ai:usage-counts:update')
    ->hourly()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('ai:usage-counts:update'))
    ->runInBackground();


// =============================================================================
// GIFT AID
// =============================================================================

// Update gift aid data: identify postcodes, houses, consented donations.
// Also sends one-off chase-up emails to eligible donors (2-30 days ago, PayPal/Stripe).
// V1: cron/donations_giftaid.php (every 10 minutes)
Schedule::command('donations:update-giftaid')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('donations:update-giftaid'))
    ->runInBackground();

// =============================================================================
// VECTOR SEARCH EMBEDDINGS
// =============================================================================

// Generate vector embeddings for new messages (for semantic search).
Schedule::command('embeddings:generate')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('embeddings:generate'))
    ->runInBackground();

// =============================================================================
// NOT YET ENABLED - pending review / sign-off
// Index unindexed messages for search.
// V1: cron/message_unindexed.php (every 30 min)
Schedule::command('messages:update-index')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('messages:update-index'))
    ->runInBackground();
// Remove confirmed spammers from groups.
// V1: cron/check_spammers.php
// Schedule::command('users:remove-spammers')
//     ->everyFiveMinutes()
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('users:remove-spammers'))
//     ->runInBackground();

// Process chat spam messages.
// V1: cron/chat_spam.php
// Schedule::command('chats:process-spam')
//     ->hourly()
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('chats:process-spam'))
//     ->runInBackground();

// Send mod notifications.
// V1: cron/mod_notifs.php
// Schedule::command('mail:mod-notifs')
//     ->everyFiveMinutes()
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('mail:mod-notifs'))
//     ->runInBackground();

// Update GiftAid donations.
// V1: cron/donations_giftaid.php
// Schedule::command('donations:update-giftaid')
//     ->hourly()
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('donations:update-giftaid'))
//     ->runInBackground();

// Volunteering opportunity roundup — weekly to group members.
// V1: cron/volunteering.php (weekly Mon 23:00, two mod-2 shards on bulk3 —
// disabled there 2026-05-12 when this Laravel command took over).
Schedule::command('mail:volunteering-digest')
    ->weeklyOn(1, '23:00')  // Monday at 11pm
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('mail:volunteering-digest'))
    ->runInBackground();

// Community events roundup — weekly to group members.
// V1: cron/events.php (weekly Thu 23:00, two mod-2 shards on bulk3 —
// disabled there 2026-05-12). Single-threaded here; the streaming /
// activity-filtered query keeps the working set well below V1's count.
Schedule::command('mail:events-digest')
    ->weeklyOn(4, '23:00')  // Thursday at 11pm
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('mail:events-digest'))
    ->runInBackground();

// Notify group mods about recent chitchat (newsfeed) posts from their members.
// V1: cron/newsfeed_modnotif.php (daily 13:30)
Schedule::command('mail:newsfeed-mod-notif')
    ->dailyAt('13:30')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('mail:newsfeed-mod-notif'))
    ->runInBackground();

// =============================================================================
// NEWSFEED
// =============================================================================

// Fetch and cache link previews for URLs found in recent newsfeed posts.
// V1: cron/newsfeed_link_previews.php (every 1 minute)
Schedule::command('newsfeed:generate-link-previews')
    ->everyMinute()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('newsfeed:generate-link-previews'))
    ->runInBackground();

// Send the newsfeed (chitchat) digest of recent nearby posts to active users.
// V1: cron/newsfeed_digest.php (daily 15:30)
// Scheduler disabled pending go-live, consistent with the other migrated mail
// jobs (mail:alerts:send, mail:digest, etc.). Enable when the team is ready.
// Schedule::command('mail:newsfeed:digest')
//     ->dailyAt('15:30')
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('mail:newsfeed:digest'))
//     ->runInBackground();

// =============================================================================
// NOTICEBOARDS
// =============================================================================

// Thank users who added noticeboards (once per user, not per board).
// V1: cron/noticeboards.php (daily at 15:30)
Schedule::command('noticeboards:thank-users')
    ->dailyAt('15:30')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('noticeboards:thank-users'))
    ->runInBackground();

// =============================================================================
// STORIES
// =============================================================================

// Send unreviewed stories to central team for newsletter voting.
// V1: cron/stories_tocentral.php (weekly, Friday 14:00)
Schedule::command('stories:send-to-central')
    ->weeklyOn(5, '14:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('stories:send-to-central'))
    ->runInBackground();

// Send the stories newsletter to all eligible Freegle members.
// V1: cron/stories_newsletter.php (monthly, 12th 23:00)
Schedule::command('stories:newsletter')
    ->monthlyOn(12, '23:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('stories:newsletter'))
    ->runInBackground();

// =============================================================================
// GIT SUMMARY
// =============================================================================

// Git summary - weekly on Wednesday at 6pm UTC.
// Sends AI-powered summary of code changes to Discourse.
Schedule::command('data:git-summary')
    ->weeklyOn(3, '18:00')  // Wednesday at 6pm UTC
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('data:git-summary'))
    ->runInBackground();

// Note: App release classification is now handled directly in CircleCI.
// The check-hotfix-promote job runs after beta builds and triggers
// immediate promotion if the commit message has hotfix: prefix.
// See iznik-nuxt3/.circleci/config.yml

// Auto-reject chat messages stuck in review for 7+ days; notify group mods
// about messages pending review for 48+ hours; send mentors a daily summary.
// V1: cron/chat_review.php (daily)
Schedule::command('chats:review-pending')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('chats:review-pending'))
    ->runInBackground();

// Alert geeks about Freegle groups that have not received messages in 7+ days.
// V1: cron/groups_nomessages.php — script existed in scripts/cron/ but no
// crontab entry, so it never ran in V1. Migrating to Laravel adds the schedule.
Schedule::command('groups:alert-no-messages')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('groups:alert-no-messages'))
    ->runInBackground();

// Sync Reach Volunteering opportunities.
// V1: cron/reachvolunteering.php (daily at 21:00)
Schedule::command('integrations:sync-reachvolunteering')
    ->dailyAt('21:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('integrations:sync-reachvolunteering'))
    ->runInBackground();

// Sync EEELabel micro-volunteering rows to the eee-browser labels DB so
// volunteer labels show up on the model-accuracy dashboard. Idempotent
// (upserts) and uses an incremental cursor, so frequent runs are safe.
Schedule::command('eee:sync-mv-labels')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('eee:sync-mv-labels'))
    ->runInBackground();
