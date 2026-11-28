<?php

namespace App\Console\Commands\Ux;

use App\Services\LokiService;
use App\Services\Ux\UxRollupService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Mondays: mail the five week-on-week changes in step conversion, click friction and regret that are
 * least likely to be chance, from the ux_* rollup tables, so a person knows where to look and what
 * to look at. Nothing acts on it.
 *
 * The mail gives rates and relative changes, never counts. How many people came is set by things
 * outside the site (the weather, a news story, a scraper), so a count on its own says nothing about
 * the site; a rate, and how much each side of it moved, does.
 *
 * - A rate is over people, not events: a person is counted once a day, by member id or else by
 *   address (the people_* columns the rollup writes). A week rolled up before those columns existed
 *   is compared in events, and the mail says so.
 * - Each change says which side moved: the people finishing, or the people arriving. When only the
 *   arrivals at a funnel step moved, on some device, that is probably traffic and not the page. It
 *   is listed apart, in one line, and left out of the comparison.
 * - "People" are person-days: a person is counted once a day and the days are added up.
 * - Each change is compared device by device, only on devices with enough people in both weeks, and
 *   ranked by how unlikely it is to be chance (a two-proportion z score), which the mail does not
 *   print. A difference that small samples would throw up by chance (z under MIN_Z) is not listed.
 * - Telemetry that changed (health ratio outside 0.7 to 1.3) leads the mail, as a multiple of usual.
 * - For a click friction change, Loki says whether two people made most of the clicks and which
 *   elements the trouble clicks were on.
 * - Each change links to the place on the UX tab that shows it, for the same week.
 *
 * A table is only compared when BOTH weeks have a full seven rollup days.
 *
 * Nothing is sent when the tables hold no rows for either week.
 */
#[AsCommand(name: 'ux:weekly-report')]
class WeeklyReportCommand extends Command
{
    protected $signature = 'ux:weekly-report';

    protected $description = 'Mail the five clearest week-on-week UX changes to geeks@';

    /** A step or component needs at least this many people (or events) in BOTH weeks, on the devices compared. */
    private const MIN_EVENTS = 50;

    /** A device is compared only with at least this many in both weeks. */
    private const MIN_DEVICE_EVENTS = 20;

    private const TOP = 5;

    private const WEEK_DAYS = 7;

    /** A device class whose weekly clicks moved by this factor either way, from at least SOURCE_MIN. */
    private const SOURCE_FACTOR = 1.5;

    private const SOURCE_MIN = 1000;

    /** Two people making at least this share of the clicks are named as the reason. */
    private const FEW_PEOPLE_SHARE = 0.5;

    /**
     * Arrivals that moved by this factor either way, while the people finishing stayed within STEADY
     * (a tenth), are traffic. The band is narrow on purpose: wider, and a real fall in the people
     * finishing would be filed as traffic and never compared.
     */
    private const TRAFFIC_FACTOR = 1.5;

    private const STEADY = 1.1;

    /** At most this many traffic lines are printed; the rest are counted. */
    private const TRAFFIC_LINES = 5;

    /**
     * A change is listed only when its z score is at least this: about one chance in twenty of a
     * difference this big when nothing changed. Below it the week's difference is noise.
     */
    private const MIN_Z = 2.0;

    /** A side that moved by less than this is "no change". */
    private const NO_CHANGE = 0.05;

    /** Rollup tables the changes come from, and what the mail calls them. */
    private const COMPARED = [
        'ux_flow_daily' => 'Finishing a step',
        'ux_component_daily' => 'Trouble clicking',
        'ux_regret_daily' => 'Second thoughts',
    ];

    /** The columns that count people, per table: [base, hits]. */
    private const PEOPLE = [
        'ux_flow_daily' => ['people_entered', 'people_completed'],
        'ux_component_daily' => ['people', 'people_trouble'],
    ];

    /** The same titles as FLOW_TITLES in iznik-nuxt3/modtools/composables/useUxFlows.js. */
    private const FLOW_TITLES = [
        'give' => 'Offering something', 'ask' => 'Asking for something', 'reply' => 'Replying to a post',
        'chat_promise' => 'Promising an item', 'chat_outcome' => 'Marking taken or received',
        'login' => 'Signing in or joining', 'settings' => 'Settings',
    ];

    /** Steps whose key does not say what the member sees. Any other step prints as its key. */
    private const STEP_TITLES = [
        'login/signup' => 'the join form', 'login/signin' => 'the log in form', 'login/forgot' => 'forgotten password',
    ];

    /** The busiest pages, as staff call them. Any other page prints as its path. */
    private const PAGE_TITLES = [
        'index' => 'Home page', 'browse' => 'Browse', 'browse-term' => 'Browse', 'message-id' => 'A post',
        'myposts' => 'My posts', 'chats' => 'Chats', 'chats-id' => 'A chat', 'give' => 'Give', 'find' => 'Ask',
        'chitchat' => 'ChitChat', 'chitchat-id' => 'A ChitChat thread', 'settings' => 'Settings',
        'explore' => 'Explore', 'help' => 'Help',
    ];

    /** A regret signal as a sentence subject, and what it is a share of. */
    private const REGRET_TITLES = [
        'withdrawn_24h' => ['Posts withdrawn within a day', 'posts'],
        'duplicate_10m' => ['The same post made twice within ten minutes', 'posts'],
        'promise_reneged' => ['Promises taken back', 'promises'],
        'reply_after_taken' => ['Replies to a post already taken', 'replies'],
    ];

    private const HEALTH_TITLES = [
        'event:session_start' => 'visits starting', 'event:page_view' => 'page views', 'event:flow' => 'form step events',
        'event:interaction' => 'clicks', 'event:impressions' => 'things shown on screen',
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
        $inEvents = [];
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
            $people = $this->countsPeople($table, [$prev[0], $cur[1]]);
            if (isset(self::PEOPLE[$table]) && ! $people) {
                $inEvents[] = self::COMPARED[$table];
            }
            $changes = array_merge($changes, $this->$method($cur, $prev, $people));
        }

        $anyRows = DB::table('ux_flow_daily')->whereBetween('date', [$prev[0], $cur[1]])->exists()
            || DB::table('ux_component_daily')->whereBetween('date', [$prev[0], $cur[1]])->exists()
            || DB::table('ux_regret_daily')->whereBetween('date', [$prev[0], $cur[1]])->exists()
            || DB::table('ux_telemetry_health_daily')->whereBetween('date', [$prev[0], $cur[1]])->exists();
        if (! $anyRows) {
            $this->info('No UX rollup rows for the last two weeks; nothing to report.');

            return self::SUCCESS;
        }

        // Traffic is told apart before ranking, so a flood of arrivals cannot take a place in the five.
        $traffic = [];
        foreach ($changes as $c) {
            foreach ($c['traffic'] as $t) {
                $traffic[] = [$c, $t];
            }
        }
        // The biggest moves first.
        usort($traffic, fn ($a, $b) => abs(log($b[1]['base'])) <=> abs(log($a[1]['base'])));
        $changes = array_values(array_filter($changes, fn ($c) => $c['compared'] && abs($c['z']) >= self::MIN_Z));
        usort($changes, fn ($a, $b) => [abs($b['z']), abs($b['delta'])] <=> [abs($a['z']), abs($a['delta'])]);
        $changes = array_slice($changes, 0, self::TOP);
        foreach ($changes as &$c) {
            if ($c['kind'] === 'friction') {
                $c['detail'] = $this->frictionDetail($loki, $c['page'], $c['component'], $today);
            }
        }
        unset($c);

        $to = config('freegle.ux.report_to') ?: config('freegle.geeks_addr', 'geeks@ilovefreegle.org');
        $body = $this->compose($cur, $prev, $unhealthy, $this->sourceShifts($cur, $prev), $traffic, $changes, $notCompared, $inEvents);
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

    /** Whether every row of the table in the range has its people counts, so the weeks compare in people. */
    private function countsPeople(string $table, array $range): bool
    {
        if (! isset(self::PEOPLE[$table])) {
            return false;
        }
        [$base] = self::PEOPLE[$table];

        return Schema::hasColumn($table, $base)
            && ! DB::table($table)->whereBetween('date', $range)->whereNull($base)->exists();
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
     * One change, compared device by device. $now and $before map a device to [base, hits]: the people
     * who arrived and the people who finished (or clicked, and had trouble). Only devices with
     * MIN_DEVICE_EVENTS in both weeks are compared.
     *
     * With $arrivals (a funnel step, where the base is people arriving at a page), a device whose base
     * moved by TRAFFIC_FACTOR while its hits stayed within STEADY is traffic: more (or fewer) people
     * arrived and the same number finished. It is returned apart and not compared. Clicks get no such
     * rule: a scraper does not click, and the same trouble among more clicks is a change in the rate.
     *
     * rateBefore is last week's rate at this week's mix of devices, so a shift between devices does not
     * read as a change. z is the two-proportion z score of the two rates.
     *
     * @return array{compared: bool, rateBefore: float, rateNow: float, base: float, hits: float, z: float, devices: array, traffic: array, new: array}
     */
    private function compare(array $now, array $before, bool $arrivals = false): array
    {
        $count = 0;
        $countBefore = 0;
        $hits = 0;
        $hitsBefore = 0;
        $expected = 0.0;
        $devices = [];
        $traffic = [];
        $new = [];
        foreach ($now as $device => [$n, $h]) {
            [$bn, $bh] = $before[$device] ?? [0, 0];
            // Arrivals are told apart by address and finishers by member id, so several members behind
            // one address can finish more often than they "arrived". Nobody finishes without arriving.
            $h = min($h, $n);
            $bh = min($bh, $bn);
            if ($bn < self::MIN_DEVICE_EVENTS || $n < self::MIN_DEVICE_EVENTS) {
                if ($bn === 0 && $n >= self::MIN_DEVICE_EVENTS) {
                    $new[] = (string) $device;
                }

                continue;
            }
            $baseMoved = $n >= $bn * self::TRAFFIC_FACTOR || $bn >= $n * self::TRAFFIC_FACTOR;
            $hitsSteady = $bh > 0 && $h <= $bh * self::STEADY && $bh <= $h * self::STEADY;
            if ($arrivals && $baseMoved && $hitsSteady && min($n, $bn) >= self::MIN_EVENTS) {
                $traffic[] = ['device' => (string) $device, 'base' => $n / $bn, 'hits' => $h / $bh];

                continue;
            }
            $count += $n;
            $countBefore += $bn;
            $hits += $h;
            $hitsBefore += $bh;
            $expected += $n * ($bh / $bn);
            $devices[] = ['device' => (string) $device, 'rateNow' => $h / $n, 'rateBefore' => $bh / $bn,
                'base' => $n / $bn, 'hits' => $bh > 0 ? $h / $bh : null];
        }
        $none = ['compared' => false, 'rateBefore' => 0.0, 'rateNow' => 0.0, 'base' => 1.0, 'hits' => null, 'z' => 0.0,
            'devices' => [], 'traffic' => $traffic, 'new' => $new];
        if ($count < self::MIN_EVENTS || $countBefore < self::MIN_EVENTS) {
            return $none;
        }

        $rateNow = $hits / $count;
        $rateBefore = $expected / $count;
        $pooled = ($hits + $rateBefore * $countBefore) / ($count + $countBefore);
        $se = sqrt(max(0.0, $pooled * (1 - $pooled) * (1 / $count + 1 / $countBefore)));

        return [
            'compared' => true, 'rateBefore' => $rateBefore, 'rateNow' => $rateNow,
            'base' => $count / $countBefore, 'hits' => $hitsBefore > 0 ? $hits / $hitsBefore : null,
            'z' => $se > 0 ? ($rateNow - $rateBefore) / $se : 0.0,
            'devices' => $devices, 'traffic' => $traffic, 'new' => $new,
        ];
    }

    /**
     * @param  callable(object): array  $key  row => [key, device, base, hits]
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

    /** Every key's comparison that has something to say: a change to rank, or traffic to name. */
    private function changes(array $now, array $before, callable $describe, bool $arrivals = false): array
    {
        $out = [];
        foreach ($now as $key => $devices) {
            $c = $this->compare($devices, $before[$key] ?? [], $arrivals);
            if (! $c['compared'] && ! $c['traffic']) {
                continue;
            }
            $out[] = $c + ['key' => $key, 'delta' => $c['rateNow'] - $c['rateBefore']] + $describe((string) $key);
        }

        return $out;
    }

    private function conversionChanges(array $cur, array $prev, bool $people): array
    {
        // In events a step is completed once per visit, so the rate is over visits, not first visits.
        $read = fn (array $range) => $this->byKeyAndDevice(
            DB::table('ux_flow_daily')->whereBetween('date', $range)->get(),
            fn ($r) => $people
                ? [$r->flow.'/'.$r->step, $r->device_class, (int) $r->people_entered, (int) $r->people_completed]
                : [$r->flow.'/'.$r->step, $r->device_class, (int) $r->entered + (int) $r->reentered, (int) $r->completed]
        );

        return $this->changes($read($cur), $read($prev), function (string $key) use ($people) {
            [$flow, $step] = explode('/', $key, 2);

            return [
                'kind' => 'conversion',
                'name' => (self::FLOW_TITLES[$flow] ?? $flow).', '.(self::STEP_TITLES[$key] ?? 'step "'.$step.'"'),
                'rate' => $people ? 'of people who reached it finished it' : 'of visits to it finished it (events, not people)',
                'hitsSide' => $people ? 'People finishing' : 'Completions', 'baseSide' => $people ? 'People arriving' : 'Visits',
                'link' => ['ux_section' => 'funnels', 'ux_flow' => $flow, 'ux_step' => $step],
            ];
        }, true);
    }

    private function frictionChanges(array $cur, array $prev, bool $people): array
    {
        $read = fn (array $range) => $this->byKeyAndDevice(
            DB::table('ux_component_daily')->whereBetween('date', $range)->get(),
            fn ($r) => $people
                ? [$r->page.' / '.$r->component, $r->device_class, (int) $r->people, (int) $r->people_trouble]
                : [$r->page.' / '.$r->component, $r->device_class, (int) $r->clicks,
                    (int) $r->rage_clicks + (int) $r->dead_clicks + (int) $r->repeated_intent]
        );

        return $this->changes($read($cur), $read($prev), function (string $key) use ($people) {
            [$page, $component] = explode(' / ', $key, 2);
            $where = self::PAGE_TITLES[$page] ?? 'The /'.str_replace('-', '/', $page).' page';

            return [
                'kind' => 'friction',
                'name' => $where.', '.($component !== '' ? $component : 'outside any named part'),
                'rate' => $people
                    ? 'of people who clicked it had trouble (a click that did nothing, was repeated, or was angry)'
                    : 'of clicks on it were trouble (did nothing, repeated, or angry; events, not people)',
                'hitsSide' => $people ? 'People having trouble' : 'Trouble clicks', 'baseSide' => $people ? 'People clicking' : 'Clicks',
                'page' => $page, 'component' => $component,
                'link' => ['ux_section' => 'pages', 'ux_open' => 'friction'],
            ];
        });
    }

    /**
     * Regret rates get the same thin-data guard as the others: a base under 50 swings wildly. The table
     * has no device and counts posts, promises and replies, not people, so a signal is compared whole.
     */
    private function regretChanges(array $cur, array $prev, bool $people): array
    {
        $read = fn (array $range) => $this->byKeyAndDevice(
            DB::table('ux_regret_daily')->whereBetween('date', $range)->get(['signal', 'count', 'base']),
            fn ($r) => [$r->signal, '', (int) $r->base, (int) $r->count]
        );

        return $this->changes($read($cur), $read($prev), function (string $key) {
            [$title, $of] = self::REGRET_TITLES[$key] ?? [$key, 'the base'];

            return [
                'kind' => 'regret', 'name' => $title, 'rate' => 'of '.$of,
                'hitsSide' => $title, 'baseSide' => ucfirst($of),
                'link' => ['ux_section' => 'pages', 'ux_open' => 'regret'],
            ];
        });
    }

    /**
     * Who made a component's clicks this week, and what the trouble clicks were on, from Loki. Each part
     * is null when Loki did not answer it. People is TOO_MANY when Loki refused to count them because more
     * than 500 sessions clicked (its series cap); then the two-person check cannot be made either.
     *
     * @return array{people: int|string|null, topTwo: ?int, total: ?int, trouble: ?int, elements: ?array}
     */
    private function frictionDetail(LokiService $loki, string $page, string $component, Carbon $end): array
    {
        $none = ['people' => null, 'topTwo' => null, 'total' => null, 'trouble' => null, 'elements' => null];
        if (! preg_match(self::NAME, $page) || ! preg_match(self::NAME, $component)) {
            return $none;
        }
        $sel = '{app="freegle", source="client", event_type="interaction"}';
        $line = $component !== '' ? ' |= "'.$component.'"' : '';
        $clicks = $sel.$line.' | json page_route, component, interaction_type, session_id, interactive, rage, repeat_no_api, tag, label'
            .' | interaction_type="click" | page_route="'.$page.'" | component="'.$component.'"';
        $troubled = $clicks.' | interactive="false" or rage="true" or repeat_no_api="true"';

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
        $trouble = $run("sum(count_over_time($troubled [7d]))");
        $elements = $run("topk(3, sum by (tag, label) (count_over_time($troubled [7d])))");
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
            'trouble' => is_array($trouble) ? $sum($trouble) : null,
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

    /** A number without trailing zeros: 2, 2.5, 14. */
    private function plain(float $v): string
    {
        return rtrim(rtrim(sprintf('%.1f', $v), '0'), '.');
    }

    /**
     * How much one side moved, as a ratio of this week to last, in words: "no change", "up 12%",
     * "down 35%", "3.2 times as many". Null (nothing last week to compare with) reads "not comparable".
     */
    private function moved(?float $ratio): string
    {
        if ($ratio === null) {
            return 'not comparable';
        }
        if (abs($ratio - 1) < self::NO_CHANGE) {
            return 'no change';
        }
        if ($ratio >= 2) {
            return $this->plain($ratio).' times as many';
        }

        return sprintf('%s %d%%', $ratio > 1 ? 'up' : 'down', (int) round(abs($ratio - 1) * 100));
    }

    private function link(array $cur, array $params): string
    {
        $query = http_build_query(['tab' => 'ux'] + $params + ['ux_period' => 'custom', 'ux_from' => $cur[0], 'ux_to' => $cur[1]]);

        return rtrim((string) config('freegle.sites.mod', 'https://modtools.org'), '/').'/sysadmin?'.$query;
    }

    private function healthTitle(string $metric): string
    {
        if (isset(self::HEALTH_TITLES[$metric])) {
            return self::HEALTH_TITLES[$metric];
        }
        if (str_starts_with($metric, 'flow_start:')) {
            $flow = substr($metric, strlen('flow_start:'));

            return '"'.(self::FLOW_TITLES[$flow] ?? $flow).'" started, against visits to its first page';
        }

        return $metric;
    }

    private function compose(array $cur, array $prev, $unhealthy, array $shifts, array $traffic, array $changes, array $notCompared = [], array $inEvents = []): string
    {
        $body = sprintf("UX weekly report: %s to %s, against %s to %s.\n", $cur[0], $cur[1], $prev[0], $prev[1]);
        $body .= "Rates and relative changes only. How many people came is set by things outside the site, so no counts are given.\n";
        $body .= "A person is counted once a day, by member id or else by address.\n\n";

        if ($inEvents) {
            $body .= "COUNTED IN EVENTS, NOT PEOPLE this week (a week was rolled up before people were counted): ".implode('; ', $inEvents).".\n"
                ."  One person or one stuck page can move an event rate by themselves, so read those changes with care.\n\n";
        }


        if (count($unhealthy)) {
            $body .= "WHAT WE MEASURE CHANGED. Read this first: more or fewer events is not more or fewer people.\n";
            foreach ($unhealthy as $h) {
                $body .= sprintf("  %s, %s: %s times the usual amount\n", $h->date, $this->healthTitle((string) $h->metric),
                    rtrim(rtrim(sprintf('%.2f', (float) $h->ratio), '0'), '.'));
            }
            $body .= "\n";
        }

        if ($shifts) {
            $body .= "WHO IS COUNTED CHANGED. Clicks by device, this week against the week before:\n";
            foreach ($shifts as $s) {
                $body .= sprintf("  %s: %s\n", $s['device'], $s['before'] < self::SOURCE_MIN / 10
                    ? 'new this week (a release that started sending telemetry?)'
                    : $this->moved($s['now'] / $s['before']));
            }
            $body .= "  The changes below compare each device with itself, so a device that came or went does not move them.\n\n";
        }

        if ($traffic) {
            $body .= "PROBABLY TRAFFIC, NOT THE PAGE. The number arriving moved and the number finishing did not.\n"
                ."That is usually a scraper or a campaign, not a change in the page. Left out of the changes below:\n";
            foreach (array_slice($traffic, 0, self::TRAFFIC_LINES) as [$c, $t]) {
                $body .= sprintf("  %s%s: %s %s; %s %s.\n", $c['name'], $t['device'] !== '' ? ', '.$t['device'] : '',
                    lcfirst($c['baseSide']), $this->moved($t['base']), lcfirst($c['hitsSide']), $this->moved($t['hits']));
            }
            if (count($traffic) > self::TRAFFIC_LINES) {
                $body .= "  And others where the arrivals moved less.\n";
            }
            $body .= "\n";
        }

        $body .= "FIVE CLEAREST CHANGES, the least likely to be chance first. Each compares a device with itself,\n"
            ."and \"was\" is last week's rate at this week's mix of devices:\n";
        if (! $changes) {
            $body .= "  (nothing moved by more than chance)\n";
        }
        foreach ($changes as $i => $c) {
            $body .= sprintf("\n  %d. %s: %s %s, was %s.\n", $i + 1, $c['name'], $this->pct($c['rateNow']), $c['rate'], $this->pct($c['rateBefore']));
            $body .= sprintf("     %s: %s. %s: %s.\n", $c['hitsSide'], $this->moved($c['hits']), $c['baseSide'], $this->moved($c['base']));
            if (count($c['devices']) > 1 || ($c['devices'][0]['device'] ?? '') !== '') {
                foreach ($c['devices'] as $d) {
                    $body .= sprintf("     %s: %s, was %s\n", $d['device'] ?: 'no device', $this->pct($d['rateNow']), $this->pct($d['rateBefore']));
                }
            }
            foreach ($c['new'] as $device) {
                $body .= sprintf("     %s: nothing the week before, so not compared\n", $device ?: 'no device');
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
        if ($d['people'] !== self::TOO_MANY && $d['people'] !== null && $d['topTwo'] !== null && $d['total']
            && $d['topTwo'] / $d['total'] >= self::FEW_PEOPLE_SHARE) {
            $out .= sprintf("     %s made %d%% of the clicks, so read this change as theirs.\n",
                $d['people'] <= 2 ? 'One or two people' : 'Just two people', (int) round(100 * $d['topTwo'] / $d['total']));
        }
        if ($d['elements']) {
            $share = fn (int $n) => $d['trouble'] ? sprintf(' (%d%% of the trouble)', (int) round(100 * $n / $d['trouble'])) : '';
            $out .= '     Trouble clicks were on: '.implode('; ', array_map(fn ($e) => $e[0].$share($e[1]), $d['elements'])).".\n";
        } elseif ($d['elements'] === null) {
            $out .= "     What the trouble clicks were on: the log store did not answer, so not known.\n";
        }

        return $out;
    }
}
