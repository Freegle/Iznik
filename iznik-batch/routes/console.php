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

// Process bounced emails — mark as invalid.
// V1: cron/bounce.php + bounce_users.php
Schedule::command('mail:bounced')
    ->hourly()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('mail:bounced'))
    ->runInBackground();

// Email health monitor — alerts if incoming or outgoing email flow drops below
// configurable thresholds during daytime hours.
Schedule::command('monitor:email-health')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('monitor:email-health'))
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
Schedule::command('purge:chats')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('purge:messages')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('purge:logs')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground();
Schedule::command('emails:validate')
    ->dailyAt('04:00')
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
Schedule::command('users:update-kudos')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('users:cleanup')
    ->weekly()
    ->sundays()
    ->at('06:00')
    ->withoutOverlapping()
    ->runInBackground();

// Group maintenance.
Schedule::command('groups:update-counts')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Chat maintenance - update message counts and reopen closed User2Mod chats.
Schedule::command('chats:update-counts')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Fallback lastaccess update from chat messages and memberships.
Schedule::command('users:update-lastaccess')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

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
// VECTOR SEARCH EMBEDDINGS
// =============================================================================

// Generate vector embeddings for new messages (for semantic search).
Schedule::command('embeddings:generate')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('embeddings:generate'))
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
