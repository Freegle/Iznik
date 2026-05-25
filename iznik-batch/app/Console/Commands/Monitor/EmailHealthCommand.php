<?php

namespace App\Console\Commands\Monitor;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Monitors incoming and outgoing email health.
 *
 * Runs 24/7. There are two tiers of check:
 *
 * - A hard outgoing-STALL check runs at ALL hours: Freegle sends chat
 *   notifications continuously, so ZERO outgoing across the stall window is a
 *   near-certain smarthost/spooler stall at any hour. This is what catches a
 *   night-time smarthost outage that would otherwise go unnoticed until morning.
 * - The incoming check and the outgoing VOLUME floor stay gated to daytime
 *   (07:00-22:00) to avoid false alarms from merely-low night-time volume.
 *
 * Returns FAILURE if:
 * - Zero outgoing emails (email_tracking sent) in the stall window (any hour)
 * - Zero incoming emails (platform=0 chat messages) in the last N hours during daytime
 * - Fewer than N outgoing emails (email_tracking sent) in the last hour during daytime
 *
 * On failure, escalates to Sentry (the team actively watches Sentry) so a
 * smarthost-down outage is noticed promptly rather than only being buried in
 * a Log::warning. Thresholds are configurable via .env. Failures also show as
 * red badges in the cron jobs sysadmin tab.
 */
class EmailHealthCommand extends Command
{
    protected $signature = 'monitor:email-health';

    protected $description = 'Checks incoming and outgoing email flow and fails if thresholds are breached';

    public function handle(): int
    {
        $now = Carbon::now();
        $hour = $now->hour;

        $dayStart = (int) config('freegle.email_health.daytime_start', 7);
        $dayEnd = (int) config('freegle.email_health.daytime_end', 22);

        // Whether we're inside the daytime window. We DON'T early-return at
        // night: the hard outgoing-stall check below runs 24/7 so a night-time
        // smarthost/spooler stall escalates to Sentry immediately.
        $isDaytime = $hour >= $dayStart && $hour < $dayEnd;

        $failures = [];

        // --- Outgoing STALL check (runs 24/7) ---
        // Freegle sends chat notifications continuously, so zero outgoing
        // across the stall window is a near-certain smarthost/spooler stall at
        // any hour. This is the only check that runs at night, so it never
        // false-alarms on merely-low night-time volume — it requires ZERO.
        $stallWindowHours = (int) config('freegle.email_health.outgoing_stall_window_hours', 1);

        $stallCount = DB::table('email_tracking')
            ->where('sent_at', '>=', $now->copy()->subHours($stallWindowHours))
            ->count();

        if ($stallCount === 0) {
            $failures[] = "OUTGOING STALL: 0 emails sent in the last {$stallWindowHours}h";
        }

        $this->info("Outgoing stall window: {$stallCount} emails in last {$stallWindowHours}h");

        if ($isDaytime) {
            // --- Incoming email check (daytime only) ---
            $incomingWindowHours = (int) config('freegle.email_health.incoming_window_hours', 2);

            $incomingCount = DB::table('chat_messages')
                ->where('platform', 0)
                ->where('date', '>=', $now->copy()->subHours($incomingWindowHours))
                ->count();

            if ($incomingCount === 0) {
                $failures[] = "INCOMING: 0 emails received in the last {$incomingWindowHours} hours";
            }

            $this->info("Incoming: {$incomingCount} emails in last {$incomingWindowHours}h");

            // --- Outgoing VOLUME floor (daytime only) ---
            // Gated to daytime so merely-low night-time volume doesn't raise a
            // false alarm; the 24/7 stall check above still catches a true stall.
            $outgoingMinPerHour = (int) config('freegle.email_health.outgoing_min_per_hour', 10);

            $outgoingCount = DB::table('email_tracking')
                ->where('sent_at', '>=', $now->copy()->subHour())
                ->count();

            if ($outgoingCount < $outgoingMinPerHour) {
                $failures[] = "OUTGOING: {$outgoingCount} emails sent in last hour (threshold: {$outgoingMinPerHour})";
            }

            $this->info("Outgoing: {$outgoingCount} emails in last 1h (min: {$outgoingMinPerHour})");
        } else {
            $this->info("Outside daytime window ({$dayStart}:00-{$dayEnd}:00); running stall check only.");
        }

        // --- Result ---
        if (! empty($failures)) {
            foreach ($failures as $f) {
                $this->error($f);
                Log::warning("[EmailHealth] {$f}");
            }

            // Escalate to Sentry so the team (who actively watch Sentry) are
            // alerted promptly — a night-time outage would otherwise only sit
            // in a Log::warning. function_exists guard matches the codebase
            // pattern in TNSyncCommand.php (Sentry's helpers aren't loaded in
            // every environment, e.g. tests).
            if (function_exists('\Sentry\captureMessage')) {
                \Sentry\captureMessage('[EmailHealth] ' . implode('; ', $failures));
            }

            return Command::FAILURE;
        }

        $this->info('Email health OK.');

        return Command::SUCCESS;
    }
}
