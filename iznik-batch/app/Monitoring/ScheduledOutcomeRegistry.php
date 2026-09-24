<?php

namespace App\Monitoring;

use App\Models\MessageGroup;
use App\Monitoring\Checks\BacklogCheck;
use App\Monitoring\Checks\CallbackCheck;
use App\Monitoring\Checks\FreshnessCheck;
use App\Monitoring\Checks\HostHealthCheck;
use App\Monitoring\Checks\ProducedSinceCheck;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Declares which scheduled tasks are monitored, and how. This is the single
 * place reviewed alongside routes/console.php. See
 * docs/scheduled-outcome-monitoring.md for the full categorisation of every
 * task (including those deliberately left unmonitored and why).
 *
 * Add an entry only after verifying its table/column against the command's
 * service code and a migration — ScheduledOutcomeRegistryTest evaluates every
 * check against the real schema, so a typo fails CI rather than production.
 */
class ScheduledOutcomeRegistry
{
    /**
     * @return list<OutcomeCheck>
     */
    public function checks(): array
    {
        $tz = config('freegle.timezone', 'Europe/London');
        $backlogMin = (int) config('freegle.monitoring.processing_backlog_max_age_minutes', 15);

        return array_merge([
            // ---- Fire-once: did it produce output this period? -------------

            // stats:generate-daily (daily 02:30) writes one `stats` row per
            // group/type for YESTERDAY. Strong fire-once signal. Only checked
            // from 06:00 so the 02:30 run has comfortably completed.
            (new ProducedSinceCheck(
                'stats:generate-daily',
                'stats',
                'date',
                fn (CarbonInterface $now) => $now->copy()->setTimezone($tz)->startOfDay()->subDay(),
                (int) config('freegle.monitoring.stats_daily_min_expected', 1),
            ))
                ->describedAs('Per-group daily stats rows generated for yesterday')
                ->inCategory('fire-once-output')
                ->activeBetween(6, 24, $tz),

            // The check above has a floor of 1, so it only says the 02:30 run started. That is
            // the same shape that let the daily digest collapse for three days while its own
            // check passed (see the digest window check below). Here the floor needs no
            // guessing at all: stats:generate-daily writes for EVERY group, and over
            // 2026-09-07..16 coverage was 507 of 507 on all ten days without exception, while
            // the raw row count wandered between 4,227 and 4,395 with activity. So assert
            // coverage rather than volume, and take the expected number from the groups table
            // at check time - it then tracks communities being added or retired by itself.
            (new CallbackCheck(
                'stats:generate-daily coverage',
                function (CarbonInterface $now) use ($tz) {
                    $slug = 'stats:generate-daily coverage';
                    $day = $now->copy()->setTimezone($tz)->startOfDay()->subDay()->toDateString();

                    // Groups founded AFTER the day in question have no stats for it and must
                    // not count against coverage. founded is NULL on a handful of the oldest
                    // groups, which long pre-date any day this could check.
                    $expected = DB::table('groups')
                        ->where(function ($q) use ($day) {
                            $q->whereNull('founded')->orWhere('founded', '<', $day);
                        })
                        ->count();

                    if ($expected === 0) {
                        return OutcomeResult::skipped($slug, 'no groups existed on '.$day);
                    }

                    $covered = DB::table('stats')
                        ->where('date', $day)
                        ->distinct()
                        ->count('groupid');

                    if ($covered < $expected) {
                        $missing = $expected - $covered;

                        return OutcomeResult::breach(
                            $slug,
                            "daily stats cover {$covered} of {$expected} communities for {$day} - "
                            ."{$missing} missing. The 02:30 run did not get through them all."
                        );
                    }

                    return OutcomeResult::ok(
                        $slug,
                        "daily stats cover all {$expected} communities for {$day}"
                    );
                }
            ))
                ->describedAs('Every community got daily stats for yesterday')
                ->inCategory('fire-once-output')
                ->activeBetween(6, 24, $tz),

            // mail:digest:unified --mode=daily (07:00-12:00 London) records a
            // users_digests.lastsent for mode='daily' on each send. Inert
            // (skipped) until the daily pilot is enabled via the allowlist.
            // Checked from 13:00, after the send window + slack.
            (new ProducedSinceCheck(
                'mail:digest:unified --mode=daily',
                'users_digests',
                'lastsent',
                fn (CarbonInterface $now) => $now->copy()->setTimezone($tz)->startOfDay()->setTimezone('UTC'),
                (int) config('freegle.monitoring.digest_daily_min_expected', 1),
                fn ($q) => $q->where('mode', 'daily'),
            ))
                ->describedAs("Daily 'What's New' digest sent today")
                ->inCategory('fire-once-output')
                ->enabledWhen(fn () => trim((string) config('freegle.digest.daily_allowlist', '')) !== '')
                ->activeBetween(13, 24, $tz),

            // tn:sync (every minute) ingests TrashNothing posts into `messages`,
            // stamping messages.tnpostid. TN is a high-volume feed, so a window
            // with NO post at all means either TN is down/returning nothing or
            // our ingestion is failing every cycle — the two cases this alert
            // exists for. TNSyncCommand::alertIfSyncStale() covers the same
            // ground from the inside, but only fires if the command completes a
            // run; this check fires from a separate command, so it still alerts
            // when tn:sync is crashing in a loop, wedged on its lock, or not
            // being scheduled at all.
            //
            // ProducedSinceCheck (not FreshnessCheck) so the query is bounded:
            // `messages` is huge and `WHERE tnpostid IS NOT NULL` matches years
            // of rows, so a MAX(arrival) over it is expensive. Counting inside
            // the window lets the (arrival, ...) index do the work.
            //
            // Gated on the API-ingestion flag: while it is off, posts arrive via
            // the V1 email path, which this app does not own.
            (new ProducedSinceCheck(
                'tn:sync (posts)',
                'messages',
                'arrival',
                fn (CarbonInterface $now) => $now->copy()->subHours(
                    (int) config('freegle.monitoring.tn_posts_max_age_hours', 6)
                ),
                (int) config('freegle.monitoring.tn_posts_min_expected', 1),
                fn ($q) => $q->whereNotNull('tnpostid'),
            ))
                ->describedAs('TrashNothing posts still being ingested from the TN API')
                ->inCategory('cursor-staleness'),
            // The check above asks only "did ANY daily digest go out today?", floor 1. That is
            // a liveness check, and a liveness check cannot see a collapse. On 2026-09-15..17
            // the daily run fell to a thirteenth of its throughput and ran around the clock -
            // members got their "morning" digest at 01:00 - and it passed all three days,
            // because 40,000+ still went out. Sentry could not see it either: nothing threw,
            // and windowed/overlapping jobs are deliberately excluded from sentryMonitor()
            // (see routes/console.php).
            //
            // What went wrong IS visible, in the same column the check above already reads:
            // the run stopped fitting in its window. mail:digest:unified --mode=daily is
            // scheduled ->between('7:00','12:00') London, so on a healthy day the last send
            // lands just inside 12:00 and nothing follows it. 09-13 and 09-14 both ended at
            // 11:59 London; 09-15 ended at 00:59 the next morning.
            //
            // Deliberately NOT a volume floor. A floor would be a number guessed off one
            // week's traffic, and it would have to be loose enough to survive a quiet day,
            // which makes it too loose to catch a 2x slowdown. The window comes from the
            // schedule itself and needs no tuning: either the run finished inside it or it
            // did not.
            $this->finishedInsideWindow(
                'mail:digest:unified --mode=daily window',
                'Daily digest finished inside its send window',
                'users_digests',
                'lastsent',
                7,
                12,
                'members were sent one',
                fn ($q) => $q->where('mode', 'daily'),
                'Check throughput before members start getting these overnight.'
            )
                ->enabledWhen(fn () => trim((string) config('freegle.digest.daily_allowlist', '')) !== ''),

            // push:daily-posts trails the digest by 30 minutes and reads the SAME
            // getPostsForUser(), so it shares the failure mode exactly: if that query gets
            // slow again, this overruns too and members get push notifications at odd hours.
            // Its cursor is its own row (mode='push').
            $this->finishedInsideWindow(
                'push:daily-posts window',
                'Daily posts push finished inside its send window',
                'users_digests',
                'lastsent',
                7,
                12,
                'members were pushed one',
                fn ($q) => $q->where('mode', 'push'),
                'It reads the same query as the daily digest - check that first.'
            )
                ->enabledWhen(fn () => trim((string) config('freegle.posts_push_allowlist', '')) !== ''),

            // ---- Cursor/queue: is a worker stuck (backlog piling up)? -------

            // queue:background-tasks drains the Go-API -> Laravel task bridge.
            (new BacklogCheck(
                'queue:background-tasks',
                'background_tasks',
                'created_at',
                (int) config('freegle.monitoring.background_tasks_max_age_minutes', 10),
                fn ($q) => $q->whereNull('processed_at')->whereNull('failed_at')->where('attempts', '<', 3),
                (int) config('freegle.monitoring.background_tasks_backlog_threshold', 0),
            ))
                ->describedAs('Go-API background task queue not backing up')
                ->inCategory('cursor-staleness'),

            // ripple:expand (every minute) advances rippling_reach rows whose
            // next_expansion_at has come due. The failure mode this asserts
            // against is real and went unnoticed for days (2026-08-31): the
            // expander wedged (run stacking, engine swap-thrash) while posts
            // kept arriving, and ~10k rows sat overdue with nothing alarming.
            // Healthy operation never leaves a row a full DAY past due — the
            // deliberate overnight pause plus the morning catch-up peaks far
            // under that — so day-late rows mean the engine or its run lock is
            // stalled, not scheduling jitter. The threshold rides over a
            // handful of individually-wedged stragglers without masking a
            // pipeline stall.
            (new BacklogCheck(
                'ripple:expand',
                'rippling_reach',
                'next_expansion_at',
                (int) config('freegle.monitoring.ripple_backlog_max_age_minutes', 1440),
                fn ($q) => $q->where('status', 'expanding'),
                (int) config('freegle.monitoring.ripple_backlog_threshold', 50),
            ))
                ->describedAs('Ripple expansion backlog not silently rotting')
                ->inCategory('cursor-staleness')
                ->enabledWhen(fn () => (bool) config('freegle.ripple.enabled')),

            // messages:contentcheck (every minute) promotes/blocks Pending
            // posts and always stamps contentcheck_checked_at. A Pending,
            // un-checked, undeleted post whose message+user are live (mirrors
            // the worker's exact join) that has sat past $backlogMin = a stalled
            // moderation pipeline. The predicates exclude rows the worker skips
            // (deleted message / null fromuser / held-by-a-mod) so they don't
            // false-alarm: a held post is deliberately pulled back for review and
            // is never checked until a mod releases it, so it can sit indefinitely.
            (new BacklogCheck(
                'messages:contentcheck',
                'messages_groups as mg',
                'mg.arrival',
                $backlogMin,
                fn ($q) => $q
                    ->join('messages as m', 'm.id', '=', 'mg.msgid')
                    ->join('users as u', 'u.id', '=', 'm.fromuser')
                    ->where('mg.collection', MessageGroup::COLLECTION_PENDING)
                    ->whereNull('mg.contentcheck_checked_at')
                    ->where('mg.deleted', 0)
                    ->whereNull('mg.heldby')
                    ->whereNull('m.deleted')
                    ->whereNotNull('m.fromuser')
                    ->whereNull('u.deleted'),
            ))
                ->describedAs('Content-check moderation queue not backing up')
                ->inCategory('cursor-staleness'),

            // chats:process-incoming (every minute) clears processingrequired
            // on every visited chat message (success or fail). A row still
            // flagged past $backlogMin = the chat processor is stuck.
            (new BacklogCheck(
                'chats:process-incoming',
                'chat_messages',
                'date',
                $backlogMin,
                fn ($q) => $q->where('processingrequired', 1),
            ))
                ->describedAs('Incoming chat-message processing queue not backing up')
                ->inCategory('cursor-staleness'),

            // memberships:process (every minute) clears processingrequired on
            // every history row it visits. A row still flagged past $backlogMin
            // = welcome-mail / review processing is stuck.
            (new BacklogCheck(
                'memberships:process',
                'memberships_history',
                'added',
                $backlogMin,
                fn ($q) => $q->where('processingrequired', 1),
            ))
                ->describedAs('Membership-history processing queue not backing up')
                ->inCategory('cursor-staleness'),

            // users:process-exports (every minute) sets completed when a GDPR
            // export finishes. An export requested but uncompleted past its
            // (larger) window = the export worker is stuck. Exports are rare,
            // so an empty queue is the norm — a backlog check only fires when
            // one genuinely sits unprocessed.
            (new BacklogCheck(
                'users:process-exports',
                'users_exports',
                'requested',
                (int) config('freegle.monitoring.exports_backlog_max_age_minutes', 30),
                fn ($q) => $q->whereNull('completed'),
            ))
                ->describedAs('GDPR data-export queue not backing up')
                ->inCategory('cursor-staleness'),

            // spam:refresh-mobile-cidrs (monthly) upserts spam_whitelist_ips
            // rows commented 'UK mobile: ...'. A 40-day staleness floor
            // tolerates the monthly cadence.
            (new FreshnessCheck(
                'spam:refresh-mobile-cidrs',
                'spam_whitelist_ips',
                'date',
                (int) config('freegle.monitoring.mobile_cidrs_max_age_days', 40) * 24 * 60,
                fn ($q) => $q->where('comment', 'like', 'UK mobile:%'),
            ))
                ->describedAs('Monthly UK-mobile CGNAT CIDR refresh')
                ->inCategory('fire-once-output'),

            // integrations:sync-whatjobs rebuilds the `jobs` table (every 3h,
            // 08:00-22:00) and stamps jobs.seenat on every row of the run. A
            // 24h freshness floor tolerates the overnight gap + slow cold runs.
            // Gated on a feed being configured (no feed = nothing to sync).
            (new FreshnessCheck(
                'integrations:sync-whatjobs',
                'jobs',
                'seenat',
                (int) config('freegle.monitoring.whatjobs_max_age_hours', 24) * 60,
            ))
                ->describedAs('WhatJobs feed rebuild of the jobs table')
                ->inCategory('cursor-staleness')
                ->enabledWhen(fn () => trim((string) config('freegle.whatjobs.feed1', '')) !== ''),

            // ---- Config-value freshness (timestamp embedded in config value) -

            // data:git-summary (weekly Wed 18:00) writes a unix timestamp into
            // config.value['git_summary_last_run'] after each successful send.
            $this->configFreshness(
                'data:git-summary',
                'git_summary_last_run',
                (int) config('freegle.monitoring.git_summary_max_age_days', 10) * 24 * 60,
                fn (string $raw) => is_numeric(trim($raw)) ? Carbon::createFromTimestamp((int) trim($raw)) : null,
            )
                ->describedAs('Weekly AI git-summary to Discourse')
                ->inCategory('fire-once-output'),

            // data:update-cpi (monthly) stores ONS data as JSON in
            // config.value['cpi_annual_data'] with an ISO-8601 'updated_at'.
            $this->configFreshness(
                'data:update-cpi',
                'cpi_annual_data',
                (int) config('freegle.monitoring.cpi_max_age_days', 40) * 24 * 60,
                function (string $raw) {
                    $decoded = json_decode($raw, true);

                    return is_array($decoded) && ! empty($decoded['updated_at'])
                        ? Carbon::parse($decoded['updated_at'])
                        : null;
                },
            )
                ->describedAs('Monthly ONS CPI inflation data fetch')
                ->inCategory('fire-once-output'),
        ], $this->hostHealthChecks());
    }

    /**
     * Host-level OS/service checks (see HostHealthCheck), one per configured
     * ssh target. The estate's topology deliberately lives ONLY in the
     * environment (FREEGLE_MONITORING_HOSTS in the uncommitted
     * .env.background), never in committed code; an empty list — dev, CI —
     * yields no checks at all.
     *
     * @return list<HostHealthCheck>
     */
    private function hostHealthChecks(): array
    {
        $targets = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('freegle.monitoring.hosts', ''))
        )));

        if (empty($targets)) {
            return [];
        }

        $runner = app(HostCommandRunner::class);

        return array_map(
            fn (string $target) => new HostHealthCheck($target, $runner),
            $targets,
        );
    }

    /**
     * Build a check that reads a timestamp embedded inside a `config` row's
     * value (the config table has no updated_at column of its own).
     *
     * A missing key is SKIPPED, not a breach — on a fresh deploy the job may
     * simply not have run yet, and we don't want to alarm on that. An
     * unparseable value IS a breach (the writer is broken).
     *
     * @param  callable(string):(CarbonInterface|null)  $extractTimestamp
     */
    private function configFreshness(string $slug, string $key, int $maxAgeMinutes, callable $extractTimestamp): CallbackCheck
    {
        return new CallbackCheck($slug, function (CarbonInterface $now) use ($slug, $key, $maxAgeMinutes, $extractTimestamp) {
            $raw = DB::table('config')->where('key', $key)->value('value');

            if ($raw === null) {
                return OutcomeResult::skipped($slug, "config '{$key}' not set yet (job may not have run since deploy)");
            }

            $timestamp = $extractTimestamp((string) $raw);
            if ($timestamp === null) {
                return OutcomeResult::breach($slug, "config '{$key}' present but its timestamp could not be parsed");
            }

            $ageMinutes = (int) round(($now->getTimestamp() - $timestamp->getTimestamp()) / 60);

            if ($timestamp->lt($now->copy()->subMinutes($maxAgeMinutes))) {
                return OutcomeResult::breach(
                    $slug,
                    "config '{$key}' last updated {$ageMinutes} min ago (max {$maxAgeMinutes}) — did it run?"
                );
            }

            return OutcomeResult::ok($slug, "config '{$key}' fresh ({$ageMinutes} min ago, max {$maxAgeMinutes})");
        });
    }

    /**
     * A job scheduled inside a window must FINISH inside it.
     *
     * The companion ProducedSinceCheck on such a job only asks whether it ran at all. That is
     * what let the daily digest collapse for three days in September 2026 while its own check
     * passed on 40,000+ sends a day: throughput fell 13x, the run went round the clock, and
     * members got their "morning" digest at 01:00.
     *
     * The assertion needs no tuning and no guessed figure. The job is scheduled
     * ->between(open, close), so on a good day its last output lands inside `close` and nothing
     * follows. Anything produced after that means the run did not fit.
     *
     * Deliberately NOT a volume floor. A floor is a number guessed off one week's traffic, it
     * has to be low enough to survive a quiet day, and that makes it too low to catch a run
     * going twice as slow. The window comes from the schedule itself.
     *
     * @param  string  $slug  check name; convention is "<command> window"
     * @param  string  $description  one line for the status dot
     * @param  string  $table  where the job records what it produced
     * @param  string  $column  the timestamp column on that table (UTC)
     * @param  int  $closeHour  the window's closing hour, LOCAL time
     * @param  string  $what  plural noun for the message, e.g. "members"
     * @param  callable|null  $where  extra narrowing, e.g. fn ($q) => $q->where('mode', 'daily')
     * @param  string  $advice  what the reader should do about it
     */
    private function finishedInsideWindow(
        string $slug,
        string $description,
        string $table,
        string $column,
        int $openHour,
        int $closeHour,
        string $what,
        ?callable $where = null,
        string $advice = 'The run is not keeping up.',
    ): CallbackCheck {
        $tz = config('freegle.timezone', 'Europe/London');

        return (new CallbackCheck($slug, function (CarbonInterface $now) use (
            $slug, $table, $column, $openHour, $closeHour, $what, $where, $advice, $tz
        ) {
            $close = $now->copy()->setTimezone($tz)
                ->startOfDay()->setTime($closeHour, 0)->setTimezone('UTC');

            // Many of these tables hold only the MOST RECENT row per subject, so comparing
            // against today's close counts subjects served after it shut - not historical
            // stragglers, whose timestamp is an earlier day and falls below the cut.
            $q = DB::table($table)->where($column, '>=', $close);
            if ($where !== null) {
                $where($q);
            }

            $late = (clone $q)->count();
            if ($late === 0) {
                return OutcomeResult::ok(
                    $slug,
                    sprintf('finished inside its %02d:00-%02d:00 window', $openHour, $closeHour)
                );
            }

            return OutcomeResult::breach($slug, sprintf(
                'overran its %02d:00-%02d:00 window: %d %s served after it closed (latest %s UTC). %s',
                $openHour,
                $closeHour,
                $late,
                $what,
                (clone $q)->max($column),
                $advice
            ));
        }))
            ->describedAs($description)
            ->inCategory('fire-once-output')
            ->activeBetween($closeHour + 1, 24, $tz);
    }
}
