<?php

namespace App\Console\Commands\Monitor;

use App\Services\DeprecatedEndpointService;
use App\Services\LokiService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Nightly: for every deprecated apiv2 endpoint whose x-sunset date has passed,
 * check Loki for hits since that sunset. Email geeks@ a "safe to retire" /
 * "still in use (+ who)" report. Sends nothing if no endpoint is past sunset.
 *
 * Retirement stays a human action. To stop nagging about an endpoint we've
 * decided to keep + chase, remove its x-sunset in swagger.json.
 */
class DeprecatedEndpointsCommand extends Command
{
    protected $signature = 'monitor:deprecated-endpoints';

    protected $description = 'Reports which sunset apiv2 endpoints are now unused (retire) or still called (chase)';

    public function handle(DeprecatedEndpointService $catalog, LokiService $loki): int
    {
        $now = Carbon::now();
        $endpoints = $catalog->pastSunset($now);

        if (empty($endpoints)) {
            $this->info('No deprecated endpoints past their sunset date.');

            return self::SUCCESS;
        }

        $retire = [];
        $stillUsed = [];

        foreach ($endpoints as $ep) {
            $sunset = Carbon::parse($ep['sunset'])->startOfDay();
            $logql = sprintf('{source="deprecated_endpoint"} |= "%s"', $ep['logged_endpoint']);
            $hits = $loki->queryRange($logql, $sunset, $now);

            // Keep only hits whose endpoint field exactly matches (|= is a
            // substring filter; guard against one route being a prefix of another).
            $hits = array_values(array_filter($hits, fn ($h) => ($h['endpoint'] ?? null) === $ep['logged_endpoint']));

            $days = max(1, (int) $sunset->diffInDays($now));

            if (count($hits) === 0) {
                $retire[] = sprintf('  %s  (silent %d day%s since sunset %s)',
                    $ep['logged_endpoint'], $days, $days === 1 ? '' : 's', $ep['sunset']);
            } else {
                $stillUsed[] = $this->stillUsedLine($ep, $hits, $days);
            }
        }

        $this->emailReport($retire, $stillUsed);

        return self::SUCCESS;
    }

    /**
     * @param  array{logged_endpoint:string, sunset:string}  $ep
     * @param  array<int, array<string, mixed>>  $hits
     */
    private function stillUsedLine(array $ep, array $hits, int $days): string
    {
        // Top callers by user_agent — the chase-down handle.
        $byAgent = [];
        foreach ($hits as $h) {
            $ua = $h['user_agent'] ?? '(none)';
            $byAgent[$ua] = ($byAgent[$ua] ?? 0) + 1;
        }
        arsort($byAgent);
        $top = [];
        foreach (array_slice($byAgent, 0, 5, true) as $ua => $n) {
            $top[] = "      {$n}x  {$ua}";
        }

        return sprintf(
            "  %s  — %d call%s in %d day%s since sunset %s\n%s",
            $ep['logged_endpoint'],
            count($hits), count($hits) === 1 ? '' : 's',
            $days, $days === 1 ? '' : 's',
            $ep['sunset'],
            implode("\n", $top)
        );
    }

    private function emailReport(array $retire, array $stillUsed): void
    {
        $body = "Deprecated apiv2 endpoints past their sunset date:\n\n";

        $body .= "SAFE TO RETIRE (no calls since sunset):\n";
        $body .= empty($retire) ? "  (none)\n" : implode("\n", $retire)."\n";

        $body .= "\nSTILL IN USE (chase the callers, or remove x-sunset to keep):\n";
        $body .= empty($stillUsed) ? "  (none)\n" : implode("\n", $stillUsed)."\n";

        $to = config('freegle.geeks_addr', 'geeks@ilovefreegle.org');
        Mail::raw($body, function ($message) use ($to) {
            $message->to($to)->subject('Deprecated endpoint report');
        });

        $this->info(sprintf('Report emailed: %d retire, %d still in use.', count($retire), count($stillUsed)));
    }
}
