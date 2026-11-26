<?php

namespace App\Services\Servers;

use Carbon\CarbonInterface;

/**
 * Works out, from the stored readings and what Katapult offers, whether each server is the right
 * size. Arithmetic only: it reads no database and calls nothing, so every number in the weekly
 * report can be traced to a rule here.
 *
 * The rules, in one place:
 *  - A server is judged on its busy end, not its average: the level it stays under 95% of the time.
 *  - A smaller package is suggested only if that level would still leave headroom on it (load at
 *    most 70% of the processors, processor time at most 60% busy, memory at most 80% used).
 *  - A bigger package is suggested only when the server is past a higher mark (load above the
 *    number of processors, processor time above 85% busy, or memory above 92% used). Between the
 *    two marks the answer is "keep", so a server near a line does not flip from week to week.
 *  - Nothing is suggested until there are enough days of readings to have seen a normal fortnight.
 *  - A disk is flagged when it is 85% full or its growth would take it to 90% within eight weeks.
 *    Katapult disks can grow but never shrink.
 */
class ServerSizingAnalyser
{
    public const LOAD_HEADROOM = 0.7;

    public const BUSY_HEADROOM = 0.6;

    public const MEMORY_HEADROOM = 0.8;

    public const LOAD_TOO_HIGH = 1.0;

    public const BUSY_TOO_HIGH = 0.85;

    public const MEMORY_TOO_HIGH = 0.92;

    public const DISK_FULL = 0.85;

    public const DISK_TARGET = 0.9;

    public const DISK_WEEKS_AHEAD = 8;

    public const TREND_WEEKS = 8;

    /** A gap longer than this between two readings is not used to work out processor time. */
    private const MAX_INTERVAL_SECONDS = 3600;

    /**
     * @param  list<array{id: string, name: string, hostname: string, state: string, package_id: ?string, package_name: ?string, ipv4: int}>  $machines
     * @param  list<array{id: string, name: string, cpus: int, memory_gb: int, disk_gb: int, dedicated: bool}>  $packages
     * @param  list<array{name: string, size_gb: int, state: string, machine_id: ?string}>  $disks
     * @param  array{packages: array<string, float>, disk_gb: float, ipv4: float}  $prices
     * @param  array<string, list<array<string, mixed>>>  $samplesByHost  readings per hostname, oldest first
     * @return array<string, mixed>
     */
    public function analyse(
        array $machines,
        array $packages,
        array $disks,
        array $prices,
        array $samplesByHost,
        CarbonInterface $now,
        float $discount,
        int $minHistoryDays,
    ): array {
        $discount = min(1.0, max(0.0, $discount));
        $packagesById = [];
        foreach ($packages as $package) {
            $packagesById[$package['id']] = $package;
        }

        $servers = [];
        $fleetList = 0.0;
        $fleetSaving = 0.0;

        foreach ($machines as $machine) {
            $server = $this->analyseMachine(
                $machine,
                $packagesById,
                $disks,
                $prices,
                $samplesByHost[$machine['hostname']] ?? [],
                $now,
                $discount,
                $minHistoryDays,
            );
            $fleetList += $server['cost']['list'];
            if (in_array($server['verdict'], ['step_down'], true) && $server['candidate'] !== null) {
                $fleetSaving += $server['candidate']['saving_paid'];
            }
            $servers[] = $server;
        }

        usort($servers, fn (array $a, array $b) => $b['cost']['list'] <=> $a['cost']['list']);

        $unattached = [];
        $unattachedList = 0.0;
        foreach ($disks as $disk) {
            if ($disk['machine_id'] === null || $disk['state'] !== 'built') {
                $unattached[] = $disk;
                if ($disk['machine_id'] === null) {
                    $unattachedList += $disk['size_gb'] * $prices['disk_gb'];
                }
            }
        }
        $fleetList += $unattachedList;

        $historyDays = 0;
        foreach ($servers as $server) {
            $historyDays = max($historyDays, $server['history_days']);
        }

        return [
            'generated_at' => $now->toDateTimeString(),
            'discount' => $discount,
            'history_days' => $historyDays,
            'min_history_days' => $minHistoryDays,
            'fleet' => [
                'monthly_list' => round($fleetList, 2),
                'monthly_paid' => round($fleetList * (1 - $discount), 2),
                'possible_saving_paid' => round($fleetSaving, 2),
            ],
            'servers' => $servers,
            'unattached_disks' => $unattached,
        ];
    }

    /**
     * @param  array<string, mixed>  $machine
     * @param  array<string, array{id: string, name: string, cpus: int, memory_gb: int, disk_gb: int, dedicated: bool}>  $packagesById
     * @param  list<array{name: string, size_gb: int, state: string, machine_id: ?string}>  $disks
     * @param  array{packages: array<string, float>, disk_gb: float, ipv4: float}  $prices
     * @param  list<array<string, mixed>>  $samples
     * @return array<string, mixed>
     */
    private function analyseMachine(
        array $machine,
        array $packagesById,
        array $disks,
        array $prices,
        array $samples,
        CarbonInterface $now,
        float $discount,
        int $minHistoryDays,
    ): array {
        $package = $machine['package_id'] !== null ? ($packagesById[$machine['package_id']] ?? null) : null;

        $diskTotal = 0;
        foreach ($disks as $disk) {
            if ($disk['machine_id'] === $machine['id'] && $disk['state'] === 'built') {
                $diskTotal += $disk['size_gb'];
            }
        }

        $running = $machine['state'] === 'started';
        $list = $this->monthlyCost($package, $diskTotal, $machine['ipv4'], $prices, $running);

        $weekAgo = $now->getTimestamp() - 7 * 86400;
        $weekSamples = array_values(array_filter($samples, fn (array $s) => $this->time($s) >= $weekAgo));
        $historyDays = $samples === []
            ? 0
            : (int) floor(($this->time($samples[count($samples) - 1]) - $this->time($samples[0])) / 86400);

        $week = $this->summarise($weekSamples);
        $latest = $samples === [] ? null : $samples[count($samples) - 1];

        $server = [
            'name' => $machine['name'],
            'hostname' => $machine['hostname'],
            'state' => $machine['state'],
            'package' => $package === null ? null : [
                'name' => $package['name'],
                'cpus' => $package['cpus'],
                'memory_gb' => $package['memory_gb'],
                'disk_gb' => $package['disk_gb'],
            ],
            'package_name' => $package['name'] ?? $machine['package_name'],
            'disk_total_gb' => $diskTotal,
            'extra_disk_gb' => $package === null ? 0 : max(0, $diskTotal - $package['disk_gb']),
            'ipv4' => $machine['ipv4'],
            'cost' => ['list' => round($list, 2), 'paid' => round($list * (1 - $discount), 2)],
            'measured' => $week !== null,
            'history_days' => $historyDays,
            'week' => $week,
            'trend' => $this->trend($samples, $now),
            'disks' => $this->diskFacts($samples, $latest),
            'needs' => null,
            'steps' => ['down' => null, 'up' => null],
            'verdict' => 'keep',
            'indication' => null,
            'candidate' => null,
            'reasons' => [],
        ];

        if ($package !== null) {
            $server['steps'] = $this->neighbours($package, $packagesById, $diskTotal, $machine['ipv4'], $prices, $discount);
        }

        if (! $running) {
            $server['verdict'] = 'stopped';
            $server['reasons'][] = 'Stopped. Only its disk is charged for.';

            return $server;
        }

        if ($week === null || $package === null) {
            $server['verdict'] = 'unmeasured';
            $server['reasons'][] = 'No readings: the sampler cannot reach this server.';

            return $server;
        }

        $server['needs'] = [
            'cpus' => max(1, (int) ceil(max(
                $week['load_p95'] / self::LOAD_HEADROOM,
                ($week['cpu_busy_p95'] ?? 0.0) * $week['cpus'] / self::BUSY_HEADROOM,
            ) - 1e-9)),
            'memory_gb' => round($week['mem_used_gb_p95'] / self::MEMORY_HEADROOM, 1),
        ];

        [$verdict, $candidate, $reasons] = $this->sizeVerdict(
            $package,
            $week,
            $server['needs'],
            $packagesById,
            $diskTotal,
            $machine['ipv4'],
            $prices,
            $discount,
            $list,
        );
        $server['reasons'] = $reasons;
        $server['candidate'] = $candidate;

        if ($historyDays < $minHistoryDays) {
            // Too early to act. Say what the readings point to so far, without recommending it.
            $server['verdict'] = 'wait';
            $server['indication'] = $verdict;
            array_unshift(
                $server['reasons'],
                "Only {$historyDays} day(s) of readings; {$minHistoryDays} are needed before a change is suggested."
            );
        } else {
            $server['verdict'] = $verdict;
        }

        return $server;
    }

    /**
     * @param  array{id: string, name: string, cpus: int, memory_gb: int, disk_gb: int, dedicated: bool}  $package
     * @param  array<string, float|int|null>  $week
     * @param  array{cpus: int, memory_gb: float}  $needs
     * @param  array<string, array{id: string, name: string, cpus: int, memory_gb: int, disk_gb: int, dedicated: bool}>  $packagesById
     * @param  array{packages: array<string, float>, disk_gb: float, ipv4: float}  $prices
     * @return array{0: string, 1: ?array<string, mixed>, 2: list<string>}
     */
    private function sizeVerdict(
        array $package,
        array $week,
        array $needs,
        array $packagesById,
        int $diskTotal,
        int $ipv4,
        array $prices,
        float $discount,
        float $currentList,
    ): array {
        $reasons = [];
        $loadPerCpu = $week['load_p95'] / max(1, $week['cpus']);
        $busy = $week['cpu_busy_p95'];
        $memory = $week['mem_used_pct_p95'];

        $tooSmall = false;
        if ($loadPerCpu > self::LOAD_TOO_HIGH) {
            $tooSmall = true;
            $reasons[] = sprintf('Load is above the number of processors for part of the week (%.2f per processor).', $loadPerCpu);
        }
        if ($busy !== null && $busy > self::BUSY_TOO_HIGH) {
            $tooSmall = true;
            $reasons[] = sprintf('Processors are %d%% busy at the busy end of the week.', round($busy * 100));
        }
        if ($memory > self::MEMORY_TOO_HIGH) {
            $tooSmall = true;
            $reasons[] = sprintf('Memory is %d%% used at the busy end of the week.', round($memory * 100));
        }

        $best = null;
        foreach ($packagesById as $option) {
            if ($option['dedicated'] || ! isset($prices['packages'][$option['id']]) || $option['id'] === $package['id']) {
                continue;
            }
            if ($option['cpus'] < $needs['cpus'] || $option['memory_gb'] < $needs['memory_gb']) {
                continue;
            }
            // Bigger means no smaller in either measure; smaller means no bigger in either.
            $fits = $tooSmall
                ? ($option['cpus'] >= $package['cpus'] && $option['memory_gb'] >= $package['memory_gb'])
                : ($option['cpus'] <= $package['cpus'] && $option['memory_gb'] <= $package['memory_gb']);
            if (! $fits) {
                continue;
            }

            $cost = $this->monthlyCost($option, $diskTotal, $ipv4, $prices, true);
            if ($best === null || $cost < $best['list']) {
                $best = ['package' => $option, 'list' => $cost];
            }
        }

        if ($tooSmall) {
            if ($best === null) {
                $reasons[] = 'No package on offer is big enough by these rules.';

                return ['step_up', null, $reasons];
            }

            return ['step_up', $this->candidate($best, $currentList, $discount), $reasons];
        }

        if ($best !== null && $best['list'] < $currentList - 1.0) {
            $reasons[] = sprintf(
                'It needs about %d processor(s) and %s GB of memory with headroom; %s has %d and %d GB.',
                $needs['cpus'],
                rtrim(rtrim(number_format($needs['memory_gb'], 1), '0'), '.'),
                $best['package']['name'],
                $best['package']['cpus'],
                $best['package']['memory_gb'],
            );

            return ['step_down', $this->candidate($best, $currentList, $discount), $reasons];
        }

        $nextDown = $this->nextDown($package, $packagesById, $prices);
        if ($nextDown === null) {
            $reasons[] = 'It is already on the smallest package.';
        } else {
            $short = array_keys(array_filter([
                'processors' => $nextDown['cpus'] < $needs['cpus'],
                'memory' => $nextDown['memory_gb'] < $needs['memory_gb'],
            ]));
            $reasons[] = $short === []
                ? 'The next size down would fit but saves nothing once its disk is paid for.'
                : 'No smaller package leaves headroom; '.implode(' and ', $short)
                    .(count($short) > 1 ? ' are' : ' is').' what it needs most.';
        }

        return ['keep', null, $reasons];
    }

    /**
     * @param  array{package: array<string, mixed>, list: float}  $best
     * @return array<string, mixed>
     */
    private function candidate(array $best, float $currentList, float $discount): array
    {
        return [
            'name' => $best['package']['name'],
            'cpus' => $best['package']['cpus'],
            'memory_gb' => $best['package']['memory_gb'],
            'disk_gb' => $best['package']['disk_gb'],
            'cost_list' => round($best['list'], 2),
            'cost_paid' => round($best['list'] * (1 - $discount), 2),
            'saving_paid' => round(($currentList - $best['list']) * (1 - $discount), 2),
        ];
    }

    /**
     * The next cheaper package in the same family, or null when there is none.
     *
     * @param  array<string, mixed>  $package
     * @param  array<string, array<string, mixed>>  $packagesById
     * @param  array{packages: array<string, float>, disk_gb: float, ipv4: float}  $prices
     * @return array<string, mixed>|null
     */
    private function nextDown(array $package, array $packagesById, array $prices): ?array
    {
        $ladder = $this->ladder($package, $packagesById, $prices);
        $position = array_search($package['id'], array_column($ladder, 'id'), true);

        return $position === false || $position === 0 ? null : $ladder[$position - 1];
    }

    /**
     * The packages either side of this one on the same family's price ladder.
     *
     * @param  array<string, mixed>  $package
     * @param  array<string, array<string, mixed>>  $packagesById
     * @param  array{packages: array<string, float>, disk_gb: float, ipv4: float}  $prices
     * @return array{down: ?array<string, mixed>, up: ?array<string, mixed>}
     */
    private function neighbours(array $package, array $packagesById, int $diskTotal, int $ipv4, array $prices, float $discount): array
    {
        $ladder = $this->ladder($package, $packagesById, $prices);
        $position = array_search($package['id'], array_column($ladder, 'id'), true);
        $steps = ['down' => null, 'up' => null];

        if ($position === false) {
            return $steps;
        }

        foreach (['down' => $position - 1, 'up' => $position + 1] as $direction => $index) {
            if (isset($ladder[$index])) {
                $list = $this->monthlyCost($ladder[$index], $diskTotal, $ipv4, $prices, true);
                $steps[$direction] = [
                    'name' => $ladder[$index]['name'],
                    'cpus' => $ladder[$index]['cpus'],
                    'memory_gb' => $ladder[$index]['memory_gb'],
                    'disk_gb' => $ladder[$index]['disk_gb'],
                    'cost_paid' => round($list * (1 - $discount), 2),
                ];
            }
        }

        return $steps;
    }

    /**
     * Shared-processor packages of the same family (the letters before the dash), cheapest first.
     *
     * @param  array<string, mixed>  $package
     * @param  array<string, array<string, mixed>>  $packagesById
     * @param  array{packages: array<string, float>, disk_gb: float, ipv4: float}  $prices
     * @return list<array<string, mixed>>
     */
    private function ladder(array $package, array $packagesById, array $prices): array
    {
        $family = strtok((string) $package['name'], '-');
        $ladder = [];

        foreach ($packagesById as $option) {
            if (! $option['dedicated'] && isset($prices['packages'][$option['id']])
                && strtok((string) $option['name'], '-') === $family) {
                $ladder[] = $option;
            }
        }

        usort($ladder, fn (array $a, array $b) => $prices['packages'][$a['id']] <=> $prices['packages'][$b['id']]);

        return $ladder;
    }

    /**
     * What a machine costs a month before discount: the package, disk beyond what the package
     * includes, and addresses beyond the first. A stopped machine is charged for its disk only.
     *
     * @param  array<string, mixed>|null  $package
     * @param  array{packages: array<string, float>, disk_gb: float, ipv4: float}  $prices
     */
    private function monthlyCost(?array $package, int $diskTotal, int $ipv4, array $prices, bool $running): float
    {
        if (! $running || $package === null) {
            return $diskTotal * $prices['disk_gb'];
        }

        return ($prices['packages'][$package['id']] ?? 0.0)
            + max(0, $diskTotal - $package['disk_gb']) * $prices['disk_gb']
            + max(0, $ipv4 - 1) * $prices['ipv4'];
    }

    /**
     * The busy end and the average of a set of readings, or null when there are none.
     *
     * @param  list<array<string, mixed>>  $samples
     * @return array<string, float|int|null>|null
     */
    private function summarise(array $samples): ?array
    {
        if ($samples === []) {
            return null;
        }

        $load = [];
        $memoryShare = [];
        $memoryGb = [];
        $swap = 0;

        foreach ($samples as $sample) {
            $load[] = (float) $sample['load5'];
            $total = max(1, (int) $sample['mem_total_mb']);
            $used = max(0, $total - (int) $sample['mem_available_mb']);
            $memoryShare[] = $used / $total;
            $memoryGb[] = $used / 1024;
            $swap = max($swap, (int) $sample['swap_used_mb']);
        }

        $busy = $this->busyShares($samples);
        $last = $samples[count($samples) - 1];

        return [
            'samples' => count($samples),
            'cpus' => (int) $last['cpus'],
            'mem_total_gb' => round((int) $last['mem_total_mb'] / 1024, 1),
            'load_avg' => round(array_sum($load) / count($load), 2),
            'load_p95' => round($this->percentile($load, 0.95), 2),
            'load_per_cpu_p95' => round($this->percentile($load, 0.95) / max(1, (int) $last['cpus']), 2),
            'cpu_busy_avg' => $busy === [] ? null : round(array_sum($busy) / count($busy), 3),
            'cpu_busy_p95' => $busy === [] ? null : round($this->percentile($busy, 0.95), 3),
            'mem_used_pct_p95' => round($this->percentile($memoryShare, 0.95), 3),
            'mem_used_gb_p95' => round($this->percentile($memoryGb, 0.95), 1),
            'swap_used_mb_max' => $swap,
        ];
    }

    /**
     * The share of processor time in use between each pair of neighbouring readings. A reboot
     * resets the counters and a long gap hides what happened inside it, so both are left out.
     *
     * @param  list<array<string, mixed>>  $samples
     * @return list<float>
     */
    private function busyShares(array $samples): array
    {
        $shares = [];

        for ($i = 1; $i < count($samples); $i++) {
            $total = (int) $samples[$i]['cpu_total'] - (int) $samples[$i - 1]['cpu_total'];
            $idle = (int) $samples[$i]['cpu_idle'] - (int) $samples[$i - 1]['cpu_idle'];
            $seconds = $this->time($samples[$i]) - $this->time($samples[$i - 1]);

            if ($total > 0 && $idle >= 0 && $idle <= $total && $seconds <= self::MAX_INTERVAL_SECONDS) {
                $shares[] = 1 - $idle / $total;
            }
        }

        return $shares;
    }

    /**
     * One line per week for the last eight, oldest first, so a drift shows.
     *
     * @param  list<array<string, mixed>>  $samples
     * @return list<array<string, mixed>>
     */
    private function trend(array $samples, CarbonInterface $now): array
    {
        $rows = [];
        $end = $now->getTimestamp();

        for ($week = self::TREND_WEEKS - 1; $week >= 0; $week--) {
            $to = $end - $week * 7 * 86400;
            $from = $to - 7 * 86400;
            $inWeek = array_values(array_filter(
                $samples,
                fn (array $s) => $this->time($s) > $from && $this->time($s) <= $to
            ));
            $summary = $this->summarise($inWeek);
            if ($summary === null) {
                continue;
            }

            $largest = $this->largestDisk($inWeek[count($inWeek) - 1]);
            $rows[] = [
                'week_ending' => date('j M', $to),
                'load_per_cpu_p95' => $summary['load_per_cpu_p95'],
                'cpu_busy_p95' => $summary['cpu_busy_p95'],
                'mem_used_pct_p95' => $summary['mem_used_pct_p95'],
                'disk_used_gb' => $largest['used_gb'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Each disk as it is now, how fast it is filling, and when it would reach 90%.
     *
     * @param  list<array<string, mixed>>  $samples
     * @param  array<string, mixed>|null  $latest
     * @return list<array<string, mixed>>
     */
    private function diskFacts(array $samples, ?array $latest): array
    {
        $facts = [];

        foreach ($this->disksOf($latest) as $disk) {
            $points = [];
            foreach ($samples as $sample) {
                foreach ($this->disksOf($sample) as $earlier) {
                    if ($earlier['mount'] === $disk['mount']) {
                        $points[] = [$this->time($sample) / 86400, (float) $earlier['used_gb']];
                    }
                }
            }

            $growth = $this->weeklyGrowth($points);
            $size = max(0.1, (float) $disk['size_gb']);
            $used = (float) $disk['used_gb'];
            $share = $used / $size;
            $weeks = ($growth !== null && $growth > 0.05)
                ? max(0.0, round((self::DISK_TARGET * $size - $used) / $growth, 1))
                : null;

            $facts[] = [
                'mount' => $disk['mount'],
                'size_gb' => $size,
                'used_gb' => $used,
                'used_pct' => round($share, 3),
                'growth_gb_per_week' => $growth === null ? null : round($growth, 2),
                'weeks_to_90' => $weeks,
                'flag' => $share >= self::DISK_FULL || ($weeks !== null && $weeks < self::DISK_WEEKS_AHEAD),
            ];
        }

        return $facts;
    }

    /**
     * Straight-line growth in GB a week. Null until there is a week of readings to draw it through.
     *
     * @param  list<array{0: float, 1: float}>  $points  [day, used GB]
     */
    private function weeklyGrowth(array $points): ?float
    {
        $n = count($points);
        if ($n < 20 || $points[$n - 1][0] - $points[0][0] < 7) {
            return null;
        }

        $meanX = array_sum(array_column($points, 0)) / $n;
        $meanY = array_sum(array_column($points, 1)) / $n;
        $top = 0.0;
        $bottom = 0.0;
        foreach ($points as [$x, $y]) {
            $top += ($x - $meanX) * ($y - $meanY);
            $bottom += ($x - $meanX) ** 2;
        }

        return $bottom > 0 ? 7 * $top / $bottom : null;
    }

    /**
     * @param  array<string, mixed>|null  $sample
     * @return list<array{mount: string, size_gb: float, used_gb: float}>
     */
    private function disksOf(?array $sample): array
    {
        $disks = $sample['disks'] ?? [];
        if (is_string($disks)) {
            $disks = json_decode($disks, true);
        }

        return is_array($disks) ? array_values($disks) : [];
    }

    /**
     * @param  array<string, mixed>  $sample
     * @return array{mount: string, size_gb: float, used_gb: float}|null
     */
    private function largestDisk(array $sample): ?array
    {
        $largest = null;
        foreach ($this->disksOf($sample) as $disk) {
            if ($largest === null || $disk['size_gb'] > $largest['size_gb']) {
                $largest = $disk;
            }
        }

        return $largest;
    }

    /**
     * @param  list<float>  $values
     */
    private function percentile(array $values, float $p): float
    {
        sort($values);
        $index = (int) ceil($p * count($values)) - 1;

        return (float) $values[max(0, min(count($values) - 1, $index))];
    }

    /**
     * @param  array<string, mixed>  $sample
     */
    private function time(array $sample): int
    {
        $at = $sample['sampled_at'];

        return $at instanceof \DateTimeInterface ? $at->getTimestamp() : (int) strtotime((string) $at);
    }
}
