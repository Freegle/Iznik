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

// Point the withoutOverlapping() mutexes below at the configured store. Under the
// default LOCK_STORE=flock this is a no-op (FlockEventMutex handles locking); under
// LOCK_STORE=redis it backs overlap protection with a redis TTL lock that survives
// across the per-tick schedule:run processes and is independent of the primary DB —
// the resilient path for our all-runInBackground jobs. See App\Console\SchedulerMutex.
\App\Console\SchedulerMutex::apply(app(\Illuminate\Console\Scheduling\Schedule::class));

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

// Record the deployed Laravel commit so /api/version reports the live build
// (the monitor-fsm "verified-live" reply gate compares it against merged PRs).
// Lightweight (just a config upsert) — safe to run frequently; deploy:watch is
// disabled and deploy:refresh is too heavy to schedule.
Schedule::command('deploy:record-commit')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30)
    ->sendOutputTo(cronLog('deploy:record-commit'))
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
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('data:update-cpi'))
    ->runInBackground();

// Refresh UK mobile-carrier IP ranges (from RIPEstat) into spam_whitelist_ips so
// CGNAT shared-egress IPs stay exempt from the IP-abuse check (Discourse #9768).
Schedule::command('spam:refresh-mobile-cidrs')
    ->monthly()
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('spam:refresh-mobile-cidrs'))
    ->runInBackground();

// Content check — run all content checks on unprocessed pending messages.
// Promotes clean messages from non-moderated users to Approved; keeps others
// in Pending with failure reasons stored, then notifies group mods.
Schedule::command('messages:contentcheck')
    ->everyMinute()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('messages:contentcheck'))
    ->runInBackground();

// Maintain rippling-out reach (rippling_reach) for active posts.
// Computes per-post reach via the routing server and advances it over time per
// the hazard schedule. Dark until browse/digest/reply-eligibility read it.
//
// Gated by the master activation switch: while ripple.enabled is false the cron is not scheduled at
// all, so no reach is computed, nothing is rippled into new groups, and every reach consumer stays
// inert. This lets the whole feature ship dark and be turned on later with just RIPPLE_ENABLED=true.
if (config('freegle.ripple.enabled')) {
    // --limit=500: the ExpandCommand default is 50 (a conservative memory-pressure floor). Running
    // network-wide the reach population reaches ~11k expanding rows generating ~185 due-advances per
    // tick; initialiseNew and advanceDue each take the full limit, so 500 clears steady-state advances
    // with headroom and drains any initialisation backlog quickly, while staying well under the
    // routing-server load the rollout sustained. Tune via historical due-rate if the routing host strains.
    Schedule::command('ripple:expand', ['--limit' => 500])
        ->everyMinute()
        ->withoutOverlapping(15)
        ->sendOutputTo(cronLog('ripple:expand'))
        ->runInBackground();
}

// Group experiment (per-group before/after): when RIPPLE_WITHIN_GROUPS lists group ids, ripple ONLY
// those groups' posts - even while the global switch above is OFF. The scoped run bypasses the global
// gate in ExpandService::process(), so the rest of the network stays dark. During the experiment you
// run with RIPPLE_ENABLED=false and RIPPLE_WITHIN_GROUPS set, so only this scoped cron is active.
$rippleWithinGroups = (array) config('freegle.ripple.within_groups', []);
if (!empty($rippleWithinGroups)) {
    // --limit=200: at scoped-experiment scale the reach population is ~3.6k expanding rows generating
    // ~60 due-advances per tick, so the default 50 budget is fully consumed advancing and starves new
    // initialisation (the new-group backlog stops draining). 200 leaves headroom for both advances and
    // new inits without over-driving the routing server. Tune up if due-advances approach the limit.
    Schedule::command('ripple:expand', ['--within-group' => implode(',', $rippleWithinGroups), '--limit' => 200])
        ->everyMinute()
        ->withoutOverlapping(15)
        ->sendOutputTo(cronLog('ripple:expand-experiment'))
        ->runInBackground();
}

// Release/expire held external (email/TN) replies as posts ripple out (#3).
// Inert until the reach engine is live -- nothing to release until a reply is held.
Schedule::command('ripple:release-replies')
    ->everyMinute()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('ripple:release-replies'))
    ->runInBackground();

// Best-effort "quicker to get to" moderator notes for rippled-in posts, computed out of the hot
// ripple:expand cron so its routing/KNN calls can't slow rippling (freegle.ripple.proximity_notes
// gates it; withoutOverlapping keeps a slow run from stacking).
Schedule::command('ripple:proximity-notes')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('ripple:proximity-notes'))
    ->runInBackground();

// Update UK spatial data - runs monthly.
// Downloads UK OSM PBF file and rebuilds deprivation quintile CSV for spatial server.
// Signals Go spatial server to reload after update.
Schedule::command('spatial:update-data')
    ->monthlyOn(1, '03:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('spatial:update-data'))
    ->runInBackground();

// Auto-approve pending messages after 48 hours.
// V1: cron/autoapprove.php
Schedule::command('messages:auto-approve')
    ->hourly()
    ->withoutOverlapping(120)
    ->sendOutputTo(cronLog('messages:auto-approve'))
    ->runInBackground();

// Auto-repost messages based on group repost settings.
// V1: cron/autorepost.php
Schedule::command('messages:auto-repost')
    ->hourly()
    ->withoutOverlapping(120)
    ->sendOutputTo(cronLog('messages:auto-repost'))
    ->runInBackground();

// Chase up messages with replies but no outcome.
// V1: cron/chaseup.php
Schedule::command('messages:chase-up')
    ->hourly()
    ->withoutOverlapping(120)
    ->sendOutputTo(cronLog('messages:chase-up'))
    ->runInBackground();

// Deduplicate searches.
// V1: cron/searchdups.php
Schedule::command('cleanup:search-duplicates')
    ->hourly()
    ->withoutOverlapping(120)
    ->sendOutputTo(cronLog('cleanup:search-duplicates'))
    ->runInBackground();

// Deduplicate chat messages.
// V1: cron/chatdups.php
Schedule::command('cleanup:chat-duplicates')
    ->everyTwoHours()
    ->withoutOverlapping(120)
    ->sendOutputTo(cronLog('cleanup:chat-duplicates'))
    ->runInBackground();

// Archive old duplicate profile images, keeping latest per user.
// V1: cron/archive_attachments.php — disabled pending sign-off
Schedule::command('cleanup:archive-profile-images')
    ->dailyAt('22:30')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('cleanup:archive-profile-images'))
    ->runInBackground();

// Clean up old sessions.
// V1: cron/purge_sessions.php
Schedule::command('cleanup:sessions')
    ->dailyAt('03:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('cleanup:sessions'))
    ->runInBackground();

// Compress rotated batch log files and prune those older than the retention
// window (default 7 days). The Monolog 'daily' channel rotates app logs but
// does not compress them, and supervisor's scheduler/worker/spooler logs are
// not Monolog-managed at all - this keeps storage/logs bounded.
Schedule::command('logs:rotate')
    ->dailyAt('00:30')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('logs:rotate'))
    ->runInBackground();

// Remove spam members from groups and clean up their content.
// V1: cron/check_spammers.php (every 5 minutes)
Schedule::command('users:remove-spammers')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('users:remove-spammers'))
    ->runInBackground();

// Process bounced emails — mark as invalid.
// V1: cron/bounce.php + bounce_users.php
Schedule::command('mail:bounced')
    ->hourly()
    ->withoutOverlapping(120)
    ->sendOutputTo(cronLog('mail:bounced'))
    ->runInBackground();

// Charity Partner signup monitor — emails geeks about new entries in the
// charities table (which would otherwise sit Pending, unwatched).
Schedule::command('charity:notify-signups')
    ->hourly()
    ->withoutOverlapping(120)
    ->sendOutputTo(cronLog('charity:notify-signups'))
    ->runInBackground();

// Moderator work notifications — tells mods about pending messages, events, etc.
// Only runs 08:00–21:00; deduplicates against last sent summary.
// V1: cron/mod_notifs.php (hourly)
Schedule::command('mail:mod-notifs')
    ->hourly()
    ->withoutOverlapping(120)
    ->sendOutputTo(cronLog('mail:mod-notifs'))
    ->runInBackground();

// Site-wide / per-group alerts to mods — processes incomplete rows in the
// `alerts` table (broadcasts from the central team, with read receipts and
// escalation). Batches 50 groups per pass via groupprogress, so the 10-min
// cadence lets a Freegle-wide alert work through every group over a few ticks.
// V1: cron/alerts.php (every 10 minutes). Cut over 2026-06-11; V1 disabled
// in the bulk3-internal crontab at the same time to avoid double-sending.
Schedule::command('mail:alerts:send')
    ->everyTenMinutes()
    ->withoutOverlapping(30)
    ->sendOutputTo(cronLog('mail:alerts:send'))
    ->runInBackground();

// Scheduler heartbeat → Sentry Crons. The one failure mode no per-job guard
// can cover is the scheduler itself dying — the host `while true; schedule:run;
// sleep 60` loop exiting, a container recreate, an OOM — which silently stops
// EVERY scheduled job until it restarts (Laravel's scheduler has no catch-up).
// This no-op task checks in to Sentry every 5 minutes; if the loop stops,
// Sentry sees the missed check-in and alerts. Sentry is free for us on the
// open-source plan, so the check-in volume is a non-issue. The check-in is
// deliberately lax to avoid occasional false alarms from the loop's natural
// drift: checkInMargin=15 gives generous grace before a check-in counts as
// missed, and failureIssueThreshold=2 means a single sporadic miss won't raise
// an issue — it takes two consecutive misses — with recoveryThreshold=1
// clearing it on the next good check-in.
Schedule::call(fn () => null)
    ->everyFiveMinutes()
    ->name('scheduler-heartbeat')
    // sentryMonitor(slug, checkInMargin, maxRuntime, updateMonitorConfig, failureIssueThreshold, recoveryThreshold)
    ->sentryMonitor('scheduler-heartbeat', 15, null, true, 2, 1);

// Email health monitor — alerts if incoming or outgoing email flow drops below
// configurable thresholds during daytime hours.
Schedule::command('monitor:email-health')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30)
    ->sendOutputTo(cronLog('monitor:email-health'))
    ->runInBackground();

// Outcome-based monitoring — asserts that scheduled tasks actually DID their
// work (rows written, cursor advanced), not just that the scheduler is alive.
// Breaches escalate to Sentry. Runs inline (it's just a few aggregate queries)
// and is itself heartbeated to Sentry Crons, so a stalled monitor — or a dead
// scheduler — is visible even when no individual job has breached yet.
// See docs/scheduled-outcome-monitoring.md.
// Same lax thresholds as the scheduler heartbeat (generous margin + two
// consecutive misses before an issue) so the monitor's own check-in doesn't
// false-alarm on occasional scheduler drift.
Schedule::command('monitor:scheduled-outcomes')
    ->everyTenMinutes()
    ->name('scheduled-outcomes-monitor')
    ->withoutOverlapping(30)
    // sentryMonitor(slug, checkInMargin, maxRuntime, updateMonitorConfig, failureIssueThreshold, recoveryThreshold)
    ->sentryMonitor('scheduled-outcomes-monitor', 20, null, true, 2, 1)
    ->sendOutputTo(cronLog('monitor:scheduled-outcomes'));

// Notification chaseup - send emails for unseen, unmailed site notifications.
// V1: cron/notification_chaseup.php (every 5 minutes)
Schedule::command('mail:notifications:chaseup')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('mail:notifications:chaseup'))
    ->runInBackground();

// Daily purge of spam chat messages, empty rooms, orphaned chat images.
// V1: cron/purge_chats.php
Schedule::command('purge:chats')
    ->dailyAt('02:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('purge:chats'))
    ->runInBackground();

// Daily log/bounce/likes purge.
// V1: cron/purge_logs.php
Schedule::command('purge:logs')
    ->dailyAt('03:30')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('purge:logs'))
    ->runInBackground();

// Daily syntactic email validation (last 30 days only).
// V1: cron/email_validate.php
Schedule::command('emails:validate')
    ->dailyAt('04:30')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('emails:validate'))
    ->runInBackground();

// Hourly group member/mod count refresh.
// V1: cron/membercounts.php
Schedule::command('groups:update-counts')
    ->hourly()
    ->withoutOverlapping(120)
    ->sendOutputTo(cronLog('groups:update-counts'))
    ->runInBackground();

// Hourly chat-room message count refresh + reopen User2Mod chats with mod
// replies that the user closed before seeing.
// V1: cron/chat_latestmessage.php
Schedule::command('chats:update-counts')
    ->hourly()
    ->withoutOverlapping(120)
    ->sendOutputTo(cronLog('chats:update-counts'))
    ->runInBackground();

// Sync recent mod actions into users_modmails and prune old entries.
// V1: cron/users_modmails.php (every 5 minutes)
Schedule::command('users:update-modmails')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('users:update-modmails'))
    ->runInBackground();

// Hourly fallback users.lastaccess update from chat / membership activity.
// V1: cron/lastaccess.php
Schedule::command('users:update-lastaccess')
    ->hourly()
    ->withoutOverlapping(120)
    ->sendOutputTo(cronLog('users:update-lastaccess'))
    ->runInBackground();

// Update chat reply-expectation tracking and per-user reply-time metrics.
// V1: cron/chat_expected.php (every 5 minutes)
Schedule::command('chats:update-expected')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('chats:update-expected'))
    ->runInBackground();

// Send calendar invites and chat reminders for arranged handover trysts.
// V1: cron/tryst.php (every 1 minute)
Schedule::command('chats:send-tryst-reminders')
    ->everyMinute()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('chats:send-tryst-reminders'))
    ->runInBackground();

// Chase up mods about User2Mod chats with no mod reply older than 6.55 days.
// V1: cron/chat_chaseupmods.php (daily 15:30)
Schedule::command('chats:chaseup-mods')
    ->dailyAt('15:30')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('chats:chaseup-mods'))
    ->runInBackground();

// Warn innocent users who chatted with spammers; auto-mark spam chat messages.
// V1: cron/chat_spam.php (every 5 minutes)
Schedule::command('chats:process-spam')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('chats:process-spam'))
    ->runInBackground();

// Sync data from TrashNothing.
// This command can be called more frequently if the "kick" API is called by TN,
// e.g. to reduce latency by requesting an immediate sync after sending a chat message.
Schedule::command('tn:sync')
    ->everyMinute()
    ->withoutOverlapping(15)
    ->runInBackground();

// =============================================================================
// UNIFIED DIGEST (daily "What's New" + immediate notifications)
// =============================================================================

// Daily digest is still owned by V1 (cron/digest.php -i 24 on
// bulk3-internal). This unified-digest daily run is SAFE to leave enabled
// because it is gated by FREEGLE_DIGEST_DAILY_ALLOWLIST, which defaults to
// empty = send to nobody (see UnifiedDigestService::getDailyAllowlist). To
// pilot the new "What's New" format, set that env var to one or more
// addresses: those users receive the new daily digest IN ADDITION to V1's,
// for a tracked side-by-side comparison. Set it to '*' for the full cutover.
// 07:00 UK local, with morning catch-up. The app runs in UTC, so pin to the
// configured local zone (FREEGLE_TIMEZONE, default Europe/London) and let
// Laravel resolve BST/GMT.
//
// Laravel's scheduler has no catch-up: a plain dailyAt('07:00') has one due
// minute, so if schedule:run isn't ticking at exactly 07:00 (container
// restart, deploy, crash, a long previous tick) the whole day's digest is
// silently skipped. The once-per-London-day guard in UnifiedDigestService
// makes repeat runs safe no-ops, so instead of firing once we tick every
// 30 min across the morning: the first live tick at/after 07:00 sends, the
// guard turns every later tick into a no-op, and withoutOverlapping stops a
// second start while the multi-hour run is still going. A missed 07:00 thus
// self-heals at 07:30/08:00/… instead of being lost for the day.
// Sharded (full cutover, allowlist '*'). A single worker reaches only a fraction of the
// ~78k daily members inside the 5-hour 07:00-12:00 window, so the rest perpetually
// backlog. Partition users across parallel workers by CRC32(users.id) % shards — a HASH,
// not MOD(id): under Galera the auto-increment stride equals the cluster size so raw ids
// skew a MOD-based split (see UnifiedDigestService). Same disjoint-partition model
// immediate mode uses, sharded by user instead of group. Each shard has its own flock via
// lockKeySuffix() (mode+shard keyed), so a still-running multi-hour shard self-bounces the
// next 30-min tick and shards never block each other or the immediate/reach shards.
// Per-shard memory is bounded by DIGEST_LOAD_CAP. Profiling the workers (2026-07-03, 8-core
// box) showed each shard runs at only ~42% of a core — the per-user cost is PHP compute +
// several REMOTE-prod-DB round trips (getPostsForUser/reach-gate/scoring), so a worker spends
// ~58% of its time waiting on the DB, not on any shared local service (it does NOT call the
// routing server; MJML is the in-process mrml engine). That makes it embarrassingly parallel:
// extra shards mostly overlap DB latency for near-free, and the box sits ~60% idle with 4.
// So 8 (matches immediate) to drain the catch-up ~2x faster; safe to raise further while CPU
// idle stays high. (The earlier load-40 "saturation" at 8 was the post-reboot boot storm —
// spatial graph rebuild + immediate backlog — not steady state.)
$dailyShardCount = 8;
foreach (range(0, $dailyShardCount - 1) as $dailyShard) {
    Schedule::command("mail:digest:unified --mode=daily --shard={$dailyShard} --shards={$dailyShardCount}")
        ->timezone(config('freegle.timezone'))
        // everyMinute (not everyThirtyMinutes): a daily sweep of a shard's eligible
        // users takes as long as it takes; when it finishes, the very next tick
        // relaunches it so residual backlog / newly-due users drain within ~60s
        // instead of idling up to 30 minutes. Overlap is prevented by the command's
        // own flock (PreventsOverlapping, keyed per mode+shard) — a tick that fires
        // while the previous sweep is still running just logs "Already running,
        // exiting." (verified live 2026-07-03). The once-per-London-day guard makes
        // every post-send tick a cheap no-op, so this is safe to run continuously.
        ->everyMinute()
        // 07:00–23:00 (London): the once-per-London-day guard means each recipient
        // still gets at most one daily digest, so a wide window only affects WHEN a
        // slow day's tail is sent, not whether. Widened from 20:00 so a large catch-up
        // backlog drains the same evening instead of stalling overnight and resuming at
        // 07:00 (measured drain ~150/min can leave 15k+ pending at a 20:00 cutoff).
        ->between('7:00', '23:00')
        ->sendOutputTo(cronLog("mail:digest:unified.daily.shard{$dailyShard}"))
        ->runInBackground();
}

// Daily new-posts push notification (push:daily-posts).
//
// Trails the daily email digest (07:00) by 30 minutes so users who open
// the email aren't also hit by a push in the same moment. The
// once-per-London-day guard inside the command turns every later tick into
// a no-op, so this is safe to leave scheduled even before the allowlist is
// configured — it exits immediately when FREEGLE_POSTS_PUSH_ALLOWLIST is ''.
Schedule::command('push:daily-posts')
    ->timezone(config('freegle.timezone'))
    ->everyThirtyMinutes()
    ->between('7:30', '12:00')
    ->withoutOverlapping(60)
    ->sendOutputTo(cronLog('push:daily-posts'))
    ->runInBackground();

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
// (Daily mode is scheduled above, gated by FREEGLE_DIGEST_DAILY_ALLOWLIST;
// V1's bulk3 `digest.php -i 24` cron still owns daily for everyone else.)
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

// Reach mail - rippling reach notifications, decoupled from ExpandService's serial Phase-2
// loop (which no longer mails inline; see ExpandService::initialiseNew). Mails members newly
// inside a rippling post's reach, sharded by MOD(msgid, N) exactly as immediate mode shards by
// MOD(groupid, N) - disjoint partitions, per-shard flock (lockKeySuffix), no locking between
// shards. Scheduled unconditionally like ripple:release-replies above: sendReachDigests' window
// query self-idles when no reach rows changed recently, so it costs nothing while rippling is
// dark AND it covers both the global cron and the scoped group experiment (the old inline mail
// ran in both paths). Each invocation drains the whole window in one pass, so no --max-iterations.
$reachMailShardCount = 4;
foreach (range(0, $reachMailShardCount - 1) as $reachShard) {
    Schedule::command("mail:digest:unified --mode=reach --shard={$reachShard} --shards={$reachMailShardCount}")
        ->everyMinute()
        ->sendOutputTo(cronLog("mail:digest:unified.reach.shard{$reachShard}"))
        ->runInBackground();
}

// Donation-related commands. V1 equivalents on bulk3 disabled 2026-05-12.
Schedule::command('mail:donations:thank')
    ->dailyAt('09:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('mail:donations:thank'))
    ->runInBackground();

Schedule::command('mail:donations:ask')
    ->dailyAt('17:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('mail:donations:ask'))
    ->runInBackground();

// Hourly donation status email to fundraising — running total of today's
// donations as a simple table. V1 (cron/donations_email.php) ran hourly
// 06:00-22:00 so the team gets intraday visibility; matching that here.
// This is the *status* mail — it tells the team what's landed in the bank.
// The complementary mail:donations:thank-prep below is a separate concern
// (composing thank-you replies); they intentionally share neither content
// nor recipient list.
Schedule::command('mail:donations:summary')
    ->hourly()
    ->between('06:00', '22:00')
    ->withoutOverlapping(120)
    ->sendOutputTo(cronLog('mail:donations:summary'))
    ->runInBackground();

// Daily thank-prep digest — card-per-donation context for whoever composes
// thank-you replies. Dedup is a config-table high-water-mark
// (donation_thank_prep_last_id): each run shows only donations with id above
// the stored mark, then advances the mark — so every donation appears in
// exactly one digest and is never re-notified (it does NOT filter on the
// users_donations.thanked column). Runs once a day in the evening, after the
// last status mail; recipient is freegle.mail.thanks_addr.
Schedule::command('mail:donations:thank-prep')
    ->dailyAt('20:30')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('mail:donations:thank-prep'))
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
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('mail:spool:process'))
    ->runInBackground();

// Clean up incoming email archives older than 48 hours.
Schedule::command('mail:cleanup-archive')
    ->hourly()
    ->withoutOverlapping(120)
    ->sendOutputTo(cronLog('mail:cleanup-archive'))
    ->runInBackground();

// Send birthday emails to members of groups founded on today's date.
// V1: cron/birthday.php (daily 12:00)
Schedule::command('birthday:send-emails')
    ->dailyAt('12:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('birthday:send-emails'))
    ->runInBackground();

// Check for inactive mods and notify group owners / mentors.
// V1: cron/mod_active.php (Monday 15:00)
Schedule::command('groups:check-mod-welfare')
    ->weeklyOn(1, '15:00')
    ->withoutOverlapping(360)
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
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('groups:welcome-review'))
    ->runInBackground();

// Calculate and send the monthly LoveJunk/TrashNothing invoice split to TN.
// V1: cron/lovejunk_tn_invoice.php (1st of month at 15:00)
Schedule::command('lovejunk:send-tn-invoice')
    ->monthlyOn(1, '15:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('lovejunk:send-tn-invoice'))
    ->runInBackground();

// Engagement emails to at-risk and inactive users.
// V1: cron/engage.php (daily 16:00). Slow by design — pulls every user with
// engagement='Inactive' and runs per-user eligibility queries.
Schedule::command('mail:engage')
    ->dailyAt('16:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('mail:engage'))
    ->runInBackground();

// Ask eligible users with outcomes/offers to share their Freegle story.
// V1: cron/stories.php (weekly Saturday 11:00)
Schedule::command('stories:ask')
    ->weeklyOn(6, '11:00')
    ->withoutOverlapping(360)
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
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('mail:admin:copy'))
    ->runInBackground();

// Send approved admin emails to group members.
// Only processes admins that are approved (pending=0) and not yet complete.
Schedule::command('mail:admin:send --spool')
    ->everyMinute()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('mail:admin:send'))
    ->runInBackground();

// Chase moderators about pending suggested admins.
// Sends reminder emails after 48h, once per day, up to 7 days.
Schedule::command('mail:admin:chase')
    ->hourly()
    ->withoutOverlapping(120)
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
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('chats:process-incoming'))
    ->runInBackground();

// Process pending membership history entries: send per-group welcome emails, flag reviewed members.
// V1: cron/memberships_processing.php (every 1 minute)
// Go API creates memberships_history with processingrequired=1; this sends welcome emails + review flags.
Schedule::command('memberships:process')
    ->everyMinute()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('memberships:process'))
    ->runInBackground();

// Process pending GDPR data export requests and purge old completed data.
// V1: cron/exports.php (every 1 minute)
Schedule::command('users:process-exports')
    ->everyMinute()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('users:process-exports'))
    ->runInBackground();

// Update user engagement classifications based on activity.
// V1: cron/engage_update.php (daily at 03:00)
Schedule::command('users:update-engagement')
    ->dailyAt('03:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('users:update-engagement'))
    ->runInBackground();

// Remove search index entries for messages older than 30 days.
// V1: cron/message_deindex.php (daily at 01:00)
Schedule::command('messages:deindex')
    ->dailyAt('01:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('messages:deindex'))
    ->runInBackground();

// Score microvolunteering actions and promote accurate users to Moderate trust.
// V1: cron/microactions_score.php (daily at 23:00)
// Note: Laravel uses correct SUM() aggregation in promote() — V1 had a longstanding aggregation
// bug (bare score_positive/_negative under GROUP BY userid) that masked promotions. First Laravel
// run after migration may bulk-promote backlog (catch-up).
Schedule::command('microvolunteering:score')
    ->dailyAt('23:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('microvolunteering:score'))
    ->runInBackground();

// Notify Moderate+ members of pending messages awaiting microvolunteering review,
// and Basic members of approved messages eligible for rating/thanks.
// V1: cron/microvolunteering.php (every 5 minutes).
Schedule::command('microvolunteering:notify')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('microvolunteering:notify'))
    ->runInBackground();

// Exhort recently-active established users with an on-site notification nudge
// (default: "Tell us your Freegle story!"). The 90-day per-user cooldown means
// running every minute over a 5-minute active window simply dedupes; matches V1.
// V1: cron/user_exhort.php (every minute).
Schedule::command('notifications:exhort')
    ->everyMinute()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('notifications:exhort'))
    ->runInBackground();

// Refresh UK postcodes (add new, update moved lat/lng) from the Doogal dataset.
// Downloads + unzips the CSV itself. V1: cron/doogal wrapper (daily at 03:00).
Schedule::command('locations:update-postcodes')
    ->dailyAt('03:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('locations:update-postcodes'))
    ->runInBackground();

// Fallback downloader for PayPal donations the IPN missed (last 30 days).
// V1: cron/paypal_download.php (every 4 hours at :30). Skips if PayPal creds unset.
Schedule::command('donations:paypal-download')
    ->cron('30 */4 * * *')
    ->withoutOverlapping(240)
    ->sendOutputTo(cronLog('donations:paypal-download'))
    ->runInBackground();

// Report Freegle groups not represented by an active mod on Discourse + mods not
// signed up. V1: cron/discourse_not_signed_up.php (daily at 03:23). Skips if key unset.
Schedule::command('discourse:not-signed-up')
    ->dailyAt('03:23')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('discourse:not-signed-up'))
    ->runInBackground();

// Update cached location names in user settings when the canonical name has changed.
// V1: cron/users_remap.php (daily at 05:00)
Schedule::command('users:remap-locations')
    ->dailyAt('05:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('users:remap-locations'))
    ->runInBackground();

// V1: cron/tn_names.php — fix display names for TN users whose email encodes their name.
Schedule::command('users:fix-tn-names')
    ->dailyAt('06:30')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('users:fix-tn-names'))
    ->runInBackground();

// Update message subjects when associated location names have changed.
// V1: cron/messages_remap.php (every 5 minutes)
Schedule::command('messages:remap-subjects')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('messages:remap-subjects'))
    ->runInBackground();

// Record giver/taker visualise pairs for offers with photos (distance ≤ 30 km).
// V1: cron/visualise.php (every 5 minutes) — disabled pending sign-off
// Note: V1 called ensureAvatar() as a side effect to refresh TN user avatars; omitted here
//       since it involved external HTTP calls and is unrelated to the visualise insert.
Schedule::command('messages:update-visualise')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('messages:update-visualise'))
    ->runInBackground();

// Update messages_spatial with recent messages, outcomes, and remove stale entries.
// V1: cron/message_spatial.php (every 5 minutes)
// Note: V1 also pushed freebie-alert jobs to Pheanstalk — that mechanism is retired in the new stack.
Schedule::command('messages:update-spatial-index')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('messages:update-spatial-index'))
    ->runInBackground();

// Update common email domains table (domains used by > 1000 users).
// V1: cron/domains_common.php (weekly, Friday 07:00)
Schedule::command('domains:update-common')
    ->weeklyOn(5, '07:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('domains:update-common'))
    ->runInBackground();

// Generate AI illustrations for messages with no photos.
// V1: cron/messages_illustrations.php (every 1 minute) — V1 cron already disabled on bulk3.
Schedule::command('messages:generate-illustrations')
    ->everyMinute()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('messages:generate-illustrations'))
    ->runInBackground();

// Generate AI illustrations for canonical job categories (pre-caching).
// V1: cron/jobs_illustrations.php (every 30 minutes) — V1 cron already disabled on bulk3.
Schedule::command('jobs:generate-illustrations')
    ->everyThirtyMinutes()
    ->withoutOverlapping(60)
    ->sendOutputTo(cronLog('jobs:generate-illustrations'))
    ->runInBackground();

// Fetch app versions from iOS App Store and Google Play - runs every 6 hours.
// V1: cron/get_app_release_versions.php
Schedule::command('data:fetch-app-versions')
    ->everySixHours()
    ->withoutOverlapping(240)
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

// Early-morning sync ahead of the 07:00 UK daily digest. The every-3h UTC
// schedule above starts at 09:00 UTC, so the morning digest would otherwise
// ship jobs last synced ~21:00 the night before (9-10h stale -> closed
// postings -> clicks don't convert to billable). Run at 05:00 UK so the sync
// (and the post-swap KNN rebuild it triggers) completes before the digest.
// Pinned to the local zone so it tracks BST/GMT with the digest; shares the
// command mutex with the run above via withoutOverlapping.
Schedule::command('integrations:sync-whatjobs')
    ->timezone(config('freegle.timezone'))
    ->dailyAt('05:00')
    ->withoutOverlapping(240)
    ->sendOutputTo(cronLog('integrations:sync-whatjobs'))
    ->runInBackground();

// Sync Freegle offers with LoveJunk - runs every minute.
// V1: cron/lovejunk.php
Schedule::command('integrations:sync-lovejunk')
    ->everyMinute()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('integrations:sync-lovejunk'))
    ->runInBackground();

// Sync upcoming Restart Project repair events into group events.
// V1: cron/restartproject.php (23:00 daily)
Schedule::command('integrations:sync-restartproject')
    ->dailyAt('23:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('integrations:sync-restartproject'))
    ->runInBackground();

// Sync upcoming Repair Cafe Wales events into group events.
// V1: cron/repaircafewales.php (23:00 daily)
Schedule::command('integrations:sync-repaircafewales')
    ->dailyAt('23:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('integrations:sync-repaircafewales'))
    ->runInBackground();

// Message expiry - process deadline-expired messages and spatial index expiry.
// V1: cron/messages_expired.php (was hourly; daily is sufficient since deadline < CURDATE() only changes daily).
// Fixed: clears messages_outcomes_intended before creating outcome (matches V1 mark()).
// Spatial pass mirrors V1 Message::processExpiry(): only acts on messages already marked OUTCOME_EXPIRED.
Schedule::command('messages:process-expired --spatial')
    ->dailyAt('03:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('messages:process-expired'))
    ->runInBackground();

// V1: cron/purge_messages.php
// Fixed: messages_history default corrected to 31 days (matches V1 MessageCollection::RECENTPOSTS).
Schedule::command('purge:messages')
    ->dailyAt('02:30')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('purge:messages'))
    ->runInBackground();

// V1: cron/locations_skewwhiff.php
Schedule::command('locations:fix-skewed')
    ->dailyAt('05:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('locations:fix-skewed'))
    ->runInBackground();

// Nightly full postcode -> nearest-area remap, via the spatial server (MySQL
// locations_spatial + iznik-spatial-go KNN). No PostgreSQL. DoogalService-imported
// postcodes rely on this pass to be mapped onto group areas.
Schedule::command('locations:remap-postcodes')
    ->dailyAt('01:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('locations:remap-postcodes'))
    ->runInBackground();

// V1: cron/user_ratings.php
Schedule::command('users:update-ratings')
    ->everyTenMinutes()
    ->withoutOverlapping(30)
    ->sendOutputTo(cronLog('users:update-ratings'))
    ->runInBackground();

// V1: cron/supporttools.php
// Note: safer than V1 — never downgrades Admin users, only Support.
Schedule::command('users:update-support-roles')
    ->hourly()
    ->withoutOverlapping(120)
    ->sendOutputTo(cronLog('users:update-support-roles'))
    ->runInBackground();

// Validate group boundary geometry (CGA/DPA polygons).
// V1: cron/check_cgas.php (every 5 minutes) — disabled pending sign-off
Schedule::command('groups:check-boundaries')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('groups:check-boundaries'))
    ->runInBackground();

// Update group stats: fix repost settings, polyindex, activity/funding, mod counts, stats_outcomes.
// V1: cron/group_stats.php (daily at 02:00) — metadata-maintenance portion only.
// The per-day per-type Stats::generate() rows come from stats:generate-daily below.
// TrashNothing group sync is intentionally not migrated (V1 keyed off TNKEY constant).
Schedule::command('groups:update-stats')
    ->dailyAt('02:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('groups:update-stats'))
    ->runInBackground();

// Per-group daily stats (Outcomes, Approved/Spam counts, feedback, breakdowns, replies, weight, ...).
// V1: cron/group_stats.php Stats::generate(yesterday) loop. Runs after groups:update-stats so the
// activity/funding rollup it does in the 02:00 job uses today's freshly-written ApprovedMessageCount
// rows on the NEXT day's run (V1 had the same one-day-stale property).
Schedule::command('stats:generate-daily')
    ->dailyAt('02:30')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('stats:generate-daily'))
    ->runInBackground();

// V1: cron/groups_closed.php
Schedule::command('groups:remind-closed')
    ->weeklyOn(1, '09:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('groups:remind-closed'))
    ->runInBackground();

// V1: cron/group_customisation.php — script existed in scripts/cron/ but no
// crontab entry, so it never ran in V1. RETIRED 2026-06-02: the "ways to make
// {group} more welcoming" customisation reminder is no longer sent (it was never
// sent in V1 either). The command remains for manual/ad-hoc use only; it is no
// longer scheduled.
// Schedule::command('groups:remind-customisation')
//     ->monthlyOn(1, '08:00')
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('groups:remind-customisation'))
//     ->runInBackground();

// V1: cron/donations_thank.php
// Schedule::command('mail:donations:thank')
//     ->dailyAt('09:00')
//     ->withoutOverlapping()
//     ->sendOutputTo(cronLog('mail:donations:thank'))
//     ->runInBackground();

// V1: cron/donations_ads_target.php
Schedule::command('donations:update-ads-target')
    ->everyMinute()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('donations:update-ads-target'))
    ->runInBackground();

// =============================================================================
// AI IMAGE REVIEW
// =============================================================================

// Update usage counts for AI images (how many posts use each image).
Schedule::command('ai:usage-counts:update')
    ->hourly()
    ->withoutOverlapping(120)
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
    ->withoutOverlapping(30)
    ->sendOutputTo(cronLog('donations:update-giftaid'))
    ->runInBackground();

// =============================================================================
// VECTOR SEARCH EMBEDDINGS
// =============================================================================

// Generate vector embeddings for new messages (for semantic search).
Schedule::command('embeddings:generate')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('embeddings:generate'))
    ->runInBackground();

// =============================================================================
// NOT YET ENABLED - pending review / sign-off
// Index unindexed messages for search.
// V1: cron/message_unindexed.php (every 30 min)
Schedule::command('messages:update-index')
    ->everyThirtyMinutes()
    ->withoutOverlapping(60)
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

// Volunteering opportunity maintenance — daily. Asks owners of dateless
// opportunities approaching expiry whether they are still active (renewal
// reminder), then expires opportunities whose dates have all passed or which
// were never renewed within EXPIRE_AGE (31) days. Runs before the weekly
// digest below so freshly-expired opportunities drop out of that run.
// V1: cron/volunteering.php ran askRenew()+expire() weekly before emailing;
// that cron was disabled 2026-05-12 when Laravel took over the digest, but the
// renewal+expiry maintenance was never migrated — nothing renewed or expired
// dateless opportunities since. Daily (vs V1 weekly) is safe because askRenew()
// now stamps askedtorenew and won't re-ask within a renewal cycle.
Schedule::command('volunteering:maintain')
    ->dailyAt('22:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('volunteering:maintain'))
    ->runInBackground();

// Volunteering opportunity roundup — weekly, ONE combined email per user
// covering every volunteering-enabled group they belong to (plus global
// opportunities), deduplicated. A per-user cadence guard (users_digests
// mode='volunteering', 3-day minimum) makes a same-week re-run a no-op.
// V1: cron/volunteering.php (weekly Mon 23:00, two mod-2 shards on bulk3 —
// disabled there 2026-05-12 when this Laravel command took over).
Schedule::command('mail:volunteering-digest')
    ->weeklyOn(1, '23:00')  // Monday at 11pm
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('mail:volunteering-digest'))
    ->runInBackground();

// Community events roundup — weekly, ONE combined email per user covering
// every event-enabled group they belong to, deduplicated (an event
// cross-posted to several of the user's groups appears once). A per-user
// cadence guard (users_digests mode='events', 3-day minimum) makes a
// same-week re-run a no-op.
// V1: cron/events.php (weekly Thu 23:00, two mod-2 shards on bulk3 —
// disabled there 2026-05-12). Single-threaded here; the streaming /
// activity-filtered query keeps the working set well below V1's count.
Schedule::command('mail:events-digest')
    ->weeklyOn(4, '23:00')  // Thursday at 11pm
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('mail:events-digest'))
    ->runInBackground();

// Notify group mods about recent chitchat (newsfeed) posts from their members.
// V1: cron/newsfeed_modnotif.php (daily 13:30)
Schedule::command('mail:newsfeed-mod-notif')
    ->dailyAt('13:30')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('mail:newsfeed-mod-notif'))
    ->runInBackground();

// =============================================================================
// NEWSFEED
// =============================================================================

// Fetch and cache link previews for URLs found in recent newsfeed posts.
// V1: cron/newsfeed_link_previews.php (every 1 minute)
Schedule::command('newsfeed:generate-link-previews')
    ->everyMinute()
    ->withoutOverlapping(15)
    ->sendOutputTo(cronLog('newsfeed:generate-link-previews'))
    ->runInBackground();

// =============================================================================
// NOTICEBOARDS
// =============================================================================

// Thank users who added noticeboards (once per user, not per board).
// V1: cron/noticeboards.php (daily at 15:30)
Schedule::command('noticeboards:thank-users')
    ->dailyAt('15:30')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('noticeboards:thank-users'))
    ->runInBackground();

// =============================================================================
// STORIES
// =============================================================================

// Send unreviewed stories to central team for newsletter voting.
// V1: cron/stories_tocentral.php (weekly, Friday 14:00)
Schedule::command('stories:send-to-central')
    ->weeklyOn(5, '14:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('stories:send-to-central'))
    ->runInBackground();

// Send the stories newsletter to all eligible Freegle members.
// V1: cron/stories_newsletter.php (monthly, 12th 23:00)
Schedule::command('stories:newsletter')
    ->monthlyOn(12, '23:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('stories:newsletter'))
    ->runInBackground();

// =============================================================================
// GIT SUMMARY
// =============================================================================

// Git summary - weekly on Wednesday at 6pm UTC.
// Sends AI-powered summary of code changes to Discourse.
Schedule::command('data:git-summary')
    ->weeklyOn(3, '18:00')  // Wednesday at 6pm UTC
    ->withoutOverlapping(360)
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
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('chats:review-pending'))
    ->runInBackground();

// Alert geeks about Freegle groups that have not received messages in 7+ days.
// V1: cron/groups_nomessages.php — script existed in scripts/cron/ but no
// crontab entry, so it never ran in V1. Migrating to Laravel adds the schedule.
Schedule::command('groups:alert-no-messages')
    ->dailyAt('07:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('groups:alert-no-messages'))
    ->runInBackground();

// Sync Reach Volunteering opportunities.
// V1: cron/reachvolunteering.php (daily at 21:00)
Schedule::command('integrations:sync-reachvolunteering')
    ->dailyAt('21:00')
    ->withoutOverlapping(360)
    ->sendOutputTo(cronLog('integrations:sync-reachvolunteering'))
    ->runInBackground();

// Sync EEELabel micro-volunteering rows to the eee-browser labels DB so
// volunteer labels show up on the model-accuracy dashboard. Idempotent
// (upserts) and uses an incremental cursor, so frequent runs are safe.
Schedule::command('eee:sync-mv-labels')
    ->everyTenMinutes()
    ->withoutOverlapping(30)
    ->sendOutputTo(cronLog('eee:sync-mv-labels'))
    ->runInBackground();

// ripple:monitor command exists but is not yet scheduled - pending decision
// on production rollout.  See plans/reference/ripple-curve-evaluation.md.
//
// ripple:tune (§16 self-tuning: rollup + geographic hotspots + advisory param
// proposals) also exists but is left unscheduled for now. It is advisory only
// (nothing it writes changes the engine until a proposal is promoted), and is
// intended to run weekly once the production rollout decision is made.
