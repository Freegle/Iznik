<?php

namespace App\Services\WhatJobs;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Keeps whatjobs_clicks_daily and decides when WhatJobs' clicks have changed suddenly.
 *
 * What counts as expected was measured on January to November 2026:
 * - Our own logged job ad clicks (logs_jobs) follow WhatJobs' billable clicks day to day
 *   (r 0.80), and WhatJobs bill about two thirds of them. That share is the steadiest figure
 *   there is, so a change in it is WhatJobs counting differently.
 * - Site activity (active members) hardly moves the clicks from day to day (r about 0.2 once
 *   the trend is taken out), and over the year it rose while clicks fell. It cannot predict a
 *   day; it can only excuse one when the whole site was quieter or busier, so it is used that way.
 * - A day's billable clicks are between half and 1.6 times the median of the 28 days before on
 *   97% of days. Every day outside that was a real event: the feed import failing (11 to 14 May),
 *   fewer jobs showing (16 to 25 June, 26 to 28 August), or a one-day spike.
 * - Seven days are between 0.75 and 1.3 times the median of the four weeks before on 90% of weeks.
 */
class ClickMonitorService
{
    public const TABLE = 'whatjobs_clicks_daily';

    public const DAY_LOW = 0.5;

    public const DAY_HIGH = 1.6;

    public const SHARE_LOW = 0.7;

    public const SHARE_HIGH = 1.4;

    public const WEEK_LOW = 0.75;

    public const WEEK_HIGH = 1.3;

    /** Within this of usual, a figure is "as usual" when explaining a change. */
    private const STEADY_LOW = 0.8;

    private const STEADY_HIGH = 1.25;

    public const BASELINE_DAYS = 28;

    public const MIN_BASELINE_DAYS = 14;

    /** WhatJobs: "Daily click data requires 48 hours to finalise." */
    public const FINAL_AFTER_DAYS = 2;

    /** A day first judged when older than this is history being filled in, not news. */
    public const NEWS_WITHIN_DAYS = 9;

    /** logs_jobs keeps 31 days (WhatJobsService::analyseClickability); stay clear of the edge. */
    public const OUR_CLICKS_KEPT_DAYS = 29;

    /**
     * Store a month of dashboard figures. A day already stored takes the new figures, because
     * WhatJobs revises them, and keeps the billable figure it was first seen with.
     *
     * @param  array<string, array<string, int>>  $days  date => [column => count]
     * @return int days stored
     */
    public function storePartnerDays(array $days, CarbonInterface $today): int
    {
        $stored = 0;
        foreach ($days as $date => $counts) {
            if ($date >= $today->toDateString()) {
                continue;
            }
            $row = DB::table(self::TABLE)->where('date', $date)->first();
            if ($row) {
                DB::table(self::TABLE)->where('id', $row->id)->update($counts + [
                    'first_billable_clicks' => $row->first_billable_clicks ?? $counts['billable_clicks'],
                    'partner_seen_at' => $row->partner_seen_at ?? now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table(self::TABLE)->insert($counts + [
                    'date' => $date,
                    'first_billable_clicks' => $counts['billable_clicks'],
                    'partner_seen_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $stored++;
        }

        return $stored;
    }

    /**
     * Record our own figures for whole days: the job ad clicks our pages logged, and how many
     * members were active. Both source tables are pruned, so the figures are kept here. The last
     * seven days are counted again on every run; an older day only when it has no figure yet, from
     * $since on. Clicks are only counted while logs_jobs still holds the whole day.
     */
    public function snapshotOurSide(CarbonInterface $since, CarbonInterface $today): void
    {
        $today = CarbonImmutable::parse($today)->startOfDay();
        $recent = $today->subDays(7);
        $dates = DB::table(self::TABLE)
            ->where('date', '>=', CarbonImmutable::parse($since)->toDateString())
            ->where('date', '<', $recent->toDateString())
            ->whereNull('active_users')
            ->pluck('date')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
            ->all();
        for ($d = $recent; $d->lt($today); $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        $oldestClicks = $today->subDays(self::OUR_CLICKS_KEPT_DAYS)->toDateString();
        foreach ($dates as $date) {
            $d = CarbonImmutable::parse($date);
            $range = [$d->toDateTimeString(), $d->addDay()->toDateTimeString()];
            $values = [
                'active_users' => (int) DB::table('users_active')
                    ->where('timestamp', '>=', $range[0])->where('timestamp', '<', $range[1])
                    ->distinct()->count('userid'),
            ];
            if ($date >= $oldestClicks) {
                $values['our_clicks'] = DB::table('logs_jobs')
                    ->where('timestamp', '>=', $range[0])->where('timestamp', '<', $range[1])
                    ->count();
            }

            if (DB::table(self::TABLE)->where('date', $date)->exists()) {
                DB::table(self::TABLE)->where('date', $date)->update($values + ['updated_at' => now()]);
            } else {
                DB::table(self::TABLE)->insert($values + ['date' => $date, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /** The newest day WhatJobs has published figures for. */
    public function latestPartnerDate(): ?string
    {
        $latest = DB::table(self::TABLE)->whereNotNull('billable_clicks')->max('date');

        return $latest ? CarbonImmutable::parse($latest)->toDateString() : null;
    }

    /**
     * Judge each day WhatJobs has finalised and that has not been judged, once. Days older than
     * NEWS_WITHIN_DAYS are marked judged without a verdict: they are history being filled in.
     *
     * @return list<array<string, mixed>> the days that changed suddenly, oldest first
     */
    public function judge(CarbonInterface $today): array
    {
        $final = CarbonImmutable::parse($today)->subDays(self::FINAL_AFTER_DAYS)->toDateString();
        $news = CarbonImmutable::parse($today)->subDays(self::NEWS_WITHIN_DAYS)->toDateString();

        DB::table(self::TABLE)->whereNull('judged_at')->whereNotNull('billable_clicks')
            ->where('date', '<', $news)->update(['judged_at' => now()]);

        $all = $this->rows();
        $findings = [];
        foreach ($all as $date => $row) {
            if ($row->judged_at !== null || $row->billable_clicks === null || $date > $final) {
                continue;
            }
            $finding = $this->assess($date, $all);
            $alert = $finding ? implode(',', $finding['codes']) : null;
            DB::table(self::TABLE)->where('id', $row->id)->update(['judged_at' => now(), 'alert' => $alert]);
            $all[$date]->alert = $alert;
            if ($finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Every stored day, keyed by date.
     *
     * @return array<string, object>
     */
    public function rows(?string $from = null, ?string $to = null): array
    {
        $query = DB::table(self::TABLE)->orderBy('date');
        if ($from) {
            $query->where('date', '>=', $from);
        }
        if ($to) {
            $query->where('date', '<=', $to);
        }
        $out = [];
        foreach ($query->get() as $row) {
            $row->date = CarbonImmutable::parse($row->date)->toDateString();
            $out[$row->date] = $row;
        }

        return $out;
    }

    /**
     * Whether one day changed suddenly, and the figures that explain it.
     *
     * @param  array<string, object>  $all
     * @return array<string, mixed>|null
     */
    public function assess(string $date, array $all): ?array
    {
        $day = $all[$date];
        $base = $this->window($all, $date, self::BASELINE_DAYS);
        $billables = array_values(array_filter(array_map(fn ($r) => $r->billable_clicks, $base), fn ($v) => $v !== null));
        if (count($billables) < self::MIN_BASELINE_DAYS || self::median($billables) <= 0) {
            return null;
        }

        $usual = self::median($billables);
        $b = $day->billable_clicks / $usual;
        $activeUsual = $this->usual($base, 'active_users');
        $a = ($activeUsual && $day->active_users) ? $day->active_users / $activeUsual : null;
        $ourUsual = $this->usual($base, 'our_clicks');
        $shareUsual = $this->usualShare($base);
        $share = $day->our_clicks ? $day->billable_clicks / $day->our_clicks : null;

        $codes = [];
        if (self::outside($b, self::DAY_LOW, self::DAY_HIGH) && ($a === null || self::outside($b / $a, self::DAY_LOW, self::DAY_HIGH))) {
            $codes[] = 'day-'.self::direction($b);
        }
        if ($share !== null && $shareUsual && self::outside($share / $shareUsual, self::SHARE_LOW, self::SHARE_HIGH)) {
            $codes[] = 'share-'.self::direction($share / $shareUsual);
        }

        $week = $this->weekRatio($date, $all);
        if ($week && $week['out']) {
            $yesterday = $this->weekRatio(CarbonImmutable::parse($date)->subDay()->toDateString(), $all);
            $dir = self::direction($week['ratio']);
            $continuing = $yesterday && $yesterday['out'] && self::direction($yesterday['ratio']) === $dir;
            if (! $continuing && ! $this->recentAlert($all, $date, "day-{$dir}")) {
                $codes[] = "week-{$dir}";
            }
        }

        if (! $codes) {
            return null;
        }

        return [
            'date' => $date,
            'codes' => $codes,
            'billable' => (int) $day->billable_clicks,
            'usual' => $usual,
            'our' => $day->our_clicks === null ? null : (int) $day->our_clicks,
            'ourUsual' => $ourUsual,
            'active' => $day->active_users === null ? null : (int) $day->active_users,
            'activeUsual' => $activeUsual,
            'share' => $share,
            'shareUsual' => $shareUsual,
            'discarded' => [
                'invalid' => (int) $day->invalid_clicks,
                'duplicate' => (int) $day->duplicate_clicks,
                'foreign' => (int) $day->foreign_clicks,
                'latent' => (int) $day->latent_clicks,
            ],
            'week' => $week,
        ];
    }

    /**
     * The seven days to $date against the median of the four seven-day blocks before them.
     * The median, not the mean, so a week after an outage is not read as a rise.
     *
     * @param  array<string, object>  $all
     * @return array{ratio: float, sum: int, usual: float, our: ?float, active: ?float, out: bool}|null
     */
    public function weekRatio(string $date, array $all): ?array
    {
        $end = CarbonImmutable::parse($date);
        $blocks = [];
        for ($i = 0; $i < 5; $i++) {
            $rows = $this->window($all, $end->subDays(7 * $i)->toDateString(), 7, true);
            if (count($rows) < 7 || array_filter($rows, fn ($r) => $r->billable_clicks === null)) {
                return null;
            }
            $blocks[] = $rows;
        }
        $sum = fn (array $rows, string $col) => array_sum(array_map(fn ($r) => (int) $r->$col, $rows));
        $complete = fn (array $rows, string $col) => ! array_filter($rows, fn ($r) => ! $r->$col);
        $usual = self::median(array_map(fn ($rows) => $sum($rows, 'billable_clicks'), array_slice($blocks, 1)));
        if ($usual <= 0) {
            return null;
        }
        $ratio = $sum($blocks[0], 'billable_clicks') / $usual;

        $ratioOf = function (string $col) use ($blocks, $sum, $complete): ?float {
            foreach ($blocks as $rows) {
                if (! $complete($rows, $col)) {
                    return null;
                }
            }
            $usual = self::median(array_map(fn ($rows) => $sum($rows, $col), array_slice($blocks, 1)));

            return $usual > 0 ? $sum($blocks[0], $col) / $usual : null;
        };
        $active = $ratioOf('active_users');

        return [
            'ratio' => $ratio,
            'sum' => $sum($blocks[0], 'billable_clicks'),
            'usual' => $usual,
            'our' => $ratioOf('our_clicks'),
            'active' => $active,
            'out' => self::outside($ratio, self::WEEK_LOW, self::WEEK_HIGH)
                && ($active === null || self::outside($ratio / $active, self::WEEK_LOW, self::WEEK_HIGH)),
        ];
    }

    /**
     * A finding in words: a headline and the reading of what our own figures say about it.
     *
     * @param  array<string, mixed>  $f  from assess()
     * @return array{headline: string, lines: list<string>, reading: string}
     */
    public function describe(array $f): array
    {
        $when = CarbonImmutable::parse($f['date'])->format('l j F');
        $lines = [];
        $isDay = (bool) array_filter($f['codes'], fn ($c) => str_starts_with($c, 'day-') || str_starts_with($c, 'share-'));

        if ($isDay) {
            $headline = sprintf('%s: %s billable clicks, against %s on a usual day lately (%s).',
                $when, number_format($f['billable']), number_format($f['usual']), self::change($f['billable'] / $f['usual']));
            $dir = self::direction($f['billable'] / $f['usual']);
        } else {
            $w = $f['week'];
            $headline = sprintf('The seven days to %s: %s billable clicks, against %s in a usual week lately (%s).',
                $when, number_format($w['sum']), number_format($w['usual']), self::change($w['ratio']));
            $dir = self::direction($w['ratio']);
        }
        if ($isDay && in_array('week-'.$dir, $f['codes'], true)) {
            $lines[] = sprintf('The seven days to then were %s on the usual week.', self::change($f['week']['ratio']));
        }

        $o = ($f['our'] !== null && $f['ourUsual']) ? $f['our'] / $f['ourUsual'] : null;
        $a = ($f['active'] !== null && $f['activeUsual']) ? $f['active'] / $f['activeUsual'] : null;
        if (! $isDay) {
            $o = $f['week']['our'];
            $a = $f['week']['active'];
        }
        if ($isDay && $f['our'] !== null && $f['ourUsual']) {
            $lines[] = sprintf('Job ad clicks in our own log: %s, usually %s (%s).', number_format($f['our']), number_format($f['ourUsual']), self::change($o));
        }
        if ($isDay && $f['share'] !== null && $f['shareUsual']) {
            $lines[] = sprintf('WhatJobs billed %d%% of them, usually %d%%.', round(100 * $f['share']), round(100 * $f['shareUsual']));
        }
        if ($isDay && $f['active'] !== null && $f['activeUsual']) {
            $lines[] = sprintf('Active members: %s, usually %s (%s).', number_format($f['active']), number_format($f['activeUsual']), self::change($a));
        }
        if ($isDay) {
            $d = $f['discarded'];
            $lines[] = sprintf('Not billed by WhatJobs: %d invalid, %d duplicate, %d foreign, %d latent.', $d['invalid'], $d['duplicate'], $d['foreign'], $d['latent']);
        } else {
            if ($o !== null) {
                $lines[] = sprintf('Job ad clicks in our own log over the same days: %s.', self::change($o));
            }
            if ($a !== null) {
                $lines[] = sprintf('Active members over the same days: %s.', self::change($a));
            }
        }

        return ['headline' => $headline, 'lines' => $lines, 'reading' => self::reading($f['codes'], $dir, $o, $a)];
    }

    /** What our own figures say about where a change came from. */
    public static function reading(array $codes, string $dir, ?float $ours, ?float $active): string
    {
        $steady = fn (?float $r) => $r !== null && $r >= self::STEADY_LOW && $r <= self::STEADY_HIGH;
        $same = fn (?float $r) => $r !== null && ($dir === 'drop' ? $r < self::STEADY_LOW : $r > self::STEADY_HIGH);
        $share = array_values(array_filter($codes, fn ($c) => str_starts_with($c, 'share-')));

        if ($share && ($ours === null || $steady($ours))) {
            return 'Members clicked job ads as usual, but WhatJobs billed a different share of those clicks. '
                .'That is on their side: the Invalid, Duplicate, Foreign and Latent columns on their dashboard show where the clicks went.';
        }
        if ($share) {
            $smaller = $share[0] === 'share-drop';

            return sprintf('Both moved: our own log shows %s job ad clicks than usual, and WhatJobs billed a %s share of them.%s',
                $ours > 1 ? 'more' : 'fewer', $smaller ? 'smaller' : 'larger',
                $ours > 1 && $smaller ? ' That fits repeated or automated clicks, which WhatJobs do not pay for.'
                    : ($ours < 1 && ! $smaller ? ' Check that our click log is still recording every click.' : ''));
        }
        if ($same($ours) && ($active === null || $steady($active))) {
            return $dir === 'drop'
                ? 'Our own log shows fewer job ad clicks too, while the site was as busy as usual. That points at our side: '
                    .'check that job ads are showing on the site and in emails, and that the jobs feed is fresh.'
                : 'Our own log shows more job ad clicks too, while the site was no busier. Something sent more people to job ads: '
                    .'a page or email change, or automated clicks.';
        }
        if ($active !== null && ! $steady($active)) {
            return $active < 1
                ? 'The site itself was quieter than usual, which accounts for some of it.'
                : 'The site itself was busier than usual, which accounts for some of it.';
        }
        if ($ours === null) {
            return 'Our own click log has no figures for these days to compare.';
        }

        return 'Neither our own clicks nor site activity explain it, so the change is in what WhatJobs counted.';
    }

    /**
     * The weekly summary for the latest Monday to Sunday that WhatJobs has finalised.
     *
     * @return array<string, mixed>|null null when there is nothing stored for that week
     */
    public function weeklySummary(CarbonInterface $today): ?array
    {
        $end = CarbonImmutable::parse($today)->startOfDay()->subDays(self::FINAL_AFTER_DAYS);
        if (! $end->isSunday()) {
            $end = $end->previous(CarbonInterface::SUNDAY);
        }
        $start = $end->subDays(6);
        $all = $this->rows();
        $week = [];
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $week[$d->toDateString()] = $all[$d->toDateString()] ?? (object) [
                'date' => $d->toDateString(), 'billable_clicks' => null, 'first_billable_clicks' => null,
                'duplicate_clicks' => null, 'invalid_clicks' => null, 'foreign_clicks' => null, 'latent_clicks' => null,
                'our_clicks' => null, 'active_users' => null, 'alert' => null,
            ];
        }
        if (! array_filter($week, fn ($r) => $r->billable_clicks !== null)) {
            return null;
        }

        // A day with no clicks counts (an outage is the news); no active members means the
        // activity log was not being written that day, so it does not.
        $perDay = function (CarbonImmutable $from, CarbonImmutable $to, string $col) use ($all): ?float {
            $vals = [];
            foreach ($all as $date => $r) {
                $recorded = $col === 'active_users' ? (bool) $r->$col : $r->$col !== null;
                if ($date >= $from->toDateString() && $date <= $to->toDateString() && $recorded) {
                    $vals[] = (int) $r->$col;
                }
            }

            return $vals ? array_sum($vals) / count($vals) : null;
        };
        $periods = [
            'week' => [$start, $end],
            'lastWeek' => [$start->subDays(7), $end->subDays(7)],
            'fourWeeks' => [$start->subDays(28), $end->subDays(7)],
            'twelveWeeks' => [$start->subDays(77), $end],
            'twelveBefore' => [$start->subDays(161), $start->subDays(78)],
        ];
        $figures = [];
        foreach ($periods as $name => [$from, $to]) {
            foreach (['billable_clicks', 'our_clicks', 'active_users'] as $col) {
                $figures[$name][$col] = $perDay($from, $to, $col);
            }
        }

        // Revisions only show on days first read while WhatJobs could still change them; a day
        // first read weeks later (history filled in) was already final.
        $watchedDays = 0;
        $revisedDays = 0;
        $revisedBy = 0;
        foreach ($this->window($all, $end->toDateString(), 28, true) as $date => $r) {
            if ($r->billable_clicks === null || $r->first_billable_clicks === null || ! $r->partner_seen_at
                || CarbonImmutable::parse($r->partner_seen_at)->gt(CarbonImmutable::parse($date)->addDays(self::FINAL_AFTER_DAYS + 1))) {
                continue;
            }
            $watchedDays++;
            if ($r->billable_clicks != $r->first_billable_clicks) {
                $revisedDays++;
                $revisedBy += $r->billable_clicks - $r->first_billable_clicks;
            }
        }

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => $week,
            'figures' => $figures,
            'weekRatio' => $this->weekRatio($end->toDateString(), $all),
            'missing' => array_keys(array_filter($week, fn ($r) => $r->billable_clicks === null)),
            'alerts' => array_values(array_filter($week, fn ($r) => $r->alert !== null)),
            'watchedDays' => $watchedDays,
            'revisedDays' => $revisedDays,
            'revisedBy' => $revisedBy,
            'latest' => $this->latestPartnerDate(),
            'weeks' => $this->weeklySeries($all, $end),
        ];
    }

    /**
     * Daily averages per Monday-to-Sunday week, for the trend chart, up to two years back.
     * A day whose active-member count is under a tenth of the series median was a day the
     * activity log was not being written (before 30 March 2026), not a quiet day, so it is left out.
     *
     * @param  array<string, object>  $all
     * @return list<array{start: string, billable: ?float, ours: ?float, active: ?float}>
     */
    public function weeklySeries(array $all, CarbonImmutable $end, int $maxWeeks = 104): array
    {
        $actives = array_values(array_filter(array_map(fn ($r) => (int) $r->active_users, $all)));
        $floor = $actives ? self::median($actives) / 10 : 0;
        $weeks = [];
        foreach ($all as $date => $r) {
            if ($date > $end->toDateString()) {
                continue;
            }
            $monday = CarbonImmutable::parse($date)->startOfWeek(CarbonInterface::MONDAY)->toDateString();
            $w = $weeks[$monday] ??= ['start' => $monday, 'billable' => [], 'ours' => [], 'active' => []];
            if ($r->billable_clicks !== null) {
                $w['billable'][] = (int) $r->billable_clicks;
            }
            if ($r->our_clicks !== null) {
                $w['ours'][] = (int) $r->our_clicks;
            }
            if ($r->active_users && $r->active_users >= $floor) {
                $w['active'][] = (int) $r->active_users;
            }
            $weeks[$monday] = $w;
        }
        $avg = fn (array $v, int $min) => count($v) >= $min ? array_sum($v) / count($v) : null;
        $series = [];
        foreach ($weeks as $w) {
            $series[] = ['start' => $w['start'], 'billable' => $avg($w['billable'], 7), 'ours' => $avg($w['ours'], 7), 'active' => $avg($w['active'], 7)];
        }
        // Drop leading weeks with no clicks at all (a part week at the start of the history).
        while ($series && $series[0]['billable'] === null) {
            array_shift($series);
        }

        return array_slice($series, -$maxWeeks);
    }

    /**
     * The $days rows ending on $end (inclusive) when $inclusive, else ending the day before $end.
     *
     * @param  array<string, object>  $all
     * @return array<string, object>
     */
    private function window(array $all, string $end, int $days, bool $inclusive = false): array
    {
        $to = CarbonImmutable::parse($end);
        if (! $inclusive) {
            $to = $to->subDay();
        }
        $from = $to->subDays($days - 1)->toDateString();
        $to = $to->toDateString();

        return array_filter($all, fn ($r, $date) => $date >= $from && $date <= $to, ARRAY_FILTER_USE_BOTH);
    }

    /** The median of a column over some rows, ignoring days without a figure. */
    private function usual(array $rows, string $col): ?float
    {
        $vals = array_values(array_filter(array_map(fn ($r) => $r->$col, $rows)));

        return count($vals) >= self::MIN_BASELINE_DAYS ? self::median($vals) : null;
    }

    /** The median share of our clicks that WhatJobs billed. */
    private function usualShare(array $rows): ?float
    {
        $shares = [];
        foreach ($rows as $r) {
            if ($r->our_clicks && $r->billable_clicks !== null) {
                $shares[] = $r->billable_clicks / $r->our_clicks;
            }
        }

        return count($shares) >= self::MIN_BASELINE_DAYS ? self::median($shares) : null;
    }

    private function recentAlert(array $all, string $date, string $code): bool
    {
        foreach ($this->window($all, $date, 6) as $r) {
            if ($r->alert && in_array($code, explode(',', $r->alert), true)) {
                return true;
            }
        }

        return false;
    }

    public static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    public static function outside(float $ratio, float $low, float $high): bool
    {
        return $ratio < $low || $ratio > $high;
    }

    public static function direction(float $ratio): string
    {
        return $ratio < 1 ? 'drop' : 'rise';
    }

    /** "down 12%", "up 40%" or "about the same". */
    public static function change(?float $ratio): string
    {
        if ($ratio === null) {
            return 'no figure';
        }
        $pct = (int) round(100 * ($ratio - 1));
        if (abs($pct) < 5) {
            return 'about the same';
        }

        return $pct < 0 ? 'down '.abs($pct).'%' : "up {$pct}%";
    }
}
