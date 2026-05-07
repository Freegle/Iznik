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

// Clean up old sessions.
// V1: cron/purge_sessions.php
Schedule::command('cleanup:sessions')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('cleanup:sessions'))
    ->runInBackground();

// Remove spam members from groups and clean up their content.
// V1: cron/check_spammers.php
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
// V1: cron/mod_notifs.php
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
// V1: cron/users_modmails.php
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
// V1: cron/chat_expected.php
Schedule::command('chats:update-expected')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('chats:update-expected'))
    ->runInBackground();

// =============================================================================
// DISABLED COMMANDS (to be enabled when ready)
// =============================================================================

/*
// Immediate digests (-1) - run every minute.
Schedule::command('mail:digest -1')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Hourly digests - run every hour.
Schedule::command('mail:digest 1')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Every 2 hours digests.
Schedule::command('mail:digest 2')
    ->everyTwoHours()
    ->withoutOverlapping()
    ->runInBackground();

// Every 4 hours digests.
Schedule::command('mail:digest 4')
    ->everyFourHours()
    ->withoutOverlapping()
    ->runInBackground();

// Every 8 hours digests (3 times per day).
Schedule::command('mail:digest 8')
    ->cron('0 0,8,16 * * *')
    ->withoutOverlapping()
    ->runInBackground();

// Daily digests.
Schedule::command('mail:digest 24')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground();

// Message expiry - run daily.
Schedule::command('messages:process-expired --spatial')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

// Purge operations - run daily at off-peak hours.
// (purge:chats, purge:logs, emails:validate enabled above.)
Schedule::command('purge:messages')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('locations:fix-skewed')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('users:update-ratings')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Unified digest - replaces per-group digests.
// Daily mode - sends one digest per user with posts from all their communities.
Schedule::command('mail:digest:unified --mode=daily')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground();

// Immediate mode - sends notifications for users who want instant alerts.
Schedule::command('mail:digest:unified --mode=immediate')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Donation-related commands.
Schedule::command('mail:donations:thank')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('mail:donations:ask')
    ->dailyAt('17:00')
    ->withoutOverlapping()
    ->runInBackground();

// User management commands.
// (users:update-kudos enabled above.)
Schedule::command('users:cleanup')
    ->weekly()
    ->sundays()
    ->at('06:00')
    ->withoutOverlapping()
    ->runInBackground();

// Group/chat maintenance, lastaccess fallback enabled above.

// Donation ad targeting - update ads-off target based on recent donations.
Schedule::command('donations:update-ads-target')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Support tools role management based on team membership.
Schedule::command('users:update-support-roles')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Email spool processing - runs continuously in daemon mode via supervisor.
// See docker/supervisor.conf for the mail-spooler program.
*/

// Background task queue - processes tasks queued by Go API server.
// Runs continuously with internal looping. Handles push notifications and emails.
Schedule::command('queue:background-tasks --max-iterations=60 --spool')
    ->everyMinute()
    ->sendOutputTo(cronLog('queue:background-tasks'))
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

// Delete spammy WhatJobs postings (same bodyhash posted > 50 times across UK).
// V1: cron/whatjobs_spam.php
Schedule::command('cleanup:whatjobs-spam')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('cleanup:whatjobs-spam'))
    ->runInBackground();

// Update common email domains table (domains used by > 1000 users).
// V1: cron/domains_common.php (weekly, Friday 07:00)
Schedule::command('domains:update-common')
    ->weeklyOn(5, '07:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('domains:update-common'))
    ->runInBackground();

// Remove search index entries for messages older than 30 days.
// V1: cron/message_deindex.php (daily at 01:00)
Schedule::command('messages:deindex')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('messages:deindex'))
    ->runInBackground();

// Add search index entries for recent messages that aren't indexed yet.
// V1: cron/message_unindexed.php (every 30 min)
Schedule::command('messages:index-unindexed')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('messages:index-unindexed'))
    ->runInBackground();

// Score microvolunteering actions and promote accurate users to Moderate trust.
// V1: cron/microactions_score.php (daily at 23:00)
Schedule::command('microvolunteering:score')
    ->dailyAt('23:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('microvolunteering:score'))
    ->runInBackground();

// Track recent mod mail actions (rejected/deleted/replied) per user for rate-limiting.
// V1: cron/users_modmails.php (every 5 minutes)
Schedule::command('users:update-modmails')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('users:update-modmails'))
    ->runInBackground();

// Update cached location names in user settings when the canonical name has changed.
// V1: cron/users_remap.php (daily at 05:00)
Schedule::command('users:remap-locations')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('users:remap-locations'))
    ->runInBackground();

// Update message subjects when associated location names have changed.
// V1: cron/messages_remap.php (every 5 minutes)
Schedule::command('messages:remap-subjects')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('messages:remap-subjects'))
    ->runInBackground();

// Update messages_spatial with recent messages, outcomes, and remove stale entries.
// V1: cron/message_spatial.php (every 5 minutes)
// Note: V1 also pushed freebie-alert jobs to Pheanstalk — that mechanism is retired in the new stack.
Schedule::command('messages:update-spatial-index')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('messages:update-spatial-index'))
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
// V1: cron/donations_giftaid.php
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
// =============================================================================

// Deindex old messages from search.
// V1: cron/message_deindex.php
// Schedule::command('messages:deindex')
//     ->hourly()
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('messages:deindex'))
//     ->runInBackground();

// Index unindexed messages for search.
// V1: cron/message_unindexed.php
// Schedule::command('messages:update-index')
//     ->hourly()
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('messages:update-index'))
//     ->runInBackground();

// Score microvolunteering tasks.
// V1: cron/microvolunteering.php
// Schedule::command('microvolunteering:score')
//     ->hourly()
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('microvolunteering:score'))
//     ->runInBackground();

// Update user modmails counts.
// V1: cron/users_modmails.php
// Schedule::command('users:update-modmails')
//     ->hourly()
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('users:update-modmails'))
//     ->runInBackground();

// Remap user locations.
// V1: cron/users_remap_locations.php
// Schedule::command('users:remap-locations')
//     ->hourly()
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('users:remap-locations'))
//     ->runInBackground();

// Remap message subjects.
// V1: cron/messages_remap_subjects.php
// Schedule::command('messages:remap-subjects')
//     ->hourly()
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('messages:remap-subjects'))
//     ->runInBackground();

// Update spatial index for messages.
// V1: cron/message_spatial.php
// Schedule::command('messages:update-spatial-index')
//     ->everyFiveMinutes()
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('messages:update-spatial-index'))
//     ->runInBackground();

// Clean up whatjobs spam.
// V1: cron/whatjobs_spam.php
// Schedule::command('cleanup:whatjobs-spam')
//     ->everyFiveMinutes()
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('cleanup:whatjobs-spam'))
//     ->runInBackground();

// Update common email domains table (domains used by > 1000 users).
// V1: cron/domains_common.php (weekly, Friday 07:00)
// Schedule::command('domains:update-common')
//     ->weeklyOn(5, '07:00')
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('domains:update-common'))
//     ->runInBackground();

// Generate AI illustrations for messages with no photos.
// V1: cron/messages_illustrations.php (every 1 minute)
// Schedule::command('messages:generate-illustrations')
//     ->everyMinute()
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('messages:generate-illustrations'))
//     ->runInBackground();

// Generate AI illustrations for canonical job categories (pre-caching).
// V1: cron/jobs_illustrations.php (every 30 minutes)
// Schedule::command('jobs:generate-illustrations')
//     ->everyThirtyMinutes()
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('jobs:generate-illustrations'))
//     ->runInBackground();

// Fetch app versions from iOS App Store and Google Play - runs every 6 hours.
// V1: cron/get_app_release_versions.php
// Schedule::command('data:fetch-app-versions')
//     ->everySixHours()
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('data:fetch-app-versions'))
//     ->runInBackground();

// Sync Freegle offers with LoveJunk - runs every minute.
// V1: cron/lovejunk.php
// Schedule::command('integrations:sync-lovejunk')
//     ->everyMinute()
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('integrations:sync-lovejunk'))
//     ->runInBackground();

// Update expected reply tracking for User2User chats.
// V1: cron/chat_expected.php (every 5 minutes)
// Schedule::command('chats:update-expected')
//     ->everyFiveMinutes()
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('chats:update-expected'))
//     ->runInBackground();

// Update group stats: fix repost settings, polyindex, activity/funding, mod counts, stats_outcomes.
// V1: cron/group_stats.php (daily at 02:00)
// Schedule::command('groups:update-stats')
//     ->dailyAt('02:00')
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('groups:update-stats'))
//     ->runInBackground();

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
