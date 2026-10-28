<?php

namespace App\Services\Ux;

use App\Services\LokiService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Turns one UTC day of client telemetry in Loki, and one day of MySQL history, into the ux_*
 * tables. The contract for the events is docs/developers/reference/ux-telemetry.md.
 *
 * Loki does the counting. Flow, click and health figures are LogQL metric queries evaluated once
 * over the whole day, so no raw line is paged for them. Raw lines are paged only where a metric
 * cannot answer: impressions (the component names are map keys, which LogQL cannot group by),
 * distinct users and sessions, re-searches, and the sample session ids.
 *
 * The day window is [00:00:00, 24:00:00) UTC, but a metric is evaluated at 23:59:59 over "[1d]",
 * so it takes in the last second of the previous day and drops the last second of this one. Loki
 * rejects a query whose start equals its end, and one second of 86,400 is not worth a second
 * query.
 *
 * A Loki failure aborts the whole day's Loki-derived output (UxLokiUnavailable): nothing is
 * written and the previous rows stay. The MySQL-derived regret rows are written separately.
 */
class UxRollupService
{
    public const FLOWS = ['give', 'ask', 'reply', 'login', 'chat_promise', 'chat_outcome', 'settings'];

    public const DEVICE_CLASSES = ['app-ios', 'app-android', 'mobile', 'tablet', 'desktop'];

    public const EVENT_TYPES = ['page_view', 'interaction', 'impressions', 'flow', 'session_start'];


    // Lines per Loki request. An impressions line is about 900 bytes, and Loki hands a result from
    // its querier to its frontend in one gRPC message capped at 4MB: a 5000-line page exceeded it,
    // Loki retried until the client timed out, and every busy day failed as "Loki unavailable".
    private const LINE_LIMIT = 1000;

    /**
     * What the search code logs: apiv2 logs each vector search as source="vector_search"
     * (message/vectorsearch.go logVectorSearch), with user_id (0 when logged out), returned (the
     * count) and a timestamp. There is no source="search" line.
     */
    private const SEARCH_SOURCE = 'vector_search';

    /**
     * Flows that start on an action rather than on opening a page, so a visit that only looks
     * sends nothing and their starts against entry page views would measure how many visits act,
     * not whether events were lost. reply starts on typing, often on a browse card; settings at
     * the first save in a visit. event:flow covers them in the health check.
     */
    /** Days of like-for-like history the health check needs before it will raise an alarm. */
    public const MIN_BASELINE_DAYS = 7;

    private const BASELINE_WINDOW_DAYS = 14;

    private const STARTS_ON_ACTION = ['reply', 'settings'];

    private const FLOW_DIMS = ['device_class', 'logged_in', 'is_app', 'variant'];

    private const IDLE_RESUME = ' | reason="idle"';

    public function __construct(private LokiService $loki)
    {
    }

    /**
     * Compute and replace every Loki-derived table for the day. False when Loki was unreachable,
     * in which case nothing was written.
     */
    public function rollupLokiDay(Carbon $day): bool
    {
        $day = $day->copy()->utc()->startOfDay();

        try {
            $data = $this->collect($day);
        } catch (UxLokiUnavailable $e) {
            Log::warning('ux:rollup-daily: Loki unavailable for '.$day->toDateString().': '.$e->getMessage());

            return false;
        }

        $date = $day->toDateString();
        foreach ($data as $table => $rows) {
            if (! Schema::hasTable($table)) {
                // Only a table added after the first release can be missing, when this code is live
                // before its migration has been run. The rest of the day is still worth writing.
                Log::warning("ux:rollup-daily: table {$table} does not exist, skipped");

                continue;
            }
            DB::transaction(function () use ($table, $date, $rows) {
                DB::table($table)->where('date', $date)->delete();
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
                if ($table === 'ux_sample_sessions') {
                    // The ids point into Loki, which keeps seven days; they are useless after eight.
                    DB::table($table)->where('date', '<', Carbon::now()->subDays(8)->toDateString())->delete();
                }
            });
        }

        return true;
    }

    /**
     * Regret signals, from MySQL. Each is a count with the denominator it is a rate of.
     */
    public function rollupRegret(Carbon $day): void
    {
        $day = $day->copy()->utc()->startOfDay();
        $start = $day->toDateTimeString();
        $end = $day->copy()->addDay()->toDateTimeString();

        $count = fn (string $sql) => (int) (DB::selectOne($sql, [$start, $end])->c ?? 0);

        $posts = $count("SELECT COUNT(*) c FROM messages m WHERE m.arrival >= ? AND m.arrival < ? AND m.type IN ('Offer', 'Wanted')");

        $withdrawn = $count("SELECT COUNT(*) c FROM messages m
            WHERE m.arrival >= ? AND m.arrival < ? AND m.type IN ('Offer', 'Wanted')
            AND EXISTS (SELECT 1 FROM messages_outcomes mo WHERE mo.msgid = m.id AND mo.outcome = 'Withdrawn'
                AND mo.timestamp <= m.arrival + INTERVAL 24 HOUR)");

        // The later of two identical posts is the regret: the first was the poster's real attempt.
        $duplicates = $count("SELECT COUNT(*) c FROM messages m
            WHERE m.arrival >= ? AND m.arrival < ? AND m.type IN ('Offer', 'Wanted')
            AND m.fromuser IS NOT NULL AND m.subject IS NOT NULL
            AND EXISTS (SELECT 1 FROM messages m2 WHERE m2.fromuser = m.fromuser AND m2.subject = m.subject
                AND m2.id < m.id AND m2.arrival >= m.arrival - INTERVAL 10 MINUTE AND m2.arrival <= m.arrival)");

        $promised = $count('SELECT COUNT(*) c FROM messages_promises WHERE promisedat >= ? AND promisedat < ?');
        $reneged = $count('SELECT COUNT(*) c FROM messages_reneged WHERE timestamp >= ? AND timestamp < ?');

        $replies = $count("SELECT COUNT(*) c FROM chat_messages cm
            WHERE cm.type = 'Interested' AND cm.date >= ? AND cm.date < ? AND cm.refmsgid IS NOT NULL");
        $afterTaken = $count("SELECT COUNT(*) c FROM chat_messages cm
            WHERE cm.type = 'Interested' AND cm.date >= ? AND cm.date < ? AND cm.refmsgid IS NOT NULL
            AND EXISTS (SELECT 1 FROM messages_outcomes mo WHERE mo.msgid = cm.refmsgid AND mo.outcome = 'Taken'
                AND mo.timestamp < cm.date)");

        $date = $day->toDateString();
        DB::transaction(function () use ($date, $withdrawn, $posts, $duplicates, $reneged, $promised, $afterTaken, $replies) {
            DB::table('ux_regret_daily')->where('date', $date)->delete();
            DB::table('ux_regret_daily')->insert([
                ['date' => $date, 'signal' => 'withdrawn_24h', 'count' => $withdrawn, 'base' => $posts],
                ['date' => $date, 'signal' => 'duplicate_10m', 'count' => $duplicates, 'base' => $posts],
                ['date' => $date, 'signal' => 'promise_reneged', 'count' => $reneged, 'base' => $promised],
                ['date' => $date, 'signal' => 'reply_after_taken', 'count' => $afterTaken, 'base' => $replies],
            ]);
        });
    }

    /**
     * @return array<string, array<int, array<string, mixed>>> table => rows, all for this day
     */
    private function collect(Carbon $day): array
    {
        $this->day = $day;
        $date = $day->toDateString();
        [$flowRows, $outcomeRows, $fieldRows] = $this->collectFlows($day);
        $started = [];
        foreach ($outcomeRows as $r) {
            $started[$r['flow']] = ($started[$r['flow']] ?? 0) + $r['started'];
        }

        $withDate = fn (array $rows) => array_map(fn ($r) => ['date' => $date] + $r, $rows);

        return [
            'ux_flow_daily' => $withDate($flowRows),
            'ux_flow_outcome_daily' => $withDate($outcomeRows),
            'ux_field_daily' => $withDate($fieldRows),
            'ux_flow_signal_daily' => $withDate($this->collectSignals()),
            'ux_component_daily' => $withDate($this->collectComponents($day)),
            'ux_feature_adoption_daily' => $withDate($this->collectAdoption($day)),
            'ux_search_daily' => $withDate([$this->collectSearch($day)]),
            'ux_telemetry_health_daily' => $withDate($this->collectHealth($day, $started)),
            'ux_sample_sessions' => $withDate($this->collectSamples($day)),
        ];
    }

    // ---------------------------------------------------------------- flows

    private function flowSelector(): string
    {
        return $this->selector('flow');
    }

    private function selector(string $eventType): string
    {
        return '{app="freegle", source="client", event_type="'.$eventType.'"}';
    }

    /**
     * A LogQL label regex for exactly these route names. A label filter regex in Loki matches
     * anywhere in the value, so an unanchored "give" also matched every give-* page: the health
     * check held the offer flow's starts against all its pages and read 0.29 when nothing was wrong.
     *
     * @param  array<int, string>  $routes  plain route names, already checked
     */
    private static function routeRegex(array $routes): string
    {
        return '^(?:'.implode('|', $routes).')$';
    }

    /** Group-by labels for the per-step tables. */
    private function stepBy(string $stepLabel = 'step', array $extra = []): array
    {
        return array_merge([$stepLabel], self::FLOW_DIMS, $extra);
    }

    /** $filter is a fixed LogQL label filter on 'reason', e.g. ' | reason="idle"'; never input. */
    private function flowCount(string $flow, string $action, array $by, string $filter = ''): array
    {
        $parse = array_merge(['flow', 'action_name'], $by, $filter === '' ? [] : ['reason']);

        return $this->metric(sprintf(
            'sum by (%s) (count_over_time(%s | json %s | flow="%s" | action_name="%s"%s [1d]))',
            implode(', ', $by), $this->flowSelector(), implode(', ', array_unique($parse)), $flow, $action, $filter
        ), true);
    }

    private function flowDwell(string $flow, string $action, float $q, array $by): array
    {
        return $this->metric(sprintf(
            'quantile_over_time(%s, %s | json %s | flow="%s" | action_name="%s" | unwrap dwell_ms | __error__="" [1d]) by (%s)',
            $q, $this->flowSelector(), implode(', ', array_merge(['flow', 'action_name', 'dwell_ms'], $by)), $flow, $action, implode(', ', $by)
        ), true);
    }

    /**
     * @return array{0: array, 1: array, 2: array} flow rows, outcome rows, field rows
     */
    private function collectFlows(Carbon $day): array
    {
        $flowRows = [];
        $outcomeRows = [];
        $fieldRows = [];

        $dimKey = fn (array $l) => [
            mb_substr($l['device_class'] ?? '', 0, 16),
            $this->bool($l['logged_in'] ?? ''),
            $this->bool($l['is_app'] ?? ''),
            mb_substr($l['variant'] ?? '', 0, 64),
        ];

        foreach (self::FLOWS as $flow) {
            // Per-step funnel columns.
            $stepSources = [
                ['step_enter', 'step', [], null],
                ['step_complete', 'step', [], 'completed'],
                ['step_back', 'step', [], 'backed'],
                ['flow_abandon', 'last_step', [], 'abandoned'],
                ['field_revisit', 'step', [], 'revisits'],
                ['validation_failed', 'step', [], 'validation_failures'],
                ['help_sought', 'step', [], 'help_sought'],
            ];
            foreach ($stepSources as [$action, $stepLabel, , $column]) {
                $extra = $action === 'step_enter' ? ['first_visit'] : [];
                foreach ($this->flowCount($flow, $action, $this->stepBy($stepLabel, $extra)) as [$labels, $n]) {
                    $key = array_merge([$flow, mb_substr($labels[$stepLabel] ?? '', 0, 64)], $dimKey($labels));
                    $row = &$this->flowRow($flowRows, $key);
                    if ($action === 'step_enter') {
                        $row[($labels['first_visit'] ?? '') === 'true' ? 'entered' : 'reentered'] += $n;
                    } else {
                        $row[$column] += $n;
                    }
                    unset($row);
                }
            }
            // A member who carries on after the idle timer abandoned them sends flow_resume with
            // reason idle on the same instance. Their abandon is taken back off.
            foreach ($this->flowCount($flow, 'flow_resume', $this->stepBy(), self::IDLE_RESUME) as [$labels, $n]) {
                $key = array_merge([$flow, mb_substr($labels['step'] ?? '', 0, 64)], $dimKey($labels));
                $row = &$this->flowRow($flowRows, $key);
                $row['abandoned'] = max(0, $row['abandoned'] - $n);
                unset($row);
            }
            foreach ([['0.5', 'dwell_p50_ms'], ['0.9', 'dwell_p90_ms']] as [$q, $column]) {
                foreach ($this->flowDwell($flow, 'step_complete', (float) $q, $this->stepBy()) as [$labels, $ms]) {
                    $key = array_merge([$flow, mb_substr($labels['step'] ?? '', 0, 64)], $dimKey($labels));
                    $row = &$this->flowRow($flowRows, $key);
                    $row[$column] = (int) round($ms);
                    unset($row);
                }
            }

            // Per-flow outcomes.
            $outcomeColumns = [
                'flow_start' => 'started', 'flow_submit' => 'submitted', 'flow_complete' => 'completed',
                'flow_abandon' => 'abandoned',
            ];
            foreach ($outcomeColumns as $action => $column) {
                foreach ($this->flowCount($flow, $action, self::FLOW_DIMS) as [$labels, $n]) {
                    $row = &$this->outcomeRow($outcomeRows, array_merge([$flow], $dimKey($labels)));
                    $row[$column] += $n;
                    unset($row);
                }
            }
            // resumed is a restored draft; an idle return instead cancels its abandon, as above.
            foreach ($this->flowCount($flow, 'flow_resume', self::FLOW_DIMS, ' | reason!="idle"') as [$labels, $n]) {
                $row = &$this->outcomeRow($outcomeRows, array_merge([$flow], $dimKey($labels)));
                $row['resumed'] += $n;
                unset($row);
            }
            foreach ($this->flowCount($flow, 'flow_resume', self::FLOW_DIMS, self::IDLE_RESUME) as [$labels, $n]) {
                $row = &$this->outcomeRow($outcomeRows, array_merge([$flow], $dimKey($labels)));
                $row['abandoned'] = max(0, $row['abandoned'] - $n);
                unset($row);
            }
            foreach ($this->flowCount($flow, 'flow_submit_result', array_merge(self::FLOW_DIMS, ['ok'])) as [$labels, $n]) {
                $row = &$this->outcomeRow($outcomeRows, array_merge([$flow], $dimKey($labels)));
                $row[($labels['ok'] ?? '') === 'true' ? 'submit_ok' : 'submit_failed'] += $n;
                unset($row);
            }
            foreach ([['0.5', 'total_dwell_p50_ms'], ['0.9', 'total_dwell_p90_ms']] as [$q, $column]) {
                foreach ($this->flowDwell($flow, 'flow_complete', (float) $q, self::FLOW_DIMS) as [$labels, $ms]) {
                    $row = &$this->outcomeRow($outcomeRows, array_merge([$flow], $dimKey($labels)));
                    $row[$column] = (int) round($ms);
                    unset($row);
                }
            }

            // Per-field.
            $fieldColumns = ['field_revisit' => 'revisits', 'validation_failed' => 'validation_failures', 'choice_switched' => 'choice_switches'];
            foreach ($fieldColumns as $action => $column) {
                foreach ($this->flowCount($flow, $action, ['step', 'field', 'device_class']) as [$labels, $n]) {
                    $key = $flow.'|'.mb_substr($labels['step'] ?? '', 0, 64).'|'.mb_substr($labels['field'] ?? '', 0, 64).'|'.mb_substr($labels['device_class'] ?? '', 0, 16);
                    $fieldRows[$key] ??= [
                        'flow' => $flow, 'step' => mb_substr($labels['step'] ?? '', 0, 64), 'field' => mb_substr($labels['field'] ?? '', 0, 64),
                        'device_class' => mb_substr($labels['device_class'] ?? '', 0, 16),
                        'revisits' => 0, 'validation_failures' => 0, 'choice_switches' => 0,
                    ];
                    $fieldRows[$key][$column] += $n;
                }
            }
        }

        return [array_values($flowRows), array_values($outcomeRows), array_values($fieldRows)];
    }

    /**
     * Second thoughts followed to the end of the visit. For each step (and field) it counts the
     * distinct flow instances that showed the signal, and how many of those also sent flow_complete.
     * LogQL does the join: a set operation on flow_instance_id, so no raw line is paged. 'reached'
     * is every instance that entered the step, the base the other signals are compared with.
     *
     * A visit that goes back and finishes after midnight UTC is counted as not finished, because
     * the day's completions are the ones sent that day. That is a small undercount of finished,
     * the same for every row.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectSignals(): array
    {
        $rows = [];
        $signals = [
            ['reached', 'step_enter', 'step', false],
            ['backed', 'step_back', 'step', false],
            ['help', 'help_sought', 'step', false],
            ['field', 'field_revisit|validation_failed|choice_switched', 'step', true],
        ];

        foreach (self::FLOWS as $flow) {
            $finished = sprintf(
                'sum by (flow_instance_id) (count_over_time(%s | json flow, action_name, flow_instance_id | flow="%s" | action_name="flow_complete" | flow_instance_id!="" [1d]))',
                $this->flowSelector(), $flow
            );

            foreach ($signals as [$signal, $actions, $stepLabel, $withField]) {
                $group = array_merge($withField ? [$stepLabel, 'field'] : [$stepLabel], self::FLOW_DIMS);
                $match = str_contains($actions, '|') ? '=~"^('.$actions.')$"' : '="'.$actions.'"';
                $visits = sprintf(
                    'sum by (flow_instance_id, %s) (count_over_time(%s | json %s | flow="%s" | action_name%s | flow_instance_id!="" [1d]))',
                    implode(', ', $group), $this->flowSelector(),
                    implode(', ', array_merge(['flow', 'action_name', 'flow_instance_id'], $group)), $flow, $match
                );
                $by = implode(', ', $group);

                foreach (['people' => $visits, 'finished' => $visits.' and on (flow_instance_id) '.$finished] as $column => $inner) {
                    foreach ($this->metric(sprintf('count by (%s) (%s)', $by, $inner), true) as [$labels, $n]) {
                        $field = $withField ? mb_substr($labels['field'] ?? '', 0, 64) : '';
                        $key = array_merge(
                            [$flow, mb_substr($labels[$stepLabel] ?? '', 0, 64), $field, $signal],
                            [
                                mb_substr($labels['device_class'] ?? '', 0, 16),
                                $this->bool($labels['logged_in'] ?? ''),
                                $this->bool($labels['is_app'] ?? ''),
                                mb_substr($labels['variant'] ?? '', 0, 64),
                            ]
                        );
                        $k = implode('|', $key);
                        $rows[$k] ??= [
                            'flow' => $key[0], 'step' => $key[1], 'field' => $key[2], 'signal' => $key[3],
                            'device_class' => $key[4], 'logged_in' => $key[5], 'is_app' => $key[6], 'variant' => $key[7],
                            'people' => 0, 'finished' => 0,
                        ];
                        $rows[$k][$column] += (int) $n;
                    }
                }
            }
        }

        return array_values($rows);
    }

    private function &flowRow(array &$rows, array $key): array
    {
        $k = implode('|', $key);
        if (! isset($rows[$k])) {
            $rows[$k] = [
                'flow' => $key[0], 'step' => $key[1], 'device_class' => $key[2], 'logged_in' => $key[3],
                'is_app' => $key[4], 'variant' => $key[5],
                'entered' => 0, 'reentered' => 0, 'completed' => 0, 'backed' => 0, 'abandoned' => 0,
                'revisits' => 0, 'validation_failures' => 0, 'help_sought' => 0,
                'dwell_p50_ms' => null, 'dwell_p90_ms' => null,
            ];
        }

        return $rows[$k];
    }

    private function &outcomeRow(array &$rows, array $key): array
    {
        $k = implode('|', $key);
        if (! isset($rows[$k])) {
            $rows[$k] = [
                'flow' => $key[0], 'device_class' => $key[1], 'logged_in' => $key[2], 'is_app' => $key[3], 'variant' => $key[4],
                'started' => 0, 'submitted' => 0, 'submit_ok' => 0, 'submit_failed' => 0, 'completed' => 0,
                'abandoned' => 0, 'resumed' => 0, 'total_dwell_p50_ms' => null, 'total_dwell_p90_ms' => null,
            ];
        }

        return $rows[$k];
    }

    private function bool(string $v): int
    {
        return $v === 'true' ? 1 : 0;
    }

    // ----------------------------------------------------------- components

    private function collectComponents(Carbon $day): array
    {
        $rows = [];
        $row = function (string $page, string $component, string $device) use (&$rows): string {
            $page = mb_substr($page, 0, 64);
            $component = mb_substr($component, 0, 96);
            $device = mb_substr($device, 0, 16);
            $k = $page.'|'.$component.'|'.$device;
            $rows[$k] ??= [
                'page' => $page, 'component' => $component, 'device_class' => $device,
                'impressions' => 0, 'clicks' => 0, 'dead_clicks' => 0, 'rage_clicks' => 0, 'repeated_intent' => 0,
            ];

            return $k;
        };

        // One query per device class so a single answer cannot exceed Loki's series cap.
        $filters = [
            'clicks' => '', 'dead_clicks' => ' | interactive="false"', 'rage_clicks' => ' | rage="true"',
            'repeated_intent' => ' | repeat_no_api="true"',
        ];
        foreach (self::DEVICE_CLASSES as $device) {
            foreach ($filters as $column => $filter) {
                foreach ($this->clickSeries($device, $filter) as [$labels, $n]) {
                    $k = $row($labels['page_route'] ?? '', $labels['component'] ?? '', $labels['device_class'] ?? $device);
                    $rows[$k][$column] += $n;
                }
            }
        }

        // Impressions: the component names are keys of an object, so the lines are read.
        $fractions = [];
        foreach ($this->lines($this->selector('impressions'), $day, $day->copy()->addDay()) as $line) {
            $rate = (float) ($line['sample_rate'] ?? 1);
            $rate = $rate > 0 ? $rate : 1.0;
            foreach (($line['components'] ?? []) as $name => $n) {
                $k = $row((string) ($line['page_route'] ?? ''), (string) $name, (string) ($line['device_class'] ?? ''));
                $fractions[$k] = ($fractions[$k] ?? 0.0) + ((float) $n) / $rate;
            }
        }
        foreach ($fractions as $k => $v) {
            $rows[$k]['impressions'] = (int) round($v);
        }

        return array_values($rows);
    }

    /**
     * Click counts by (page_route, component) for one device class and filter. Loki errors, rather
     * than truncating, when a query has too many series, so on that error the routes are listed
     * and each is queried alone. A single route that is still too big is logged and left out
     * rather than costing the night.
     *
     * @return array<int, array{0: array<string, string>, 1: float}>
     */
    private function clickSeries(string $device, string $filter): array
    {
        $sel = $this->selector('interaction');
        $head = '| json page_route, component, device_class, interaction_type, interactive, rage, repeat_no_api | interaction_type="click" | device_class="'.$device.'"'.$filter;
        try {
            return $this->metricOrThrow("sum by (page_route, component, device_class) (count_over_time($sel $head [1d]))");
        } catch (UxSeriesLimit) {
            Log::warning('ux:rollup-daily: series limit on clicks for '.$device.', splitting by page_route');
        }

        $out = [];
        foreach ($this->metric("sum by (page_route) (count_over_time($sel $head [1d]))", true) as [$labels]) {
            $route = $labels['page_route'] ?? '';
            if (! preg_match('/^[A-Za-z0-9_-]*$/', $route)) {
                continue;
            }
            $part = $this->metric("sum by (component, device_class) (count_over_time($sel $head | page_route=\"$route\" [1d]))", true);
            foreach ($part as [$l, $n]) {
                $out[] = [$l + ['page_route' => $route], $n];
            }
        }

        return $out;
    }
    // ------------------------------------------------------------- adoption

    private function collectAdoption(Carbon $day): array
    {
        $date = $day->toDateString();
        $active = (int) DB::table('users')
            ->where('lastaccess', '>=', $day->toDateTimeString())
            ->where('lastaccess', '<', $day->copy()->addDay()->toDateTimeString())
            ->count();

        $rows = [];
        foreach ((array) config('freegle.ux.features', []) as $feature => $routes) {
            // Route names come from config, not from a member, but they go into LogQL, so only
            // plain route-name characters are let through.
            $routes = array_values(array_filter((array) $routes, fn ($r) => is_string($r) && preg_match('/^[A-Za-z0-9_-]+$/', $r)));
            $users = [];
            $sessions = [];
            if ($routes) {
                $logql = $this->selector('page_view').' | json | page_route=~"'.self::routeRegex($routes).'"';
                foreach ($this->lines($logql, $day, $day->copy()->addDay()) as $line) {
                    if (! empty($line['user_id'])) {
                        $users[(string) $line['user_id']] = true;
                    }
                    if (! empty($line['session_id'])) {
                        $sessions[(string) $line['session_id']] = true;
                    }
                }
            }
            $rows[] = [
                'feature' => mb_substr((string) $feature, 0, 64), 'active_users' => $active,
                'using_users' => count($users), 'sessions_using' => count($sessions),
            ];
        }

        return $rows;
    }

    // --------------------------------------------------------------- search

    private function collectSearch(Carbon $day): array
    {
        $sel = '{app="freegle", source="'.self::SEARCH_SOURCE.'"}';
        $total = $this->scalar(sprintf('sum(count_over_time(%s [1d]))', $sel));
        $zero = $this->scalar(sprintf('sum(count_over_time(%s | json returned | returned="0" [1d]))', $sel));

        // A re-search is a search by the same logged-in member within 60s of their previous one.
        // Logged-out searches log user_id 0 and carry no session id, so they cannot be linked and
        // are not counted here: the figure is a floor, not the whole.
        $byUser = [];
        foreach ($this->lines($sel.' | json', $day, $day->copy()->addDay()) as $line) {
            $uid = (int) ($line['user_id'] ?? 0);
            if ($uid <= 0 || empty($line['timestamp'])) {
                continue;
            }
            try {
                $byUser[$uid][] = Carbon::parse($line['timestamp'])->getTimestamp();
            } catch (\Throwable) {
                continue;
            }
        }
        $re = 0;
        foreach ($byUser as $times) {
            sort($times);
            for ($i = 1; $i < count($times); $i++) {
                if ($times[$i] - $times[$i - 1] <= 60) {
                    $re++;
                }
            }
        }

        $end = $day->copy()->addDay()->toDateTimeString();
        $browse = DB::table('browse_scroll_depth')
            ->where('created_at', '>=', $day->toDateTimeString())->where('created_at', '<', $end)
            ->where(fn ($q) => $q->whereNull('context')->orWhere('context', '!=', 'search'));
        $sessions = (clone $browse)->count();
        // Reached the last item the feed had when it was recorded.
        $out = (clone $browse)->where('items_available', '>', 0)->whereColumn('max_position', '>=', DB::raw('items_available - 1'))->count();

        return [
            'searches' => $total, 'zero_result' => $zero, 're_searched_60s' => $re,
            'browse_sessions' => $sessions, 'browse_scrolled_out' => $out,
        ];
    }

    // --------------------------------------------------------------- health

    private function collectHealth(Carbon $day, array $started): array
    {
        $rows = [];
        $add = function (string $metric, float $observed, ?float $expected) use (&$rows) {
            $rows[] = [
                'metric' => $metric, 'expected' => $expected, 'observed' => $observed,
                'ratio' => $expected !== null && $expected > 0 ? round($observed / $expected, 3) : null,
            ];
        };

        foreach ((array) config('freegle.ux.flow_entry_routes', []) as $flow => $routes) {
            if (in_array($flow, self::STARTS_ON_ACTION, true)) {
                continue;
            }
            $routes = array_values(array_filter((array) $routes, fn ($r) => is_string($r) && preg_match('/^[A-Za-z0-9_-]+$/', $r)));
            if (! $routes) {
                continue;
            }
            $views = $this->scalar(sprintf(
                'sum(count_over_time(%s | json page_route | page_route=~"%s" [1d]))', $this->selector('page_view'), self::routeRegex($routes)
            ));
            $add('flow_start:'.$flow, (float) ($started[$flow] ?? 0), (float) $views);
        }

        foreach (self::EVENT_TYPES as $type) {
            $observed = $this->scalar(sprintf('sum(count_over_time(%s [1d]))', $this->selector($type)));
            $expected = $this->baselineMedian('event:'.$type, $day);
            $add('event:'.$type, (float) $observed, $expected);
        }

        return $rows;
    }

    /**
     * The first day a metric is collected the way it is today, or null when it has never changed.
     * Days before it are a different measurement (raw counts of lines the client now samples, or
     * lines that did not exist yet), so they are not a baseline for today.
     */
    public static function healthChangePoint(string $metric): ?string
    {
        $point = config('freegle.ux.health_change_points.'.$metric);

        return is_string($point) && $point !== '' ? $point : null;
    }

    /**
     * Whether an event-metric alarm on $date rests on a trustworthy baseline: the same minimum
     * number of like-for-like days must have passed since the metric's change point. Used by the
     * weekly report to ignore rows stored before this rule existed.
     */
    public static function healthAlarmTrusted(string $metric, string $date): bool
    {
        $point = self::healthChangePoint($metric);
        if ($point === null) {
            return true;
        }

        return Carbon::parse($date)->gte(Carbon::parse($point)->addDays(self::MIN_BASELINE_DAYS));
    }

    /**
     * Median of the metric's own recent days, counting only days collected the same way as today
     * (on or after its change point) and ignoring days with nothing recorded. Null, meaning
     * "not enough history", below MIN_BASELINE_DAYS days, so no alarm is raised on a thin base.
     */
    private function baselineMedian(string $metric, Carbon $day): ?float
    {
        $from = $day->copy()->subDays(self::BASELINE_WINDOW_DAYS)->toDateString();
        $point = self::healthChangePoint($metric);
        if ($point !== null && $point > $from) {
            $from = $point;
        }

        $history = DB::table('ux_telemetry_health_daily')
            ->where('metric', $metric)
            ->where('date', '>=', $from)->where('date', '<', $day->toDateString())
            ->where('observed', '>', 0)
            ->pluck('observed')->map(fn ($v) => (float) $v)->sort()->values()->all();
        if (count($history) < self::MIN_BASELINE_DAYS) {
            return null;
        }
        $mid = intdiv(count($history), 2);

        return count($history) % 2 ? $history[$mid] : ($history[$mid - 1] + $history[$mid]) / 2;
    }

    // -------------------------------------------------------------- samples

    private function collectSamples(Carbon $day): array
    {
        $cap = (int) config('freegle.ux.sample_per_step', 20);
        $rows = [];
        foreach (self::FLOWS as $flow) {
            $logql = sprintf('%s | json | flow="%s" | action_name="flow_abandon"', $this->flowSelector(), $flow);
            $seen = [];
            foreach ($this->lines($logql, $day, $day->copy()->addDay()) as $line) {
                $session = (string) ($line['session_id'] ?? '');
                $step = mb_substr((string) ($line['last_step'] ?? $line['step'] ?? ''), 0, 64);
                if ($session === '' || isset($seen[$step][$session]) || count($seen[$step] ?? []) >= $cap) {
                    continue;
                }
                $seen[$step][$session] = true;
                $rows[] = ['flow' => $flow, 'step' => $step, 'signal' => 'abandon', 'session_id' => mb_substr($session, 0, 64)];
            }
        }

        return $rows;
    }

    // ----------------------------------------------------------------- Loki

    private ?Carbon $day = null;

    /** Evaluate a metric query once, over the day, and return [[labels, value], ...]. */
    private function metric(string $logql, bool $skipOnLimit = false): array
    {
        try {
            return $this->metricOrThrow($logql);
        } catch (UxSeriesLimit $e) {
            if (! $skipOnLimit) {
                throw $e;
            }
            Log::warning('ux:rollup-daily: skipped a query over the series limit: '.$e->getMessage());

            return [];
        }
    }

    private function metricOrThrow(string $logql): array
    {
        $end = $this->day->copy()->addDay();
        $series = $this->loki->queryMetricRange($logql, $end->copy()->subSecond(), $end, 86400);
        if ($series === null) {
            // Only the series limit is a property of the query. Any other refusal (a syntax
            // error, a rate limit, a timeout) must abort the day rather than write it short.
            if ($this->loki->lastMetricStatus === 400
                && preg_match('/maximum (number )?of series/i', (string) $this->loki->lastMetricError)) {
                throw new UxSeriesLimit('HTTP 400: '.substr($logql, 0, 200));
            }
            throw new UxLokiUnavailable('metric query failed: '.substr($logql, 0, 200));
        }

        $out = [];
        foreach ($series as $s) {
            if ($s['points']) {
                $out[] = [$s['labels'], (float) end($s['points'])];
            }
        }

        return $out;
    }

    private function scalar(string $logql): int
    {
        $total = 0.0;
        foreach ($this->metric($logql) as [, $v]) {
            $total += $v;
        }

        return (int) round($total);
    }

    /**
     * Every line a query matches in [start, end), paged by halving the window until each part
     * comes back under Loki's line limit (the decoded lines carry no reliable timestamp to page
     * from).
     *
     * @return array<int, array<string, mixed>>
     */
    private function lines(string $logql, Carbon $start, Carbon $end): array
    {
        $rows = $this->loki->queryRange($logql, $start, $end, self::LINE_LIMIT);
        if ($rows === null) {
            throw new UxLokiUnavailable('line query failed: '.substr($logql, 0, 200));
        }

        if (count($rows) < self::LINE_LIMIT) {
            return $rows;
        }

        $span = $end->getTimestamp() - $start->getTimestamp();
        if ($span <= 1) {
            Log::warning('ux:rollup-daily: more than '.self::LINE_LIMIT.' lines in one second, truncated: '.substr($logql, 0, 200));

            return $rows;
        }

        $mid = $start->copy()->addSeconds(intdiv($span, 2));

        return array_merge($this->lines($logql, $start, $mid), $this->lines($logql, $mid, $end));
    }
}
