<?php

namespace App\Console\Commands\Ripple;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Weekly Layer-3 monitoring of the ripple notification algorithm.
 *
 * Shells out to two Go binaries that live in the iznik-routing-go container:
 *
 *   rippleextract  — pulls last 7 days of (post, replier) pairs from live DB
 *   ripplesim      — runs the cached eval cache through the curve sweep and
 *                    emits a structured JSON summary
 *
 * Each result row is upserted into `ripple_algorithm_metrics` keyed by
 * (week, group, curve, ticks, lifetime, max_minutes).  Trend dashboards read
 * from this table; drift alerts are a week-over-week SQL comparison.
 *
 * See plans/reference/ripple-curve-evaluation.md for what the metrics mean
 * and why this is the right way to monitor the algorithm without depending
 * on data the algorithm itself produced.
 */
class MonitorAlgorithmCommand extends Command
{
    protected $signature = 'ripple:monitor
        {--routing-container=spatial-live : routing-go container name (suffix only, the project prefix is added)}
        {--routing-url=http://localhost:8194 : routing server URL as seen from inside the routing container}
        {--ticks=30 : number of cron ticks in the simulated schedule}
        {--lifetime-days=3 : simulated post lifetime}
        {--max-minutes=30 : max drive-time isochrone}
        {--limit=2000 : max posts to sample for the run}
        {--workers=8 : concurrent eval workers}
        {--dry-run : do everything except the DB insert}';

    protected $description = 'Run the ripple-algorithm simulator over the previous week of live data and record results';

    public function handle(): int
    {
        // Monday of the current ISO week — the "week" the metrics belong to.
        $weekStart = Carbon::now()->startOfWeek()->toDateString();
        $this->info("Ripple monitoring run for week starting {$weekStart}");

        // Build the docker exec invocation.  We rely on docker-cli being
        // available in the batch container; alternative is to bake these
        // binaries into iznik-batch itself, but that's a heavier change.
        $project = env('COMPOSE_PROJECT_NAME', 'freegle');
        $container = "{$project}-" . $this->option('routing-container');

        $dataPath  = '/tmp/ripple_monitor_data.json';
        $cachePath = '/tmp/ripple_monitor_cache.json';
        $jsonPath  = '/tmp/ripple_monitor_result.json';

        // ----- 1. extract last 7 days of post/reply data -----
        $this->info('extracting last 7 days...');
        $res = $this->dockerExec($container, [
            '/usr/local/bin/rippleextract',
            '--months=1',
            '--limit=' . $this->option('limit'),
            '--output=' . $dataPath,
        ]);
        if (!$res) {
            $this->error('rippleextract failed');
            return self::FAILURE;
        }

        // ----- 2. force-rebuild the eval cache, then run the simulator -----
        $this->info('running simulator...');
        $res = $this->dockerExec($container, [
            '/usr/local/bin/ripplesim',
            '--data=' . $dataPath,
            '--cache=' . $cachePath,
            '--rebuild-cache',
            '--routing=' . $this->option('routing-url'),
            '--ticks=' . $this->option('ticks'),
            '--post-lifetime-days=' . $this->option('lifetime-days'),
            '--max-minutes=' . $this->option('max-minutes'),
            '--workers=' . $this->option('workers'),
            '--group-by=ru-coarse',
            '--json-output=' . $jsonPath,
        ]);
        if (!$res) {
            $this->error('ripplesim failed');
            return self::FAILURE;
        }

        // ----- 3. parse the JSON the simulator produced -----
        $jsonRaw = $this->dockerExec($container, ['cat', $jsonPath], true);
        if (!$jsonRaw) {
            $this->error('failed to read simulator JSON output');
            return self::FAILURE;
        }
        $summary = json_decode($jsonRaw, true);
        if (!is_array($summary) || !isset($summary['rows'])) {
            $this->error('simulator JSON malformed');
            return self::FAILURE;
        }

        $this->info('rows produced: ' . count($summary['rows']));
        $this->info(sprintf(
            'sample stats: reply-p50=%.1fh reply-p75=%.1fh rank-p50=%.2f drive-p75=%.1fmin posts=%d',
            $summary['reply_p50_hours'] ?? 0,
            $summary['reply_p75_hours'] ?? 0,
            $summary['replier_rank_p50'] ?? 0,
            $summary['replier_drive_min_p75'] ?? 0,
            $summary['posts_evaluated'] ?? 0,
        ));

        if ($this->option('dry-run')) {
            $this->warn('--dry-run set; not writing to ripple_algorithm_metrics');
            return self::SUCCESS;
        }

        // ----- 4. upsert into the metrics table -----
        $now = Carbon::now();
        foreach ($summary['rows'] as $row) {
            DB::table('ripple_algorithm_metrics')->updateOrInsert(
                [
                    'week_start'    => $weekStart,
                    'group'         => $row['group'],
                    'curve'         => $row['curve'],
                    'ticks'         => $summary['ticks'],
                    'lifetime_days' => $summary['lifetime_days'],
                    'max_minutes'   => $summary['max_minutes'],
                ],
                [
                    'pairs_total'           => $row['pairs_total'],
                    'pairs_in_time'         => $row['pairs_in_time'],
                    'pairs_late'            => $row['pairs_late'],
                    'pairs_unreachable'     => $row['pairs_unreachable'],
                    'notify_vol'            => $row['notify_vol'],
                    'reply_p50_hours'       => $summary['reply_p50_hours'] ?? null,
                    'reply_p75_hours'       => $summary['reply_p75_hours'] ?? null,
                    'replier_rank_p50'      => $summary['replier_rank_p50'] ?? null,
                    'replier_drive_min_p75' => $summary['replier_drive_min_p75'] ?? null,
                    'created_at'            => $now,
                ]
            );
        }

        Log::info('ripple:monitor completed', [
            'week_start' => $weekStart,
            'rows'       => count($summary['rows']),
        ]);
        $this->info('done.');
        return self::SUCCESS;
    }

    /**
     * Run a command inside the routing-go container via the host's docker CLI.
     * Returns the trimmed stdout on success, or false on failure.  When
     * $passthrough is true the output is streamed to the console as well.
     */
    private function dockerExec(string $container, array $argv, bool $captureOnly = false): string|false
    {
        $cmd = array_merge(['docker', 'exec', $container], $argv);
        $process = new Process($cmd);
        $process->setTimeout(1800); // 30 min for big extractions
        if ($captureOnly) {
            $process->run();
        } else {
            $process->run(function ($_, $buffer) {
                $this->getOutput()->write($buffer);
            });
        }
        if (!$process->isSuccessful()) {
            $this->error('docker exec failed: ' . implode(' ', $argv));
            return false;
        }
        return trim($process->getOutput());
    }
}
