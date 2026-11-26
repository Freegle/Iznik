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
 *  - A smaller package is suggested only if the busiest week in the whole window (up to eight
 *    weeks) would still leave headroom on it: load at most 70% of the processors, processor time
 *    at most 60% busy, memory at most 80% used. One quiet week is not enough.
 *  - A bigger package is suggested when the last week is past a higher mark (load above the number
 *    of processors while they are more than 60% busy, processor time above 85% busy, or memory
 *    above 92% used). It is the next size up, one step at a time. Between the two
 *    marks the answer is "keep", so a server near a line does not flip from week to week.
 *  - A move down needs enough days of readings to have seen a normal fortnight, and readings on
 *    most days of the last week. A day counts when it has at least six hours' worth of readings. A move up needs only a few days: an overloaded server should not
 *    wait a fortnight to be mentioned.
 *  - Only packages of the same family are compared, so "smaller" and "bigger" mean what they say.
 *  - Readings from before a resize are left out: they describe a different machine.
 *  - A server that is using swap is not offered less memory.
 *  - A server marked "bulk" does background work and is meant to run flat out. It is not judged
 *    on load or processor time at all, only on whether late-job alerts showed in the week, and on
 *    memory. A server marked "keep" is kept on purpose and is not judged.
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

    /** Swap in use above this share of memory means the server is short of memory already. */
    public const SWAP_IN_USE = 0.1;

    public const DISK_FULL = 0.85;

    public const DISK_TARGET = 0.9;

    public const DISK_WEEKS_AHEAD = 8;

    public const TREND_WEEKS = 8;

    /** Days of the last week that must have readings before a move down is suggested. */
    public const WEEK_DAYS_FOR_DOWN = 5;

    /** Days of the last week that must have readings before a move up is suggested. */
    public const WEEK_DAYS_FOR_UP = 3;

    /** A day counts as having readings when it has this many: six hours' worth at four an hour. */
    public const READINGS_FOR_A_DAY = 24;

    /** A gap longer than this between two readings is not used to work out processor time. */
    private const MAX_INTERVAL_SECONDS = 3600;

    /**
     * @param  list<array{id: string, name: string, hostname: string, state: string, package_id: ?string, package_name: ?string, ipv4: int}>  $machines
     * @param  list<array{id: string, name: string, cpus: int, memory_gb: int, disk_gb: int, dedicated: bool}>  $packages
     * @param  list<array{name: string, size_gb: int, state: string, machine_id: ?string}>  $disks
     * @param  array{packages: array<string, float>, disk_gb: float, ipv4: float}  $prices
     * @param  array<string, list<array<string, mixed>>>  $samplesByHost  readings per host name, oldest first
     * @param  array<string, array{role: string, note: string}>  $hostNotes  by lower-case short name
     * @param  list<array{slug: string, hours: float}>  $lateJobs  late-job alerts in the last week
     * @param  float  $otherList  monthly charges the API does not show, before discount
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
        array $hostNotes = [],
        array $lateJobs = [],
        float $otherList = 0.0,
        float $vatRate = 0.0,
    ): array {
        $discount = min(1.0, max(0.0, $discount));
        $packagesById = [];
        foreach ($packages as $package) {
            $packagesById[$package['id']] = $package;
        }

        // A host may call itself "db-2" or "db-2.example.org", in any case; Katapult has its own
        // spelling. Match on the short lower-case name so a difference of that kind is not read
        // as "this server is not being sampled".
        // A reading dated after now (a clock that is ahead) is left out everywhere, so the last
        // week and the busiest week are always worked out from the same readings.
        $end = $now->getTimestamp();
        $samplesByKey = [];
        foreach ($samplesByHost as $host => $samples) {
            $key = $this->hostKey((string) $host);
            if ($key === '') {
                continue;
            }
            foreach ($samples as $sample) {
                $reading = $this->normalise($sample);
                if ($reading['t'] <= $end) {
                    $samplesByKey[$key][] = $reading;
                }
            }
        }
        foreach ($samplesByKey as &$merged) {
            usort($merged, fn (array $a, array $b) => $a['t'] <=> $b['t']);
        }
        unset($merged);

        $servers = [];
        $fleetList = 0.0;
        $fleetSaving = 0.0;
        $fleetCost = 0.0;
        $matched = [];

        foreach ($machines as $machine) {
            $samples = [];
            foreach (array_unique([$this->hostKey($machine['hostname']), $this->hostKey($machine['name'])]) as $key) {
                if ($key !== '' && isset($samplesByKey[$key])) {
                    $samples = $samplesByKey[$key];
                    $matched[$key] = true;
                    break;
                }
            }

            $note = $hostNotes[$this->hostKey($machine['hostname'])] ?? $hostNotes[$this->hostKey($machine['name'])] ?? null;
            $server = $this->analyseMachine($machine, $packagesById, $disks, $prices, $samples, $now, $discount, $minHistoryDays, $note, $lateJobs);
            $fleetList += $server['cost']['list'];
            if ($server['candidate'] !== null && $server['verdict'] === 'step_down') {
                $fleetSaving += $server['candidate']['saving_paid'];
            }
            if ($server['candidate'] !== null && in_array($server['verdict'], ['step_up', 'bulk_late'], true)) {
                $fleetCost += max(0.0, -$server['candidate']['saving_paid']);
            }
            $servers[] = $server;
        }

        usort($servers, fn (array $a, array $b) => $b['cost']['list'] <=> $a['cost']['list']);

        $unattached = [];
        foreach ($disks as $disk) {
            $trashed = ($disk['trashed_until'] ?? null) !== null;
            if ($trashed || $disk['machine_id'] === null || $disk['state'] !== 'built') {
                $unattached[] = $disk;
                if ($trashed || $disk['machine_id'] === null) {
                    $fleetList += $disk['size_gb'] * $prices['disk_gb'];
                }
            }
        }

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
                'possible_cost_paid' => round($fleetCost, 2),
                // What the next invoice should come to: everything above, what the API does not show,
                // the discount, then VAT.
                'other_list' => round(max(0.0, $otherList), 2),
                'invoice_before_vat' => round(($fleetList + max(0.0, $otherList)) * (1 - $discount), 2),
                'invoice_expected' => round(($fleetList + max(0.0, $otherList)) * (1 - $discount) * (1 + max(0.0, $vatRate)), 2),
                'vat_rate' => max(0.0, $vatRate),
            ],
            'servers' => $servers,
            'unattached_disks' => $unattached,
            // Sampled, but no rented machine has that name: a host renamed or not on Katapult.
            'unmatched_hosts' => array_values(array_diff(array_keys($samplesByKey), array_keys($matched))),
        ];
    }

    /**
     * @param  array<string, mixed>  $machine
     * @param  array<string, array{id: string, name: string, cpus: int, memory_gb: int, disk_gb: int, dedicated: bool}>  $packagesById
     * @param  list<array{name: string, size_gb: int, state: string, machine_id: ?string}>  $disks
     * @param  array{packages: array<string, float>, disk_gb: float, ipv4: float}  $prices
     * @param  list<array<string, mixed>>  $samples  normalised, oldest first
     * @param  array{role: string, note: string}|null  $note
     * @param  list<array{slug: string, hours: float}>  $lateJobs
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
        ?array $note = null,
        array $lateJobs = [],
    ): array {
        $package = $machine['package_id'] !== null ? ($packagesById[$machine['package_id']] ?? null) : null;

        $diskTotal = 0;
        foreach ($disks as $disk) {
            // Every disk is charged for, built or not. One in the trash is counted with the fleet, not
            // with the machine it used to belong to.
            if ($disk['machine_id'] === $machine['id'] && ($disk['trashed_until'] ?? null) === null) {
                $diskTotal += $disk['size_gb'];
            }
        }

        $running = $machine['state'] === 'started';
        $list = $this->monthlyCost($package, $diskTotal, $machine['ipv4'], $prices);
        $diskList = $diskTotal * $prices['disk_gb'];
        $end = $now->getTimestamp();
        $latest = $samples === [] ? null : $samples[count($samples) - 1];

        // Only readings taken at the size the server is now say anything about that size.
        $sized = $latest === null ? [] : array_values(array_filter(
            $samples,
            fn (array $s) => $s['cpus'] === $latest['cpus']
                && abs($s['mem_total_mb'] - $latest['mem_total_mb']) <= 0.05 * $latest['mem_total_mb']
        ));

        $weeks = $this->weeks($sized, $end);
        $week = $weeks[$end] ?? null;
        $historyDays = $this->daysWithReadings($sized);
        $weekDays = $this->daysWithReadings(array_values(array_filter($sized, fn (array $s) => $s['t'] > $end - 7 * 86400)));

        $server = [
            'name' => $machine['name'],
            'hostname' => $machine['hostname'],
            'state' => $machine['state'],
            'role' => $note['role'] ?? 'normal',
            'package' => $package === null ? null : [
                'name' => $package['name'],
                'cpus' => $package['cpus'],
                'memory_gb' => $package['memory_gb'],
                'disk_gb' => $package['disk_gb'],
            ],
            'package_name' => $package['name'] ?? $machine['package_name'],
            'disk_total_gb' => $diskTotal,
            'disk_cost' => ['list' => round($diskList, 2), 'paid' => round($diskList * (1 - $discount), 2)],
            'ipv4' => $machine['ipv4'],
            'cost' => ['list' => round($list, 2), 'paid' => round($list * (1 - $discount), 2)],
            'measured' => $week !== null,
            'history_days' => $historyDays,
            'week_days' => $weekDays,
            'week' => $week === null ? null : array_diff_key($week, ['last' => true]),
            'peak' => null,
            'trend' => $this->trend($sized, $end),
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

        $explanation = trim($note['note'] ?? '');

        if (($note['role'] ?? null) === 'keep') {
            // Somebody has decided to keep this one as it is. Say why, and judge nothing.
            $server['verdict'] = 'kept';
            if ($explanation !== '') {
                $server['reasons'][] = $explanation;
            }
            if (! $running) {
                $server['reasons'][] = 'Stopped, but still charged for its package and its disk.';
            }

            return $server;
        }

        if (! $running) {
            $server['verdict'] = 'stopped';
            $server['reasons'][] = 'Stopped, but still charged for its package and its disk.';

            return $server;
        }

        if ($week === null || $package === null) {
            $server['verdict'] = 'unmeasured';
            $server['reasons'][] = $latest === null
                ? 'No readings: the sampler does not reach this server.'
                : 'No readings in the last week; the last was on '.date('j M', $latest['t']).'.';

            return $server;
        }

        // The busiest week seen at this size. A move down has to fit this, not just the last week.
        $peak = ['weeks' => 0, 'load_p95' => 0.0, 'cpu_busy_p95' => null, 'mem_used_gb_p95' => 0.0, 'swap_used_mb_max' => 0];
        foreach ($weeks as $summary) {
            $peak['weeks']++;
            $peak['load_p95'] = max($peak['load_p95'], $summary['load_p95']);
            $peak['mem_used_gb_p95'] = max($peak['mem_used_gb_p95'], $summary['mem_used_gb_p95']);
            $peak['swap_used_mb_max'] = max($peak['swap_used_mb_max'], $summary['swap_used_mb_max']);
            if ($summary['cpu_busy_p95'] !== null) {
                $peak['cpu_busy_p95'] = max($peak['cpu_busy_p95'] ?? 0.0, $summary['cpu_busy_p95']);
            }
        }
        $server['peak'] = $peak;

        $server['needs'] = [
            'cpus' => max(1, (int) ceil(max(
                $peak['load_p95'] / self::LOAD_HEADROOM,
                ($peak['cpu_busy_p95'] ?? 0.0) * $week['cpus'] / self::BUSY_HEADROOM,
            ) - 1e-9)),
            'memory_gb' => round($peak['mem_used_gb_p95'] / self::MEMORY_HEADROOM, 1),
        ];

        if (($note['role'] ?? null) === 'bulk') {
            return $this->bulkVerdict($server, $explanation, $lateJobs, $package, $week, $packagesById, $diskTotal, $machine['ipv4'], $prices, $discount, $list);
        }

        [$verdict, $candidate, $reasons] = $this->sizeVerdict(
            $package,
            $week,
            $peak,
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

        // Is there enough to go on? Say what the readings point to either way, without recommending it.
        $short = null;
        if ($verdict === 'step_up' && $weekDays < self::WEEK_DAYS_FOR_UP) {
            $short = sprintf('Readings on only %d day(s) of the last week; %d are needed before a move up is suggested.', $weekDays, self::WEEK_DAYS_FOR_UP);
        } elseif ($verdict !== 'step_up' && $historyDays < $minHistoryDays) {
            $short = "Readings on only {$historyDays} day(s); {$minHistoryDays} are needed before the size is judged.";
        } elseif ($verdict === 'step_down' && $weekDays < self::WEEK_DAYS_FOR_DOWN) {
            $short = sprintf('Readings on only %d day(s) of the last week; %d are needed before a move down is suggested.', $weekDays, self::WEEK_DAYS_FOR_DOWN);
        }

        if ($short !== null) {
            $server['verdict'] = 'wait';
            $server['indication'] = $verdict;
            array_unshift($server['reasons'], $short);
        } else {
            $server['verdict'] = $verdict;
        }

        return $server;
    }

    /**
     * @param  array{id: string, name: string, cpus: int, memory_gb: int, disk_gb: int, dedicated: bool}  $package
     * @param  array<string, float|int|null>  $week
     * @param  array<string, float|int|null>  $peak
     * @param  array{cpus: int, memory_gb: float}  $needs
     * @param  array<string, array{id: string, name: string, cpus: int, memory_gb: int, disk_gb: int, dedicated: bool}>  $packagesById
     * @param  array{packages: array<string, float>, disk_gb: float, ipv4: float}  $prices
     * @return array{0: string, 1: ?array<string, mixed>, 2: list<string>}
     */
    private function sizeVerdict(
        array $package,
        array $week,
        array $peak,
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

        // Load counts work that is waiting for disk as well as work that wants a processor, so
        // high load only means "short of processors" when the processors are busy too.
        $shortOfProcessors = false;
        $shortOfMemory = false;
        if ($loadPerCpu > self::LOAD_TOO_HIGH && ($busy === null || $busy > self::BUSY_HEADROOM)) {
            $shortOfProcessors = true;
            $reasons[] = sprintf('Load is above the number of processors for part of the week (%.2f per processor).', $loadPerCpu);
        }
        if ($busy !== null && $busy > self::BUSY_TOO_HIGH) {
            $shortOfProcessors = true;
            $reasons[] = sprintf('Processors are %d%% busy at the busy end of the week.', round($busy * 100));
        }
        if ($memory > self::MEMORY_TOO_HIGH) {
            $shortOfMemory = true;
            $reasons[] = sprintf('Memory is %d%% used at the busy end of the week.', round($memory * 100));
        }

        $ladder = $this->ladder($package, $packagesById, $prices);
        $position = array_search($package['id'], array_column($ladder, 'id'), true);
        $fits = fn (array $option): bool => $option['cpus'] >= $needs['cpus'] && $option['memory_gb'] >= $needs['memory_gb'];

        if ($shortOfProcessors || $shortOfMemory) {
            $option = $this->nextUp($package, $ladder, $shortOfProcessors, $shortOfMemory);
            if ($option !== null) {
                $cost = $this->monthlyCost($option, $diskTotal, $ipv4, $prices);
                if (! $fits($option)) {
                    $reasons[] = 'The next size up may still be short of headroom; this report looks again next week.';
                }

                return ['step_up', $this->candidate(['package' => $option, 'list' => $cost], $currentList, $discount), $reasons];
            }
            $reasons[] = $position === false
                ? 'Its package has no price, so no other size can be compared.'
                : 'There is no bigger package of this kind.';

            return ['step_up', null, $reasons];
        }

        // The last week's swap, not the window's: one blip weeks ago should not hold a server for two months.
        $swapShare = $week['swap_used_mb_max'] / max(1.0, $week['mem_total_gb'] * 1024);
        if ($swapShare > self::SWAP_IN_USE) {
            $reasons[] = sprintf('It used up to %.1f GB of swap this week, so it is not offered less memory.', $week['swap_used_mb_max'] / 1024);

            return ['keep', null, $reasons];
        }

        // The cheapest step down the ladder that fits and is no bigger in either measure.
        $best = null;
        foreach ($position === false ? [] : array_slice($ladder, 0, $position) as $option) {
            if (! $fits($option) || $option['cpus'] > $package['cpus'] || $option['memory_gb'] > $package['memory_gb']) {
                continue;
            }
            $cost = $this->monthlyCost($option, $diskTotal, $ipv4, $prices);
            if ($best === null || $cost < $best['list']) {
                $best = ['package' => $option, 'list' => $cost];
            }
        }

        if ($best !== null && $best['list'] < $currentList - 0.005) {
            $reasons[] = sprintf(
                'In its busiest of %d week(s) it needed about %d processor(s) and %s GB of memory with headroom; %s has %d and %d GB.',
                $peak['weeks'],
                $needs['cpus'],
                rtrim(rtrim(number_format($needs['memory_gb'], 1), '0'), '.'),
                $best['package']['name'],
                $best['package']['cpus'],
                $best['package']['memory_gb'],
            );

            return ['step_down', $this->candidate($best, $currentList, $discount), $reasons];
        }

        $nextDown = $position === false || $position === 0 ? null : $ladder[$position - 1];
        if ($nextDown === null) {
            $reasons[] = $position === 0 ? 'It is already on the smallest package.' : 'Its package has no price, so no other size can be compared.';
        } else {
            $short = array_keys(array_filter([
                'processors' => $nextDown['cpus'] < $needs['cpus'],
                'memory' => $nextDown['memory_gb'] < $needs['memory_gb'],
            ]));
            $reasons[] = $short === []
                ? 'The next cheaper package would fit, but it is not smaller in both processors and memory.'
                : 'No smaller package leaves headroom: it would be short of '.implode(' and ', $short).'.';
        }

        return ['keep', null, $reasons];
    }

    /**
     * One step at a time: the next package up the ladder that is bigger in what the server is short
     * of and no smaller in the other. The following report shows whether that was enough.
     *
     * @param  array<string, mixed>  $package
     * @param  list<array<string, mixed>>  $ladder
     * @return array<string, mixed>|null
     */
    private function nextUp(array $package, array $ladder, bool $moreProcessors, bool $moreMemory): ?array
    {
        $position = array_search($package['id'], array_column($ladder, 'id'), true);

        foreach ($position === false ? [] : array_slice($ladder, $position + 1) as $option) {
            if ($option['cpus'] >= $package['cpus'] && $option['memory_gb'] >= $package['memory_gb']
                && (! $moreProcessors || $option['cpus'] > $package['cpus'])
                && (! $moreMemory || $option['memory_gb'] > $package['memory_gb'])) {
                return $option;
            }
        }

        return null;
    }

    /**
     * A server that does background work is meant to run flat out, so its load says nothing about
     * its size. What does: whether late-job alerts showed in the week, and whether it is out of
     * memory. If either, the next size up is offered; otherwise it is right as it is.
     *
     * @param  array<string, mixed>  $server
     * @param  list<array{slug: string, hours: float}>  $lateJobs
     * @param  array<string, mixed>  $package
     * @param  array<string, float|int|null>  $week
     * @param  array<string, array<string, mixed>>  $packagesById
     * @param  array{packages: array<string, float>, disk_gb: float, ipv4: float}  $prices
     * @return array<string, mixed>
     */
    private function bulkVerdict(
        array $server,
        string $explanation,
        array $lateJobs,
        array $package,
        array $week,
        array $packagesById,
        int $diskTotal,
        int $ipv4,
        array $prices,
        float $discount,
        float $currentList,
    ): array {
        $server['reasons'] = $explanation === '' ? [] : [$explanation];
        $server['needs'] = null;
        $shortOfMemory = $week['mem_used_pct_p95'] > self::MEMORY_TOO_HIGH;

        if ($lateJobs === []) {
            $server['reasons'][] = 'No late-job alerts showed in the last week.';
        } else {
            $server['reasons'][] = 'Late-job alerts in the last week: '.implode(', ', array_map(
                fn (array $job) => sprintf('%s (about %s hour%s)', $job['slug'], rtrim(rtrim(number_format($job['hours'], 1), '0'), '.'), $job['hours'] == 1.0 ? '' : 's'),
                array_slice($lateJobs, 0, 5)
            )).'.';
        }
        if ($shortOfMemory) {
            $server['reasons'][] = sprintf('Memory is %d%% used at the busy end of the week.', round($week['mem_used_pct_p95'] * 100));
        }

        if ($lateJobs === [] && ! $shortOfMemory) {
            $server['verdict'] = 'bulk';

            return $server;
        }

        $server['verdict'] = 'bulk_late';
        $option = $this->nextUp($package, $this->ladder($package, $packagesById, $prices), $lateJobs !== [], $shortOfMemory);
        if ($option !== null) {
            $server['candidate'] = $this->candidate(
                ['package' => $option, 'list' => $this->monthlyCost($option, $diskTotal, $ipv4, $prices)],
                $currentList,
                $discount
            );
        }

        return $server;
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
                $list = $this->monthlyCost($ladder[$index], $diskTotal, $ipv4, $prices);
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
     * What a machine costs a month before discount: the package, every GB of its disk, and
     * addresses beyond the first. Checked against the invoice of 10 November 2026: disk is charged
     * for in full whatever the package says it includes, and a stopped machine is still charged for
     * its package. So a change of package changes the package price and nothing else.
     *
     * @param  array<string, mixed>|null  $package
     * @param  array{packages: array<string, float>, disk_gb: float, ipv4: float}  $prices
     */
    private function monthlyCost(?array $package, int $diskTotal, int $ipv4, array $prices): float
    {
        return ($package === null ? 0.0 : ($prices['packages'][$package['id']] ?? 0.0))
            + $diskTotal * $prices['disk_gb']
            + max(0, $ipv4 - 1) * $prices['ipv4'];
    }

    /**
     * The busy end and the average of a set of readings. Only called with at least one.
     *
     * @param  non-empty-list<array<string, mixed>>  $samples
     * @return array<string, float|int|null>
     */
    private function summarise(array $samples): array
    {
        $load = [];
        $memoryShare = [];
        $memoryGb = [];
        $swap = 0;

        foreach ($samples as $sample) {
            $load[] = $sample['load5'];
            $total = max(1, $sample['mem_total_mb']);
            $used = max(0, $total - $sample['mem_available_mb']);
            $memoryShare[] = $used / $total;
            $memoryGb[] = $used / 1024;
            $swap = max($swap, $sample['swap_used_mb']);
        }

        $busy = $this->busyShares($samples);
        $last = $samples[count($samples) - 1];

        return [
            'samples' => count($samples),
            'cpus' => $last['cpus'],
            'mem_total_gb' => round($last['mem_total_mb'] / 1024, 1),
            'load_avg' => round(array_sum($load) / count($load), 2),
            'load_p95' => round($this->percentile($load, 0.95), 2),
            'load_per_cpu_p95' => round($this->percentile($load, 0.95) / max(1, $last['cpus']), 2),
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
            $total = $samples[$i]['cpu_total'] - $samples[$i - 1]['cpu_total'];
            $idle = $samples[$i]['cpu_idle'] - $samples[$i - 1]['cpu_idle'];
            $seconds = $samples[$i]['t'] - $samples[$i - 1]['t'];

            if ($total > 0 && $idle >= 0 && $idle <= $total && $seconds <= self::MAX_INTERVAL_SECONDS) {
                $shares[] = 1 - $idle / $total;
            }
        }

        return $shares;
    }

    /**
     * A summary of each of the last eight weeks that has readings, oldest first, keyed by the
     * time the week ends.
     *
     * @param  list<array<string, mixed>>  $samples
     * @return array<int, array<string, float|int|null>>
     */
    private function weeks(array $samples, int $end): array
    {
        $buckets = [];
        foreach ($samples as $sample) {
            $age = $end - $sample['t'];
            if ($age >= 0 && $age < self::TREND_WEEKS * 7 * 86400) {
                $buckets[intdiv($age, 7 * 86400)][] = $sample;
            }
        }

        $weeks = [];
        for ($week = self::TREND_WEEKS - 1; $week >= 0; $week--) {
            if (isset($buckets[$week])) {
                $weeks[$end - $week * 7 * 86400] = $this->summarise($buckets[$week]) + ['last' => $buckets[$week][count($buckets[$week]) - 1]];
            }
        }

        return $weeks;
    }

    /**
     * One line per week for the last eight, oldest first, so a drift shows.
     *
     * @param  list<array<string, mixed>>  $samples
     * @return list<array<string, mixed>>
     */
    private function trend(array $samples, int $end): array
    {
        $rows = [];

        foreach ($this->weeks($samples, $end) as $to => $summary) {
            $largest = null;
            foreach ($summary['last']['disks'] as $disk) {
                if ($largest === null || $disk['size_gb'] > $largest['size_gb']) {
                    $largest = $disk;
                }
            }

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

        foreach ($latest['disks'] ?? [] as $disk) {
            $points = [];
            foreach ($samples as $sample) {
                foreach ($sample['disks'] as $earlier) {
                    if ($earlier['mount'] === $disk['mount']) {
                        $points[] = [$sample['t'] / 86400, $earlier['used_gb']];
                    }
                }
            }

            $growth = $this->weeklyGrowth($points);
            $size = max(0.1, $disk['size_gb']);
            $used = $disk['used_gb'];
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
     * How many different days have enough readings to count. A day with a handful says too little
     * about that day to be evidence of a normal one.
     *
     * @param  list<array<string, mixed>>  $samples
     */
    private function daysWithReadings(array $samples): int
    {
        $days = [];
        foreach ($samples as $sample) {
            $day = intdiv($sample['t'], 86400);
            $days[$day] = ($days[$day] ?? 0) + 1;
        }

        return count(array_filter($days, fn (int $readings) => $readings >= self::READINGS_FOR_A_DAY));
    }

    /**
     * One reading with its time as a number and its disks as a list, so neither is worked out again
     * on every pass.
     *
     * @param  array<string, mixed>  $sample
     * @return array<string, mixed>
     */
    private function normalise(array $sample): array
    {
        $at = $sample['sampled_at'];
        $disks = $sample['disks'] ?? [];
        if (is_string($disks)) {
            $disks = json_decode($disks, true);
        }

        $list = [];
        foreach (is_array($disks) ? $disks : [] as $disk) {
            if (isset($disk['mount'], $disk['size_gb'], $disk['used_gb'])) {
                $list[] = ['mount' => (string) $disk['mount'], 'size_gb' => (float) $disk['size_gb'], 'used_gb' => (float) $disk['used_gb']];
            }
        }

        return [
            't' => $at instanceof \DateTimeInterface ? $at->getTimestamp() : (int) strtotime((string) $at),
            'cpus' => (int) $sample['cpus'],
            'load5' => (float) $sample['load5'],
            'cpu_total' => (int) $sample['cpu_total'],
            'cpu_idle' => (int) $sample['cpu_idle'],
            'mem_total_mb' => (int) $sample['mem_total_mb'],
            'mem_available_mb' => (int) $sample['mem_available_mb'],
            'swap_used_mb' => (int) $sample['swap_used_mb'],
            'disks' => $list,
        ];
    }

    /**
     * The short lower-case form of a host name: "DB-2.example.org" and "db-2" are the same host.
     */
    private function hostKey(string $name): string
    {
        return strtolower((string) strtok(trim($name), '.'));
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
}
