<?php

namespace App\Console\Commands\Monitor;

use App\Services\LokiService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Nightly: checks that members' API requests over the last day went to one
 * apiv2 node, and raises a Sentry error for any hour in which another node
 * served a real share of them.
 *
 * The load balancer sends every request to the active node and uses the others
 * only as backups while it is down. If clients that failed over during a restart
 * are kept on a backup once the active node is back, the backup goes on serving
 * them for hours with no error anywhere: everything still works, but the backup
 * is also the database read node and carries load it should not. That is the
 * situation this looks for.
 *
 * Which node is active is not configured here: the node that served the most
 * requests over the window is taken to be it, so the check survives a change of
 * active node without an edit.
 */
#[AsCommand(name: 'monitor:api-backup-traffic')]
class ApiBackupTrafficCommand extends Command
{
    protected $signature = 'monitor:api-backup-traffic';

    protected $description = 'Raises a Sentry error if API requests were served by a backup apiv2 node while the active one was up';

    // Requests through the load balancer carry the member's address in "ip". The
    // ones without are the nodes' own health checks, which every node gets alike.
    private const LOGQL = 'sum by (hostname) (count_over_time({source="api"} != `"ip":""` [1h]))';

    public function handle(LokiService $loki): int
    {
        $windowHours = (int) config('freegle.api_backup_traffic.window_hours', 24);
        $maxShare = (float) config('freegle.api_backup_traffic.max_hourly_share', 0.10);
        $minRequests = (int) config('freegle.api_backup_traffic.min_hourly_requests', 1000);

        // Each point covers the hour up to its timestamp, so the first point sits
        // an hour after the start of the window.
        $end = Carbon::now()->startOfHour();
        $start = $end->copy()->subHours($windowHours - 1);

        $series = $loki->queryMetricRange(self::LOGQL, $start, $end, 3600);

        if ($series === null) {
            return $this->raise('[ApiBackupTraffic] Could not query Loki for apiv2 request counts, so whether a backup node is serving members is unknown.');
        }

        // hostname => [unix hour => requests]
        $byHost = [];
        foreach ($series as $s) {
            $host = $s['labels']['hostname'] ?? '(unknown)';
            foreach ($s['points'] as $ts => $count) {
                $byHost[$host][$ts] = ($byHost[$host][$ts] ?? 0) + $count;
            }
        }

        $totals = array_map('array_sum', $byHost);
        arsort($totals);
        $active = array_key_first($totals);

        $hours = [];
        foreach ($byHost as $points) {
            foreach ($points as $ts => $count) {
                $hours[$ts] = ($hours[$ts] ?? 0) + $count;
            }
        }
        ksort($hours);

        // hostname => list of [unix hour, share]
        $breaches = [];
        $judged = 0;
        foreach ($hours as $ts => $hourTotal) {
            if ($hourTotal < $minRequests) {
                continue;
            }

            $judged++;
            foreach ($byHost as $host => $points) {
                if ($host === $active) {
                    continue;
                }

                $share = ($points[$ts] ?? 0) / $hourTotal;
                if ($share > $maxShare) {
                    $breaches[$host][] = [$ts, $share];
                }
            }
        }

        if ($judged === 0) {
            // No traffic at all is not "all on one node": the log shipping or the
            // query is broken, and passing would hide exactly that.
            return $this->raise(sprintf(
                '[ApiBackupTraffic] No hour in the last %d had %d or more apiv2 requests in Loki, so the check could not run. Is the API logging to Loki?',
                $windowHours, $minRequests
            ));
        }

        if (! empty($breaches)) {
            $lines = [];
            foreach ($breaches as $host => $list) {
                $peak = max(array_column($list, 1));
                $lines[] = sprintf(
                    '%s served over %d%% of member API requests in %d of the last %d hours (peak %d%%, %s to %s UTC); %s is the active node.',
                    $host,
                    (int) round($maxShare * 100),
                    count($list),
                    $windowHours,
                    (int) round($peak * 100),
                    Carbon::createFromTimestamp($list[0][0] - 3600)->format('D H:i'),
                    Carbon::createFromTimestamp(end($list)[0])->format('D H:i'),
                    $active
                );
            }

            return $this->raise('[ApiBackupTraffic] '.implode(' ', $lines)
                .' A backup should only serve while the active node is down; hours of it mean clients stayed on the backup after a failover. Check the load balancer\'s stick rules for the API backend.');
        }

        $this->info(sprintf(
            'API traffic OK: %s served the members\' requests in all %d judged hours of the last %d (no other node over %d%%).',
            $active, $judged, $windowHours, (int) round($maxShare * 100)
        ));

        return self::SUCCESS;
    }

    private function raise(string $message): int
    {
        $this->error($message);
        Log::warning($message);

        if (app()->bound('sentry')) {
            app('sentry')->captureMessage($message, \Sentry\Severity::error());
        }

        return self::FAILURE;
    }
}
