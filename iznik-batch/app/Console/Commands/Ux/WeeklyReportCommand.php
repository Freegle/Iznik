<?php

namespace App\Console\Commands\Ux;

use App\Services\LokiService;
use App\Services\Ux\UxRollupService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Mondays: mail the five week-on-week changes in step conversion, click friction and regret that
 * touched the most events, from the ux_* rollup tables, so a person knows where to look and what to
 * look at. Nothing acts on it.
 *
 * - Telemetry that changed (health ratio outside 0.7 to 1.3) leads the mail, and says whether events
 *   rose or fell, because a change in events is not a change in people.
 * - A device class whose clicks moved by half or more is named next: a new app release that starts
 *   sending telemetry adds a population, and every rate it touches moves with nothing changed.
 * - Each change is compared device by device, only on devices with enough events in both weeks, and
 *   ranked by the events it touched at this week's volume (a rate change times this week's count),
 *   not by percentage points: 40 points on 92 clicks is 37 clicks, 10 points on 2,000 is 200.
 * - For a click friction change, Loki says how many people clicked, whether two of them made most
 *   of the clicks, and which elements the trouble clicks were on.
 * - Each change links to the place on the UX tab that shows it, for the same week.
 *
 * A table is only compared when BOTH weeks have a full seven rollup days. Otherwise the longer week
 * simply has more events and every base reads as growth; the mail says how many days each week has.
 *
 * Nothing is sent when the tables hold no rows for either week.
 */
#[AsCommand(name: 'ux:weekly-report')]
class WeeklyReportCommand extends Command
{
    protected $signature = 'ux:weekly-report';

    protected $description = 'Mail the five largest week-on-week UX changes to geeks@';

    /** A step or component needs at least this many events in BOTH weeks, on the devices compared. */
    private const MIN_EVENTS = 50;

    /** A device is compared only with at least this many events in both weeks. */
    private const MIN_DEVICE_EVENTS = 20;

    private const TOP = 5;

    private const WEEK_DAYS = 7;

    /** A device class whose weekly clicks moved by this factor either way, from at least SOURCE_MIN. */
    private const SOURCE_FACTOR = 1.5;

    private const SOURCE_MIN = 1000;

    /** Two people making at least this share of the clicks are named as the reason. */
    private const FEW_PEOPLE_SHARE = 0.5;

    /** Rollup tables the changes come from, and what the mail calls them. */
    private const COMPARED = [
        'ux_flow_daily' => 'Step conversion',
        'ux_component_daily' => 'Click friction',
        'ux_regret_daily' => 'Regret',
    ];

    private const NAME = '/^[A-Za-z0-9_-]*$/';

    /** Loki would not count the people: more than its 500-series cap. */
    private const TOO_MANY = 'too many';

    public function handle(LokiService $loki): int
    {
        $today = Carbon::now('UTC')->startOfDay();
        $cur = [$today->copy()->subDays(7)->toDateString(), $today->copy()->subDay()->toDateString()];
        $prev = [$today->copy()->subDays(14)->toDateString(), $today->copy()->subDays(8)->toDateString()];

        $unhealthy = DB::table('ux_telemetry_health_daily')
            ->whereBetween('date', $cur)
            ->where(fn ($q) => $q->where('ratio', '<', 0.7)->orWhere('ratio', '>', 1.3))
            ->orderBy('date')->orderBy('metric')->get()
            // An event metric's alarm is only believed once it has a like-for-like baseline.
            ->filter(fn ($h) => UxRollupService::healthAlarmTrusted($h->metric, (string) $h->date))
            ->values();

        $notCompared = [];
        $changes = [];
        $sources = [
            'ux_flow_daily' => 'conversionChanges',
            'ux_component_daily' => 'frictionChanges',
            'ux_regret_daily' => 'regretChanges',
        ];
        foreach ($sources as $table => $method) {
            $days = [$this->rollupDays($table, $cur), $this->rollupDays($table, $prev)];
            if ($days[0] < self::WEEK_DAYS || $days[1] < self::WEEK_DAYS) {
                if ($days[0] > 0 || $days[1] > 0) {
                    $notCompared[] = [self::COMPARED[$table], $days[0], $days[1]];
                }

                continue;
            }
            $changes = array_merge($changes, $this->$method($cur, $prev));
        }

        $anyRows = DB::table('ux_flow_daily')->whereBetween('date', [$prev[0], $cur[1]])->exists()
            || DB::table('ux_component_daily')->whereBetween('date', [$prev[0], $cur[1]])->exists()
            || DB::table('ux_regret_daily')->whereBetween('date', [$prev[0], $cur[1]])->exists()
            || DB::table('ux_telemetry_health_daily')->whereBetween('date', [$prev[0], $cur[1]])->exists();
        if (! $anyRows) {
            $this->info('No UX rollup rows for the last two weeks; nothing to report.');

            return self::SUCCESS;
        }

        usort($changes, fn ($a, $b) => [abs($b['effect']), abs($b['delta'])] <=> [abs($a['effect']), abs($a['delta'])]);
        $changes = array_slice($changes, 0, self::TOP);
        foreach ($changes as &$c) {
            if ($c['kind'] === 'friction') {
                $c['detail'] = $this->frictionDetail($loki, $c['page'], $c['component'], $today);
            }
        }
        unset($c);

        $to = config('freegle.ux.report_to') ?: config('freegle.geeks_addr', 'geeks@ilovefreegle.org');
        $body = $this->compose($cur, $prev, $unhealthy, $this->sourceShifts($cur, $prev), $changes, $notCompared);
        Mail::raw($body, function ($message) use ($to, $cur) {
            $message->to($to)->subject('UX weekly report: week ending '.$cur[1]);
        });

        $this->info(sprintf('UX report emailed: %d telemetry problem(s), %d change(s).', count($unhealthy), count($changes)));

        return self::SUCCESS;
    }

    /** Distinct days in the range that have a row in the rollup table. */
    private function rollupDays(string $table, array $range): int
    {
        return (int) DB::table($table)->whereBetween('date', $range)->distinct()->count('date');
    }

    /**
     * Device classes whose clicks moved by half or more between the weeks, from at least SOURCE_MIN.
     *
     * @return array<int, array{device: string, now: int, before: int}>
     */
    private function sourceShifts(array $cur, array $prev): array
    {
        $clicks = function (array $range): array {
            $out = [];
            foreach (DB::table('ux_component_daily')->whereBetween('date', $range)->get(['device_class', 'clicks']) as $r) {
                $device = (string) ($r->device_class ?? '');
                $out[$device] = ($out[$device] ?? 0) + (int) $r->clicks;
            }

            return $out;
        };
        $now = $clicks($cur);
        $before = $clicks($prev);

        $out = [];
        foreach (array_unique(array_merge(array_keys($now), array_keys($before))) as $device) {
            $a = $before[$device] ?? 0;
            $b = $now[$device] ?? 0;
            if ($device === '' || max($a, $b) < self::SOURCE_MIN) {
                continue;
            }
            if ($b >= $a * self::SOURCE_FACTOR || $a >= $b * self::SOURCE_FACTOR) {
                $out[] = ['device' => (string) $device, 'now' => $b, 'before' => $a];
            }
        }
        usort($out, fn ($x, $y) => abs($y['now'] - $y['before']) <=> abs($x['now'] - $x['before']));

        return $out;
    }

    /**
     * One change, compared device by device. $now and $before map a device to [count, hits]. Only devices
     * with MIN_DEVICE_EVENTS in both weeks are compared; the effect is this week's hits less the hits this
     * week's counts would have had at last week's rates.
     *
     * @return array{rateBefore: float, rateNow: float, count: int, countBefore: int, effect: float, devices: array, new: array}|null
     */
    private function compare(array $now, array $before): ?array
    {
        $count = 0;
        $countBefore = 0;
        $hits = 0;
        $expected = 0.0;
        $devices = [];
        $new = [];
        foreach ($now as $device => [$n, $h]) {
            [$bn, $bh] = $before[$device] ?? [0, 0];
            if ($bn < self::MIN_DEVICE_EVENTS || $n < self::MIN_DEVICE_EVENTS) {
                if ($bn === 0 && $n >= self::MIN_DEVICE_EVENTS) {
                    $new[] = [(string) $device, $n];
                }

                continue;
            }
            $count += $n;
            $countBefore += $bn;
            $hits += $h;
            $expected += $n * ($bh / $bn);
            $devices[] = [(string) $device, $n, $bn, $h / $n, $bh / $bn];
        }
        if ($count < self::MIN_EVENTS || $countBefore < self::MIN_EVENTS) {
            return null;
        }

        return [
            'rateBefore' => $expected / $count, 'rateNow' => $hits / $count, 'count' => $count,
            'countBefore' => $countBefore, 'effect' => $hits - $expected, 'devices' => $devices, 'new' => $new,
        ];
    }

    /**
     * @param  callable(object): array  $key  row => [key, device, count, hits]
     * @return array<string, array<string, array{0: int, 1: int}>>
     */
    private function byKeyAndDevice(iterable $rows, callable $key): array
    {
        $out = [];
        foreach ($rows as $r) {
            [$k, $device, $n, $h] = $key($r);
            $device = (string) ($device ?? '');
            $out[$k][$device] ??= [0, 0];
            $out[$k][$device][0] += $n;
            $out[$k][$device][1] += $h;
        }

        return $out;
    }

    private function conversionChanges(array $cur, array $prev): array
    {
        // A step is completed once per visit, so the rate is over visits, not first visits.
        $read = fn (array $range) => $this->byKeyAndDevice(
            DB::table('ux_flow_daily')->whereBetween('date', $range)->get(['flow', 'step', 'device_class', 'entered', 'reentered', 'completed']),
            fn ($r) => [$r->flow.'/'.$r->step, $r->device_class, (int) $r->entered + (int) $r->reentered, (int) $r->completed]
        );
        $now = $read($cur);
        $before = $read($prev);

        $out = [];
        foreach ($now as $key => $devices) {
            $c = $this->compare($devices, $before[$key] ?? []);
            if (! $c) {
                continue;
            }
            [$flow, $step] = explode('/', $key, 2);
            $out[] = $c + [
                'kind' => 'conversion', 'label' => 'Step conversion', 'key' => $key, 'unit' => 'visits',
                'hits' => 'completions', 'delta' => $c['rateNow'] - $c['rateBefore'],
                'link' => ['ux_section' => 'funnels', 'ux_flow' => $flow, 'ux_step' => $step],
            ];
        }

        return $out;
    }

    private function frictionChanges(array $cur, array $prev): array
    {
        $read = fn (array $range) => $this->byKeyAndDevice(
            DB::table('ux_component_daily')->whereBetween('date', $range)
                ->get(['page', 'component', 'device_class', 'clicks', 'rage_clicks', 'dead_clicks', 'repeated_intent']),
            fn ($r) => [$r->page.' / '.$r->component, $r->device_class, (int) $r->clicks,
                (int) $r->rage_clicks + (int) $r->dead_clicks + (int) $r->repeated_intent]
        );
        $now = $read($cur);
        $before = $read($prev);

        $out = [];
        foreach ($now as $key => $devices) {
            $c = $this->compare($devices, $before[$key] ?? []);
            if (! $c) {
                continue;
            }
            [$page, $component] = explode(' / ', $key, 2);
            $out[] = $c + [
                'kind' => 'friction', 'label' => 'Click friction (rage, dead, repeated)', 'key' => $key, 'unit' => 'clicks',
                'hits' => 'trouble clicks', 'delta' => $c['rateNow'] - $c['rateBefore'], 'page' => $page, 'component' => $component,
                'link' => ['ux_section' => 'pages', 'ux_open' => 'friction'],
            ];
        }

        return $out;
    }

    /**
     * Regret rates get the same thin-data guard as the others: a base under 50 swings wildly. The table
     * has no device, so a signal is compared whole.
     */
    private function regretChanges(array $cur, array $prev): array
    {
        $read = fn (array $range) => $this->byKeyAndDevice(
            DB::table('ux_regret_daily')->whereBetween('date', $range)->get(['signal', 'count', 'base']),
            fn ($r) => [$r->signal, '', (int) $r->base, (int) $r->count]
        );
        $now = $read($cur);
        $before = $read($prev);

        $out = [];
        foreach ($now as $key => $devices) {
            $c = $this->compare($devices, $before[$key] ?? []);
            if (! $c) {
                continue;
            }
            $out[] = $c + [
                'kind' => 'regret', 'label' => 'Regret', 'key' => $key, 'unit' => 'base',
                'hits' => 'regrets', 'delta' => $c['rateNow'] - $c['rateBefore'],
                'link' => ['ux_section' => 'pages', 'ux_open' => 'regret'],
            ];
        }

        return $out;
    }

    /**
     * Who made a component's clicks this week, and what the trouble clicks were on, from Loki. Each part
     * is null when Loki did not answer it. People is TOO_MANY when Loki refused to count them because more
     * than 500 sessions clicked (its series cap); then the two-person check cannot be made either.
     *
     * @return array{people: int|string|null, topTwo: ?int, total: ?int, elements: ?array}
     */
    private function frictionDetail(LokiService $loki, string $page, string $component, Carbon $end): array
    {
        $none = ['people' => null, 'topTwo' => null, 'total' => null, 'elements' => null];
        if (! preg_match(self::NAME, $page) || ! preg_match(self::NAME, $component)) {
            return $none;
        }
        $sel = '{app="freegle", source="client", event_type="interaction"}';
        $line = $component !== '' ? ' |= "'.$component.'"' : '';
        $clicks = $sel.$line.' | json page_route, component, interaction_type, session_id, interactive, rage, repeat_no_api, tag, label'
            .' | interaction_type="click" | page_route="'.$page.'" | component="'.$component.'"';

        // Evaluated once, at midnight at the end of the week. Loki rounds the start of a range query down to
        // a multiple of its step, so a 7-day step would land on a Thursday and read the wrong week.
        $run = function (string $logql) use ($loki, $end) {
            $series = $loki->queryMetricRange($logql, $end, $end, 86400);
            if ($series === null) {
                return $loki->lastMetricStatus === 400 && preg_match('/maximum (number )?of series/i', (string) $loki->lastMetricError)
                    ? self::TOO_MANY : null;
            }
            $out = [];
            foreach ($series as $s) {
                if ($s['points']) {
                    $out[] = [$s['labels'], (float) end($s['points'])];
                }
            }

            return $out;
        };
        $sum = fn ($r) => is_array($r) ? (int) array_sum(array_column($r, 1)) : null;

        $people = $run("count(sum by (session_id) (count_over_time($clicks [7d])))");
        $top = $people === self::TOO_MANY ? null : $run("topk(2, sum by (session_id) (count_over_time($clicks [7d])))");
        $total = $run("sum(count_over_time($clicks [7d]))");
        $elements = $run('topk(3, sum by (tag, label) (count_over_time('.$clicks
            .' | interactive="false" or rage="true" or repeat_no_api="true" [7d])))');
        if (is_array($elements)) {
            usort($elements, fn ($a, $b) => $b[1] <=> $a[1]);
            $elements = array_map(fn ($e) => [$this->element($e[0]), (int) $e[1]], $elements);
        } else {
            $elements = null;
        }

        return [
            'people' => $people === self::TOO_MANY ? self::TOO_MANY : $sum($people),
            'topTwo' => $sum($top),
            'total' => $sum($total),
            'elements' => $elements,
        ];
    }

    /** An element as the mail names it: its tag and the first 40 characters of its label. */
    private function element(array $labels): string
    {
        $label = trim((string) ($labels['label'] ?? ''));
        if (mb_strlen($label) > 40) {
            $label = mb_substr($label, 0, 40).'...';
        }
        $tag = (string) ($labels['tag'] ?? '');

        return $label !== '' ? sprintf('%s "%s"', $tag ?: 'element', $label) : ($tag ?: 'element').' with no label';
    }

    private function pct(float $v): string
    {
        return sprintf('%.1f%%', $v * 100);
    }

    private function link(array $cur, array $params): string
    {
        $query = http_build_query(['tab' => 'ux'] + $params + ['ux_period' => 'custom', 'ux_from' => $cur[0], 'ux_to' => $cur[1]]);

        return rtrim((string) config('freegle.sites.mod', 'https://modtools.org'), '/').'/sysadmin?'.$query;
    }

    private function compose(array $cur, array $prev, $unhealthy, array $shifts, array $changes, array $notCompared = []): string
    {
        $body = sprintf("UX weekly report: %s to %s, against %s to %s.\n\n", $cur[0], $cur[1], $prev[0], $prev[1]);

        if (count($unhealthy)) {
            $body .= "TELEMETRY CHANGED. Read this first: a change in the number of events is not a change in people.\n";
            foreach ($unhealthy as $h) {
                $body .= sprintf("  %s on %s: %s, expected %s (%s times, %s than usual)\n", $h->metric, $h->date,
                    number_format((float) $h->observed), $h->expected === null ? 'n/a' : number_format((float) $h->expected),
                    rtrim(rtrim(sprintf('%.3f', (float) $h->ratio), '0'), '.'), $h->ratio > 1 ? 'more' : 'fewer');
            }
            $body .= "\n";
        }

        if ($shifts) {
            $body .= "WHO IS COUNTED CHANGED. Clicks by device, this week against the week before:\n";
            foreach ($shifts as $s) {
                $body .= sprintf("  %s: %s clicks, was %s%s\n", $s['device'], number_format($s['now']), number_format($s['before']),
                    $s['before'] < self::SOURCE_MIN / 10 ? ' (new: a release that started sending telemetry?)' : '');
            }
            $body .= "  The changes below compare each device with itself, so a device that came or went does not move them.\n\n";
        }

        $body .= 'FIVE LARGEST CHANGES, by the events they touched this week. Each is compared device by device, on'
            .' devices with at least '.self::MIN_DEVICE_EVENTS.' events in both weeks, and needs '.self::MIN_EVENTS." in all:\n";
        if (! $changes) {
            $body .= "  (none comparable)\n";
        }
        foreach ($changes as $i => $c) {
            $body .= sprintf("\n  %d. %s - %s: %s to %s, about %s %s %s a week (%s %s, was %s)\n",
                $i + 1, $c['label'], $c['key'], $this->pct($c['rateBefore']), $this->pct($c['rateNow']),
                number_format(abs($c['effect'])), $c['effect'] >= 0 ? 'more' : 'fewer', $c['hits'],
                number_format($c['count']), $c['unit'], number_format($c['countBefore']));
            if (count($c['devices']) > 1 || ($c['devices'][0][0] ?? '') !== '') {
                foreach ($c['devices'] as [$device, $n, $bn, $r, $br]) {
                    $body .= sprintf("     %s: %s to %s (%s %s, was %s)\n", $device ?: 'no device',
                        $this->pct($br), $this->pct($r), number_format($n), $c['unit'], number_format($bn));
                }
            }
            foreach ($c['new'] as [$device, $n]) {
                $body .= sprintf("     %s: %s %s, none the week before, so not compared\n", $device ?: 'no device', number_format($n), $c['unit']);
            }
            if ($c['kind'] === 'friction') {
                $body .= $this->frictionLines($c['detail']);
            }
            $body .= '     See it: '.$this->link($cur, $c['link'])."\n";
        }

        if ($notCompared) {
            $body .= "\nNOT COMPARED (a week with fewer than ".self::WEEK_DAYS." rollup days is not set against another):\n";
            foreach ($notCompared as [$name, $now, $before]) {
                $body .= sprintf("  %s: %d day(s) this week, %d the week before\n", $name, $now, $before);
            }
        }

        $body .= "\nThe UX sub-tab of ModTools SysAdmin: ".rtrim((string) config('freegle.sites.mod', 'https://modtools.org'), '/')."/sysadmin?tab=ux\n";

        return $body;
    }

    private function frictionLines(array $d): string
    {
        $out = '';
        if ($d['people'] === self::TOO_MANY) {
            $out .= '     More than 500 people clicked it this week.';
        } elseif ($d['people'] !== null) {
            $out .= sprintf('     %s %s clicked it this week.', number_format($d['people']), $d['people'] === 1 ? 'person' : 'people');
            if ($d['topTwo'] !== null && $d['total'] && $d['topTwo'] / $d['total'] >= self::FEW_PEOPLE_SHARE) {
                $out .= sprintf(' %s of the %s clicks came from %s, so read this change as theirs.',
                    number_format($d['topTwo']), number_format($d['total']), $d['people'] <= 2 ? 'them' : 'just two of them');
            }
        }
        $out .= $out !== '' ? "\n" : '';
        if ($d['elements']) {
            $out .= '     Trouble clicks were on: '.implode('; ', array_map(fn ($e) => sprintf('%s (%s)', $e[0], number_format($e[1])), $d['elements'])).".\n";
        }

        return $out !== '' ? $out : "     Who and what: Loki did not answer, so not known.\n";
    }
}
