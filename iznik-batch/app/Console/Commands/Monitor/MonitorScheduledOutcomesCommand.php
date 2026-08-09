<?php

namespace App\Console\Commands\Monitor;

use App\Monitoring\PlatformStatusWriter;
use App\Monitoring\ScheduledOutcomeRegistry;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Outcome-based monitoring for scheduled tasks: asserts that jobs actually did
 * their work (rows written, cursor advanced), not just that the scheduler is
 * alive. Runs every 10 minutes (itself heartbeated to Sentry Crons).
 *
 * For each registered check it prints a line and, on any BREACH, escalates to
 * Sentry (\Sentry\captureMessage, guarded by function_exists exactly like
 * EmailHealthCommand/TNSyncCommand) plus Log::warning, then exits non-zero so
 * the breach shows as a red badge in the cron-jobs sysadmin tab. SKIPPED
 * results (precondition unmet / outside active window) never fail the command.
 *
 * A full pass also publishes its verdict for ModTools' platform status dot (see
 * PlatformStatusWriter). The dot therefore reports exactly what the monitoring
 * decided, and cannot drift away from it, because there is only one evaluation.
 *
 * See docs/scheduled-outcome-monitoring.md.
 */
class MonitorScheduledOutcomesCommand extends Command
{
    protected $signature = 'monitor:scheduled-outcomes {--only= : Evaluate only the check with this slug}';

    protected $description = 'Asserts scheduled tasks did their work; alerts Sentry on outcome breaches';

    public function handle(ScheduledOutcomeRegistry $registry, PlatformStatusWriter $statusWriter): int
    {
        if (! config('freegle.monitoring.enabled', true)) {
            $this->info('Scheduled-outcome monitoring disabled (freegle.monitoring.enabled=false).');

            return Command::SUCCESS;
        }

        $now = Carbon::now();
        $only = $this->option('only');

        $checks = $registry->checks();
        if ($only !== null && $only !== '') {
            $checks = array_values(array_filter($checks, fn ($c) => $c->slug() === $only));
            if (empty($checks)) {
                $this->error("No scheduled-outcome check with slug '{$only}'.");

                return Command::FAILURE;
            }
        }

        $breaches = [];
        $results = [];

        foreach ($checks as $check) {
            $result = $check->evaluate($now);
            $results[] = $result;
            $line = "[{$result->status}] {$check->slug()} — {$result->message}";

            if ($result->isBreach()) {
                $this->error($line);
                $breaches[] = $result;
            } else {
                $this->info($line);
            }
        }

        // Publish for ModTools' status dot — but only on a FULL pass. With
        // --only we have evaluated one check, and writing that would report
        // every other subsystem as fine on no evidence.
        if ($only === null || $only === '') {
            $statusWriter->write($results, $now);
        }

        if (! empty($breaches)) {
            foreach ($breaches as $breach) {
                Log::warning("[ScheduledOutcomes] {$breach->slug}: {$breach->message}");
            }

            // Escalate to Sentry so the team (who actively watch it) are alerted
            // promptly. Guarded because Sentry's helpers aren't loaded in every
            // environment (e.g. tests) — same pattern as EmailHealthCommand.
            if (function_exists('\Sentry\captureMessage')) {
                foreach ($breaches as $breach) {
                    \Sentry\captureMessage(
                        "[ScheduledOutcomes][{$breach->severity}] {$breach->slug}: {$breach->message}"
                    );
                }
            }

            $this->error(count($breaches) . ' scheduled-outcome check(s) breached.');

            return Command::FAILURE;
        }

        $this->info('All scheduled-outcome checks OK.');

        return Command::SUCCESS;
    }
}
