<?php

namespace App\Console\Commands\Ux;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Mondays: mail the five biggest week-on-week changes in step conversion, click friction and
 * regret, from the ux_* rollup tables. Telemetry that looks broken (health ratio outside 0.7 to
 * 1.3) leads the mail, ahead of any finding, because a drop in events is not a drop in people.
 *
 * It names a change, never a level: no level has an agreed threshold, a change always earns a
 * look. The mail is for a person; nothing acts on it.
 *
 * Nothing is sent when the tables hold no rows for either week.
 */
#[AsCommand(name: 'ux:weekly-report')]
class WeeklyReportCommand extends Command
{
    protected $signature = 'ux:weekly-report';

    protected $description = 'Mail the five largest week-on-week UX changes to geeks@';

    /** A step or component needs at least this many events in BOTH weeks to be compared. */
    private const MIN_EVENTS = 50;

    private const TOP = 5;

    public function handle(): int
    {
        $today = Carbon::now('UTC')->startOfDay();
        $cur = [$today->copy()->subDays(7)->toDateString(), $today->copy()->subDay()->toDateString()];
        $prev = [$today->copy()->subDays(14)->toDateString(), $today->copy()->subDays(8)->toDateString()];

        $unhealthy = DB::table('ux_telemetry_health_daily')
            ->whereBetween('date', $cur)
            ->where(fn ($q) => $q->where('ratio', '<', 0.7)->orWhere('ratio', '>', 1.3))
            ->orderBy('date')->orderBy('metric')->get();

        $changes = array_merge(
            $this->conversionChanges($cur, $prev),
            $this->frictionChanges($cur, $prev),
            $this->regretChanges($cur, $prev),
        );

        $anyRows = DB::table('ux_flow_daily')->whereBetween('date', [$prev[0], $cur[1]])->exists()
            || DB::table('ux_component_daily')->whereBetween('date', [$prev[0], $cur[1]])->exists()
            || DB::table('ux_regret_daily')->whereBetween('date', [$prev[0], $cur[1]])->exists()
            || DB::table('ux_telemetry_health_daily')->whereBetween('date', [$prev[0], $cur[1]])->exists();
        if (! $anyRows) {
            $this->info('No UX rollup rows for the last two weeks; nothing to report.');

            return self::SUCCESS;
        }

        usort($changes, fn ($a, $b) => abs($b['delta']) <=> abs($a['delta']));
        $changes = array_slice($changes, 0, self::TOP);

        $to = config('freegle.ux.report_to') ?: config('freegle.geeks_addr', 'geeks@ilovefreegle.org');
        $body = $this->compose($cur, $prev, $unhealthy, $changes);
        Mail::raw($body, function ($message) use ($to, $cur) {
            $message->to($to)->subject('UX weekly report: week ending '.$cur[1]);
        });

        $this->info(sprintf('UX report emailed: %d telemetry problem(s), %d change(s).', count($unhealthy), count($changes)));

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{kind: string, text: string, delta: float}>
     */
    private function conversionChanges(array $cur, array $prev): array
    {
        $rates = function (array $range) {
            $out = [];
            $rows = DB::table('ux_flow_daily')->whereBetween('date', $range)->get(['flow', 'step', 'entered', 'reentered', 'completed']);
            foreach ($rows->groupBy(fn ($r) => $r->flow.'/'.$r->step) as $key => $group) {
                // A step is completed once per visit, so the rate is over visits, not first visits.
                $out[$key] = [(int) ($group->sum('entered') + $group->sum('reentered')), (int) $group->sum('completed')];
            }

            return $out;
        };
        $now = $rates($cur);
        $before = $rates($prev);

        $out = [];
        foreach ($now as $key => [$visits, $completed]) {
            if (! isset($before[$key]) || $visits < self::MIN_EVENTS || $before[$key][0] < self::MIN_EVENTS) {
                continue;
            }
            $a = $before[$key][1] / $before[$key][0];
            $b = $completed / $visits;
            $out[] = [
                'kind' => 'Step conversion',
                'text' => sprintf('%s: %s (%d visits, was %d)', $key, $this->move($a, $b), $visits, $before[$key][0]),
                'delta' => $b - $a,
            ];
        }

        return $out;
    }

    private function frictionChanges(array $cur, array $prev): array
    {
        $rates = function (array $range) {
            $out = [];
            $rows = DB::table('ux_component_daily')->whereBetween('date', $range)
                ->get(['page', 'component', 'clicks', 'rage_clicks', 'dead_clicks', 'repeated_intent']);
            foreach ($rows->groupBy(fn ($r) => $r->page.' / '.$r->component) as $key => $group) {
                $out[$key] = [(int) $group->sum('clicks'), (int) ($group->sum('rage_clicks') + $group->sum('dead_clicks') + $group->sum('repeated_intent'))];
            }

            return $out;
        };
        $now = $rates($cur);
        $before = $rates($prev);

        $out = [];
        foreach ($now as $key => [$clicks, $bad]) {
            if (! isset($before[$key]) || $clicks < self::MIN_EVENTS || $before[$key][0] < self::MIN_EVENTS) {
                continue;
            }
            $a = $before[$key][1] / $before[$key][0];
            $b = $bad / $clicks;
            $out[] = [
                'kind' => 'Click friction (rage, dead, repeated)',
                'text' => sprintf('%s: %s of clicks (%d clicks, was %d)', $key, $this->move($a, $b), $clicks, $before[$key][0]),
                'delta' => $b - $a,
            ];
        }

        return $out;
    }

    /**
     * Regret rates get the same thin-data guard as the others: a base under 50 swings wildly.
     */
    private function regretChanges(array $cur, array $prev): array
    {
        $rates = function (array $range) {
            $out = [];
            $rows = DB::table('ux_regret_daily')->whereBetween('date', $range)->get(['signal', 'count', 'base']);
            foreach ($rows->groupBy('signal') as $key => $group) {
                $out[$key] = [(int) $group->sum('base'), (int) $group->sum('count')];
            }

            return $out;
        };
        $now = $rates($cur);
        $before = $rates($prev);

        $out = [];
        foreach ($now as $key => [$base, $count]) {
            if (! isset($before[$key]) || $base < self::MIN_EVENTS || $before[$key][0] < self::MIN_EVENTS) {
                continue;
            }
            $a = $before[$key][1] / $before[$key][0];
            $b = $count / $base;
            $out[] = [
                'kind' => 'Regret',
                'text' => sprintf('%s: %s (base %d, was %d)', $key, $this->move($a, $b), $base, $before[$key][0]),
                'delta' => $b - $a,
            ];
        }

        return $out;
    }

    private function move(float $from, float $to): string
    {
        return sprintf('%.1f%% to %.1f%%', $from * 100, $to * 100);
    }

    private function compose(array $cur, array $prev, $unhealthy, array $changes): string
    {
        $body = sprintf("UX weekly report: %s to %s, against %s to %s.\n\n", $cur[0], $cur[1], $prev[0], $prev[1]);

        if (count($unhealthy)) {
            $body .= "TELEMETRY LOOKS BROKEN. Read this before anything below; a drop in events is not a drop in people.\n";
            foreach ($unhealthy as $h) {
                $body .= sprintf("  %s on %s: observed %s, expected %s (ratio %s)\n", $h->metric, $h->date,
                    rtrim(rtrim((string) $h->observed, '0'), '.'), $h->expected === null ? 'n/a' : rtrim(rtrim((string) $h->expected, '0'), '.'), $h->ratio);
            }
            $body .= "\n";
        }

        $body .= "FIVE LARGEST CHANGES (percentage points, steps and components with fewer than ".self::MIN_EVENTS." events in either week left out):\n";
        if (! $changes) {
            $body .= "  (none comparable)\n";
        }
        foreach ($changes as $i => $c) {
            $body .= sprintf("  %d. %s - %s\n", $i + 1, $c['kind'], $c['text']);
        }

        $body .= "\nSee the UX sub-tab of ModTools SysAdmin: ".rtrim((string) config('freegle.sites.mod', 'https://modtools.org'), '/')."/sysadmin?tab=ux\n";

        return $body;
    }
}
