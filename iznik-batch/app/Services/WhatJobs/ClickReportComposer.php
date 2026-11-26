<?php

namespace App\Services\WhatJobs;

use App\Mail\WhatJobs\WhatJobsClicksMail;
use Carbon\CarbonImmutable;

/**
 * Turns the monitor's findings and weekly figures into the WhatJobs clicks emails.
 */
class ClickReportComposer
{
    private const HOW_JUDGED = 'A day is flagged when its billable clicks are under half or over 1.6 times the median of '
        .'the 28 days before and the number of active members does not account for it, or when WhatJobs bill under 0.7 '
        .'or over 1.4 times their usual share of the clicks in our own log. Seven days are flagged when they are under '
        .'0.75 or over 1.3 times the median of the four weeks before. WhatJobs publish a day at midnight and take '
        .'48 hours more to finalise it, so each day is judged three days later.';

    public function __construct(private readonly ClickMonitorService $monitor) {}

    /**
     * @param  list<array<string, mixed>>  $findings  from ClickMonitorService::judge()
     */
    public function alert(array $findings): WhatJobsClicksMail
    {
        $sections = [];
        foreach ($findings as $f) {
            $text = $this->monitor->describe($f);
            $sections[] = ['heading' => $text['headline'], 'paragraphs' => [...$text['lines'], $text['reading']]];
        }
        $sections[] = ['heading' => 'How this is judged', 'paragraphs' => [self::HOW_JUDGED]];

        $last = end($findings);
        $dir = str_ends_with($last['codes'][0], 'rise') ? 'rise' : 'drop';
        $when = CarbonImmutable::parse($last['date'])->format('l j F');
        $subject = count($findings) > 1
            ? sprintf('WhatJobs clicks: %d sudden changes, latest %s', count($findings), $when)
            : (str_starts_with($last['codes'][0], 'week-')
                ? "WhatJobs clicks: a week-long {$dir} to {$when}"
                : "WhatJobs clicks: sudden {$dir} on {$when}");

        // A title, each finding, the chart, then how it is judged.
        array_unshift($sections, ['heading' => $subject, 'paragraphs' => []]);

        $end = CarbonImmutable::parse($last['date']);
        $points = [];
        foreach ($this->monitor->rows($end->subDays(55)->toDateString(), $end->toDateString()) as $date => $r) {
            $d = CarbonImmutable::parse($date);
            $points[] = [
                'label' => $d->isMonday() ? $d->format('j M') : null,
                'billable' => $r->billable_clicks === null ? null : (float) $r->billable_clicks,
                'ours' => $r->our_clicks === null ? null : (float) $r->our_clicks,
                'active' => $r->active_users ? (float) $r->active_users : null,
            ];
        }

        return new WhatJobsClicksMail(
            $subject,
            $sections,
            null,
            $points ? ClickTrendChart::render($points) : null,
            'Daily billable clicks, our own logged job ad clicks and active members over the last eight weeks.',
            count($findings),
        );
    }

    /**
     * @param  array<string, mixed>  $s  from ClickMonitorService::weeklySummary()
     */
    public function weekly(array $s): WhatJobsClicksMail
    {
        $start = CarbonImmutable::parse($s['start']);
        $end = CarbonImmutable::parse($s['end']);
        $f = $s['figures'];
        $billed = array_sum(array_map(fn ($r) => (int) $r->billable_clicks, $s['days']));
        $w = $s['weekRatio'];

        $headline = [];
        $recorded = 7 - count($s['missing']);
        $headline[] = sprintf('%s billable clicks%s, %s a day. The week before: %s a day.',
            number_format($billed), $recorded < 7 ? " on the {$recorded} days with figures" : '',
            self::n($f['week']['billable_clicks']), self::n($f['lastWeek']['billable_clicks']));
        if ($w) {
            $inRange = ! ClickMonitorService::outside($w['ratio'], ClickMonitorService::WEEK_LOW, ClickMonitorService::WEEK_HIGH);
            $headline[] = sprintf('A usual week lately (the median of the four before) was %s, so this week was %s. %s',
                number_format($w['usual']), ClickMonitorService::change($w['ratio']),
                $inRange ? 'That is within the usual range.' : 'That is outside the usual range of 0.75 to 1.3 times.');
        }
        if ($f['week']['our_clicks']) {
            $headline[] = sprintf('Job ad clicks in our own log: %s a day, of which WhatJobs billed %d%% (%s the week before).',
                self::n($f['week']['our_clicks']), round(100 * $f['week']['billable_clicks'] / $f['week']['our_clicks']),
                $f['lastWeek']['our_clicks'] ? round(100 * $f['lastWeek']['billable_clicks'] / $f['lastWeek']['our_clicks']).'%' : 'no figure');
        }
        if ($f['week']['active_users']) {
            $headline[] = sprintf('Active members: %s a day (%s the week before).', self::n($f['week']['active_users']), self::n($f['lastWeek']['active_users']));
        }

        $trend = [];
        $now = $f['twelveWeeks'];
        $before = $f['twelveBefore'];
        if ($now['billable_clicks'] && $before['billable_clicks']) {
            $clicks = $now['billable_clicks'] / $before['billable_clicks'];
            $trend[] = sprintf('The last 12 weeks averaged %s billable clicks a day, against %s in the 12 weeks before (%s).',
                self::n($now['billable_clicks']), self::n($before['billable_clicks']), ClickMonitorService::change($clicks));
            if ($now['active_users'] && $before['active_users']) {
                $active = $now['active_users'] / $before['active_users'];
                $rate = $clicks / $active;
                $trend[] = sprintf('Active members over the same weeks: %s a day against %s (%s).',
                    self::n($now['active_users']), self::n($before['active_users']), ClickMonitorService::change($active));
                $trend[] = sprintf('Billable clicks per 1,000 active members: %s against %s (%s)%s',
                    number_format(1000 * $now['billable_clicks'] / $now['active_users'], 1),
                    number_format(1000 * $before['billable_clicks'] / $before['active_users'], 1),
                    ClickMonitorService::change($rate),
                    abs($rate - 1) < 0.05 ? ', so clicks per member have held steady.'
                        : ($rate < 1 ? ', so members click job ads less than they did, or WhatJobs bill fewer of the clicks.'
                            : ', so members click job ads more than they did.'));
            }
        } else {
            $trend[] = 'There are not yet 24 weeks of figures to compare.';
        }

        $notes = [];
        foreach ($s['alerts'] as $r) {
            $notes[] = sprintf('%s was flagged: %s.', CarbonImmutable::parse($r->date)->format('l j F'), self::codesInWords($r->alert));
        }
        if ($s['missing']) {
            $notes[] = 'There are no WhatJobs figures for '.implode(', ', array_map(fn ($d) => CarbonImmutable::parse($d)->format('j F'), $s['missing']))
                .': WhatJobs have not published them, or the dashboard could not be read.';
        }
        // Say nothing about revisions until a week of days has been read while still fresh.
        if ($s['watchedDays'] >= 7) {
            $notes[] = $s['revisedDays']
                ? sprintf('WhatJobs changed the billable figure for %d of the last %d days after first publishing it, by %+d clicks in all.', $s['revisedDays'], $s['watchedDays'], $s['revisedBy'])
                : sprintf('WhatJobs changed none of the last %d days\' billable figures after first publishing them.', $s['watchedDays']);
        }

        $rows = [];
        foreach ($s['days'] as $date => $r) {
            $notBilled = $r->billable_clicks === null ? null
                : (int) $r->duplicate_clicks + (int) $r->invalid_clicks + (int) $r->foreign_clicks + (int) $r->latent_clicks;
            $rows[] = [
                CarbonImmutable::parse($date)->format('D j M'),
                $r->billable_clicks === null ? '-' : number_format($r->billable_clicks),
                $notBilled === null ? '-' : number_format($notBilled),
                $r->our_clicks === null ? '-' : number_format($r->our_clicks),
                ($r->our_clicks && $r->billable_clicks !== null) ? round(100 * $r->billable_clicks / $r->our_clicks).'%' : '-',
                $r->active_users ? number_format($r->active_users) : '-',
            ];
        }

        $points = [];
        $month = null;
        foreach ($s['weeks'] as $week) {
            $d = CarbonImmutable::parse($week['start']);
            $label = $d->format('M') !== $month ? $d->format('M') : null;
            $month = $d->format('M');
            $points[] = ['label' => $label, 'billable' => $week['billable'], 'ours' => $week['ours'], 'active' => $week['active']];
        }

        $subject = sprintf('WhatJobs clicks, week to %s: %s billable%s',
            $end->format('l j F'), number_format($billed), $w ? ' ('.ClickMonitorService::change($w['ratio']).' on a usual week)' : '');

        return new WhatJobsClicksMail(
            $subject,
            array_values(array_filter([
                ['heading' => sprintf('WhatJobs clicks, %s to %s', $start->format($start->month === $end->month ? 'j' : 'j F'), $end->format('j F Y')), 'paragraphs' => $headline],
                ['heading' => 'The longer trend', 'paragraphs' => $trend],
                $notes ? ['heading' => 'Notes', 'paragraphs' => $notes] : null,
                ['heading' => 'Day by day', 'paragraphs' => []],
            ])),
            ['head' => ['Day', 'Billable', 'Not billed', 'Our log', 'Billed share', 'Active members'], 'rows' => $rows],
            $points ? ClickTrendChart::render($points) : null,
            'Weekly averages of billable clicks a day, our own logged job ad clicks a day and active members a day.',
        );
    }

    private static function n(?float $v): string
    {
        return $v === null ? 'no figure' : number_format($v);
    }

    private static function codesInWords(string $codes): string
    {
        $words = [
            'day-drop' => 'a sudden drop that day',
            'day-rise' => 'a sudden rise that day',
            'share-drop' => 'WhatJobs billed far fewer of our clicks than usual',
            'share-rise' => 'WhatJobs billed far more of our clicks than usual',
            'week-drop' => 'the seven days to then were well down on a usual week',
            'week-rise' => 'the seven days to then were well up on a usual week',
        ];

        return implode('; ', array_map(fn ($c) => $words[$c] ?? $c, explode(',', $codes)));
    }
}
