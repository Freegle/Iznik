<?php

namespace App\Monitoring;

use App\Monitoring\Checks\BacklogCheck;
use App\Monitoring\Checks\FreshnessCheck;
use App\Monitoring\Checks\ProducedSinceCheck;
use Carbon\CarbonInterface;

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

        return [
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

            // queue:background-tasks drains the Go-API -> Laravel task bridge.
            // The failure mode is a stuck worker letting rows pile up, not an
            // empty queue — so assert "no pending task waiting too long".
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
        ];
    }
}
