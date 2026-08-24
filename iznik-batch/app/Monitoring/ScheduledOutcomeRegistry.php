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

                    return is_array($decoded) && !empty($decoded['updated_at'])
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
}
