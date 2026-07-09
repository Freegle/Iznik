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

        if ($endpoints === null) {
            // Spec unreachable/misconfigured — surface it (red cron badge, non-zero
            // exit) rather than silently behaving like "nothing is deprecated".
            $this->warn('Could not fetch the apiv2 OpenAPI spec ('.config('freegle.apiv2_swagger_url').'); cannot report on deprecated endpoints. Check APIV2_SWAGGER_URL.');

            return self::FAILURE;
        }

        if (count($endpoints) === 0) {
            $this->info('No deprecated endpoints past their sunset date.');

            return self::SUCCESS;
        }

        // Loki caps a single query at max_query_length (~30d), so we observe at most
        // this many days back — enough post-sunset silence to call an endpoint dead.
        $windowDays = (int) config('freegle.deprecated_endpoints.observation_window_days', 29);

        $retire = [];
        $stillUsed = [];
        $couldNotCheck = [];

        foreach ($endpoints as $ep) {
            $sunset = Carbon::parse($ep['sunset'])->startOfDay();
            // Query from the sunset date, but never further back than $windowDays
            // (a longer range 400s in Loki). For endpoints long past sunset this
            // observes the trailing window rather than the whole since-sunset span.
            $windowStart = $sunset->copy()->max($now->copy()->subDays($windowDays));

            // endpoint is NOT a promoted Loki label — Alloy keeps it in the JSON
            // message body (verified in-container). So parse the message with | json
            // and match the field exactly. A stream selector {endpoint="..."} would
            // match NOTHING, making every endpoint look unused → catastrophic false
            // "safe to retire". The exact | json match also avoids |= prefix collisions.
            $selector = str_replace(['\\', '"'], ['\\\\', '\\"'], $ep['logged_endpoint']);
            $logql = sprintf('{source="deprecated_endpoint"} | json | endpoint="%s"', $selector);
            $hits = $loki->queryRange($logql, $windowStart, $now);

            if ($hits === null) {
                // The Loki query itself failed — do NOT conclude "safe to retire"
                // (deleting a live endpoint is the dangerous mistake). Flag it.
                $couldNotCheck[] = sprintf('  %s  (Loki query failed — status unknown, NOT safe to retire)', $ep['logged_endpoint']);

                continue;
            }

            $observed = max(1, (int) $windowStart->diffInDays($now));

            if (count($hits) === 0) {
                $retire[] = sprintf('  %s  (no calls in %d day%s observed; sunset %s)',
                    $ep['logged_endpoint'], $observed, $observed === 1 ? '' : 's', $ep['sunset']);
            } else {
                $stillUsed[] = $this->stillUsedLine($ep, $hits, $observed);
            }
        }

        $this->emailReport($retire, $stillUsed, $couldNotCheck);

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

    private function emailReport(array $retire, array $stillUsed, array $couldNotCheck = []): void
    {
        $body = "Deprecated apiv2 endpoints past their sunset date:\n\n";

        $body .= "SAFE TO RETIRE (no calls in the observed window):\n";
        $body .= empty($retire) ? "  (none)\n" : implode("\n", $retire)."\n";

        $body .= "\nSTILL IN USE (chase the callers, or remove x-sunset to keep):\n";
        $body .= empty($stillUsed) ? "  (none)\n" : implode("\n", $stillUsed)."\n";

        if (! empty($couldNotCheck)) {
            $body .= "\nCOULD NOT CHECK (Loki query failed — investigate, do NOT retire):\n";
            $body .= implode("\n", $couldNotCheck)."\n";
        }

        $to = config('freegle.geeks_addr', 'geeks@ilovefreegle.org');
        Mail::raw($body, function ($message) use ($to) {
            $message->to($to)->subject('Deprecated endpoint report');
        });

        $this->info(sprintf('Report emailed: %d retire, %d still in use, %d could not check.',
            count($retire), count($stillUsed), count($couldNotCheck)));
    }
}
