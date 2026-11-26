<?php

namespace Tests\Unit\Services\Servers;

use App\Services\Servers\ServerSizingAnalyser;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * The sizing rules. Each test builds a small estate and a run of readings and checks the verdict,
 * so a change to a threshold shows up as a changed verdict here and not as a surprise in the mail.
 */
class ServerSizingAnalyserTest extends TestCase
{
    private const PACKAGES = [
        ['id' => 'p1', 'name' => 'C-1', 'cpus' => 1, 'memory_gb' => 1, 'disk_gb' => 10, 'dedicated' => false],
        ['id' => 'p3', 'name' => 'C-3', 'cpus' => 1, 'memory_gb' => 3, 'disk_gb' => 25, 'dedicated' => false],
        ['id' => 'p12', 'name' => 'C-12', 'cpus' => 4, 'memory_gb' => 12, 'disk_gb' => 150, 'dedicated' => false],
        ['id' => 'p24', 'name' => 'C-24', 'cpus' => 8, 'memory_gb' => 24, 'disk_gb' => 225, 'dedicated' => false],
        ['id' => 'p36', 'name' => 'C-36', 'cpus' => 12, 'memory_gb' => 36, 'disk_gb' => 300, 'dedicated' => false],
        ['id' => 'p36d', 'name' => 'C-36 (Dedicated)', 'cpus' => 12, 'memory_gb' => 36, 'disk_gb' => 300, 'dedicated' => true],
        ['id' => 'm40', 'name' => 'M-40', 'cpus' => 4, 'memory_gb' => 40, 'disk_gb' => 50, 'dedicated' => false],
        ['id' => 'unpriced', 'name' => 'C-2', 'cpus' => 1, 'memory_gb' => 2, 'disk_gb' => 15, 'dedicated' => false],
    ];

    private const PRICES = [
        'packages' => ['p1' => 8.0, 'p3' => 12.0, 'p12' => 44.0, 'p24' => 90.0, 'p36' => 136.0, 'p36d' => 20.0, 'm40' => 130.0],
        'disk_gb' => 0.2,
        'ipv4' => 2.0,
    ];

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-11-30 08:00:00');
    }

    private function machine(string $name, string $package, string $state = 'started', int $ipv4 = 1): array
    {
        return ['id' => "vm_{$name}", 'name' => $name, 'hostname' => $name, 'state' => $state,
            'package_id' => $package, 'package_name' => null, 'ipv4' => $ipv4];
    }

    private function disk(string $machine, int $size, string $state = 'built'): array
    {
        return ['name' => 'System Disk', 'size_gb' => $size, 'state' => $state, 'machine_id' => "vm_{$machine}"];
    }

    /**
     * Readings every half hour for $days days ending now.
     *
     * @param  callable(int): array<string, mixed>|array<string, mixed>  $shape  values, or a function of the reading's index
     */
    private function samples(int $days, int $cpus, float $load, float $busy, int $memTotalMb, int $memUsedMb, callable|array $shape = []): array
    {
        $samples = [];
        $count = $days * 48;
        $total = 0;
        $idle = 0;

        for ($i = 0; $i <= $count; $i++) {
            $at = $this->now->copy()->subMinutes(30 * ($count - $i));
            $total += 1000;
            $idle += (int) round(1000 * (1 - $busy));
            $extra = is_callable($shape) ? $shape($i) : $shape;

            $samples[] = array_merge([
                'sampled_at' => $at->toDateTimeString(),
                'cpus' => $cpus,
                'load1' => $load,
                'load5' => $load,
                'load15' => $load,
                'cpu_total' => $total,
                'cpu_idle' => $idle,
                'mem_total_mb' => $memTotalMb,
                'mem_available_mb' => $memTotalMb - $memUsedMb,
                'swap_total_mb' => 0,
                'swap_used_mb' => 0,
                'disks' => json_encode([['mount' => '/', 'size_gb' => 100.0, 'used_gb' => 40.0]]),
            ], $extra);
        }

        return $samples;
    }

    private function analyse(array $machines, array $disks, array $samples, float $discount = 0.5, int $minDays = 14): array
    {
        return (new ServerSizingAnalyser)->analyse(
            $machines, self::PACKAGES, $disks, self::PRICES, $samples, $this->now, $discount, $minDays
        );
    }

    private function server(array $analysis, string $name): array
    {
        foreach ($analysis['servers'] as $server) {
            if ($server['name'] === $name) {
                return $server;
            }
        }
        $this->fail("no server {$name}");
    }

    public function test_an_idle_server_is_moved_down_to_the_cheapest_package_that_leaves_headroom(): void
    {
        // Load 1.0 needs 2 processors at 70%; 6 GB used needs 7.5 GB at 80%: C-12 is the smallest that has both.
        $analysis = $this->analyse(
            [$this->machine('big', 'p36')],
            [$this->disk('big', 300)],
            ['big' => $this->samples(21, 12, 1.0, 0.05, 36000, 6144)],
        );
        $server = $this->server($analysis, 'big');

        $this->assertSame('step_down', $server['verdict']);
        $this->assertSame('C-12', $server['candidate']['name']);
        $this->assertSame(['cpus' => 2, 'memory_gb' => 7.5], $server['needs']);
        // Now: 136. On C-12: 44 plus 150 GB of the 300 GB disk beyond the package at 0.2 = 74. Half price.
        $this->assertSame(136.0, $server['cost']['list']);
        $this->assertSame(68.0, $server['cost']['paid']);
        $this->assertSame(74.0, $server['candidate']['cost_list']);
        $this->assertSame(31.0, $server['candidate']['saving_paid']);
        $this->assertSame(31.0, $analysis['fleet']['possible_saving_paid']);
    }

    public function test_a_memory_package_is_never_offered_when_it_has_more_memory_than_now(): void
    {
        // M-40 has the processors and is cheaper than C-36, but it is not "smaller" in memory.
        $analysis = $this->analyse(
            [$this->machine('db', 'p36')],
            [$this->disk('db', 300)],
            ['db' => $this->samples(21, 12, 2.0, 0.1, 36000, 30000)],
        );
        $server = $this->server($analysis, 'db');

        $this->assertSame('keep', $server['verdict']);
        $this->assertNull($server['candidate']);
        $this->assertStringContainsString('memory is what it needs most', implode(' ', $server['reasons']));
    }

    public function test_a_server_between_the_two_marks_is_kept(): void
    {
        // Load 7 on 8 is over the 70% headroom mark for anything smaller and under the "too high" mark.
        $server = $this->server($this->analyse(
            [$this->machine('mid', 'p24')],
            [$this->disk('mid', 225)],
            ['mid' => $this->samples(21, 8, 7.0, 0.5, 24000, 8000)],
        ), 'mid');

        $this->assertSame('keep', $server['verdict']);
        $this->assertStringContainsString('processors is what it needs most', implode(' ', $server['reasons']));
    }

    public function test_a_server_with_load_above_its_processors_is_moved_up(): void
    {
        $server = $this->server($this->analyse(
            [$this->machine('hot', 'p24')],
            [$this->disk('hot', 300)],
            ['hot' => $this->samples(21, 8, 8.4, 0.6, 24000, 12000)],
        ), 'hot');

        $this->assertSame('step_up', $server['verdict']);
        $this->assertSame('C-36', $server['candidate']['name']);
        // 90 + 75 GB extra disk = 105 now; 136 on C-36. 31 more at list, 15.50 at half price.
        $this->assertSame(-15.5, $server['candidate']['saving_paid']);
        $this->assertStringContainsString('above the number of processors', $server['reasons'][0]);
    }

    public function test_busy_processors_or_full_memory_also_mean_too_small(): void
    {
        $busy = $this->server($this->analyse(
            [$this->machine('busy', 'p12')], [$this->disk('busy', 150)],
            ['busy' => $this->samples(21, 4, 2.0, 0.9, 12000, 6000)],
        ), 'busy');
        $this->assertSame('step_up', $busy['verdict']);
        $this->assertStringContainsString('90% busy', implode(' ', $busy['reasons']));

        $full = $this->server($this->analyse(
            [$this->machine('full', 'p12')], [$this->disk('full', 150)],
            ['full' => $this->samples(21, 4, 1.0, 0.1, 12000, 11500)],
        ), 'full');
        $this->assertSame('step_up', $full['verdict']);
        $this->assertStringContainsString('Memory is 96% used', implode(' ', $full['reasons']));
    }

    public function test_too_small_with_nothing_bigger_on_offer_says_so(): void
    {
        $server = $this->server($this->analyse(
            [$this->machine('top', 'p36')], [$this->disk('top', 300)],
            ['top' => $this->samples(21, 12, 30.0, 0.95, 36000, 20000)],
        ), 'top');

        $this->assertSame('step_up', $server['verdict']);
        $this->assertNull($server['candidate']);
        $this->assertStringContainsString('No bigger package of this kind is big enough', implode(' ', $server['reasons']));
    }

    public function test_nothing_is_suggested_before_enough_days_of_readings(): void
    {
        $analysis = $this->analyse(
            [$this->machine('new', 'p36')],
            [$this->disk('new', 300)],
            ['new' => $this->samples(3, 12, 1.0, 0.05, 36000, 6144)],
        );
        $server = $this->server($analysis, 'new');

        $this->assertSame('wait', $server['verdict']);
        $this->assertSame('step_down', $server['indication']);
        $this->assertSame('C-12', $server['candidate']['name']);
        // Three days back from eight in the morning touches four calendar days.
        $this->assertSame('Readings on only 4 day(s); 14 are needed before a change is suggested.', $server['reasons'][0]);
        // A change that is not yet suggested is not counted as a saving.
        $this->assertSame(0.0, $analysis['fleet']['possible_saving_paid']);
        $this->assertSame(4, $analysis['history_days']);
    }

    public function test_the_smallest_package_says_it_is_the_smallest(): void
    {
        $server = $this->server($this->analyse(
            [$this->machine('tiny', 'p1')], [$this->disk('tiny', 10)],
            ['tiny' => $this->samples(21, 1, 0.1, 0.02, 1000, 300)],
        ), 'tiny');

        $this->assertSame('keep', $server['verdict']);
        $this->assertSame(['It is already on the smallest package.'], $server['reasons']);
        $this->assertNull($server['steps']['down']);
        $this->assertSame('C-3', $server['steps']['up']['name']);
    }

    public function test_the_steps_either_side_come_from_the_same_family_and_skip_unpriced_and_dedicated(): void
    {
        $server = $this->server($this->analyse(
            [$this->machine('mid', 'p24')], [$this->disk('mid', 225)],
            ['mid' => $this->samples(21, 8, 7.0, 0.5, 24000, 12000)],
        ), 'mid');

        $this->assertSame('C-12', $server['steps']['down']['name']);
        $this->assertSame('C-36', $server['steps']['up']['name']);
        // C-12 with a 225 GB disk: 44 + 75 x 0.2 = 59, half price.
        $this->assertSame(29.5, $server['steps']['down']['cost_paid']);
    }

    public function test_a_smaller_package_that_saves_nothing_once_its_disk_is_paid_for_is_not_suggested(): void
    {
        // C-3 with a 100 GB disk: 12 + 75 x 0.2 = 27. C-1 would be 8 + 90 x 0.2 = 26, under a pound less.
        $server = $this->server($this->analyse(
            [$this->machine('disky', 'p3')], [$this->disk('disky', 100)],
            ['disky' => $this->samples(21, 1, 0.1, 0.02, 3000, 500)],
        ), 'disky');

        $this->assertSame('keep', $server['verdict']);
        $this->assertSame(['The next size down would fit but saves nothing once its disk is paid for.'], $server['reasons']);
    }

    public function test_keep_names_both_limits_when_both_are_short(): void
    {
        $server = $this->server($this->analyse(
            [$this->machine('both', 'p24')], [$this->disk('both', 225)],
            ['both' => $this->samples(21, 8, 7.0, 0.5, 24000, 12000)],
        ), 'both');

        $this->assertSame(['No smaller package leaves headroom; processors and memory are what it needs most.'], $server['reasons']);
    }

    public function test_a_package_with_no_price_has_no_steps_either_side(): void
    {
        $server = $this->server($this->analyse(
            [$this->machine('odd', 'unpriced')], [$this->disk('odd', 15)],
            ['odd' => $this->samples(21, 1, 0.1, 0.02, 2000, 500)],
        ), 'odd');

        $this->assertSame(['down' => null, 'up' => null], $server['steps']);
    }

    /**
     * Move a run of readings back in time by whole days.
     */
    private function aged(array $samples, int $days): array
    {
        return array_map(function (array $sample) use ($days) {
            $sample['sampled_at'] = Carbon::parse($sample['sampled_at'])->subDays($days)->toDateTimeString();

            return $sample;
        }, $samples);
    }

    public function test_one_reading_after_an_outage_is_not_enough_for_a_move_down(): void
    {
        // Twenty quiet days, a ten-day gap in sampling, then a single reading this morning.
        $old = $this->aged($this->samples(20, 12, 1.0, 0.05, 36000, 6144), 10);
        $today = $this->samples(0, 12, 1.0, 0.05, 36000, 6144);
        $server = $this->server($this->analyse(
            [$this->machine('gap', 'p36')], [$this->disk('gap', 300)], ['gap' => array_merge($old, $today)],
        ), 'gap');

        $this->assertSame(1, $server['week']['samples']);
        $this->assertSame('wait', $server['verdict']);
        $this->assertSame('step_down', $server['indication']);
        $this->assertSame('Readings on only 1 day(s) of the last week; 5 are needed before a move down is suggested.', $server['reasons'][0]);
    }

    public function test_a_quiet_week_does_not_hide_a_busy_one_earlier_in_the_window(): void
    {
        // Load 9 on 12 processors three weeks ago, load 1 since. Anything smaller would not have coped.
        $busy = $this->aged($this->samples(7, 12, 9.0, 0.6, 36000, 20000), 14);
        $quiet = $this->samples(14, 12, 1.0, 0.05, 36000, 6144);
        $server = $this->server($this->analyse(
            [$this->machine('peaky', 'p36')], [$this->disk('peaky', 300)], ['peaky' => array_merge($busy, $quiet)],
        ), 'peaky');

        $this->assertSame('keep', $server['verdict']);
        $this->assertSame(9.0, $server['peak']['load_p95']);
        $this->assertSame(13, $server['needs']['cpus']);
        $this->assertSame(0.08, $server['week']['load_per_cpu_p95']);
    }

    public function test_readings_from_before_a_resize_are_left_out(): void
    {
        // Three busy weeks as a 12-processor machine, then five days as a 4-processor one.
        $before = $this->aged($this->samples(20, 12, 9.0, 0.6, 36000, 20000), 6);
        $after = $this->samples(5, 4, 1.0, 0.1, 12000, 4000);
        $server = $this->server($this->analyse(
            [$this->machine('shrunk', 'p12')], [$this->disk('shrunk', 150)], ['shrunk' => array_merge($before, $after)],
        ), 'shrunk');

        // Nothing from the bigger machine is counted: not its load, and not its days.
        $this->assertSame(1.0, $server['peak']['load_p95']);
        $this->assertSame(6, $server['history_days']);
        $this->assertSame('wait', $server['verdict']);
        $this->assertCount(1, $server['trend']);
    }

    public function test_a_server_using_swap_is_not_offered_less_memory(): void
    {
        $server = $this->server($this->analyse(
            [$this->machine('swappy', 'p36')], [$this->disk('swappy', 300)],
            ['swappy' => $this->samples(21, 12, 1.0, 0.05, 36000, 6144, ['swap_used_mb' => 5120])],
        ), 'swappy');

        $this->assertSame('keep', $server['verdict']);
        $this->assertNull($server['candidate']);
        $this->assertSame(['It has used up to 5.0 GB of swap, so it is not offered less memory.'], $server['reasons']);
    }

    public function test_a_move_up_needs_three_days_of_the_week_but_not_a_fortnight(): void
    {
        $twoDays = $this->server($this->analyse(
            [$this->machine('hot', 'p24')], [$this->disk('hot', 300)],
            ['hot' => $this->samples(1, 8, 8.4, 0.6, 24000, 12000)],
        ), 'hot');
        $this->assertSame('wait', $twoDays['verdict']);
        $this->assertSame('step_up', $twoDays['indication']);
        $this->assertSame('C-36', $twoDays['candidate']['name']);
        $this->assertSame('Readings on only 2 day(s) of the last week; 3 are needed before a move up is suggested.', $twoDays['reasons'][0]);

        $analysis = $this->analyse(
            [$this->machine('hot', 'p24')], [$this->disk('hot', 300)],
            ['hot' => $this->samples(4, 8, 8.4, 0.6, 24000, 12000)],
        );
        $this->assertSame('step_up', $this->server($analysis, 'hot')['verdict']);
        $this->assertSame(15.5, $analysis['fleet']['possible_cost_paid']);
        $this->assertSame(0.0, $analysis['fleet']['possible_saving_paid']);
    }

    public function test_readings_are_matched_to_a_server_whatever_the_case_or_domain_of_its_name(): void
    {
        $analysis = $this->analyse(
            [$this->machine('big', 'p36')], [$this->disk('big', 300)],
            [
                'BIG.internal.example' => $this->samples(21, 12, 1.0, 0.05, 36000, 6144),
                'stray' => $this->samples(1, 1, 0.1, 0.02, 1000, 300),
            ],
        );

        $this->assertSame('step_down', $this->server($analysis, 'big')['verdict']);
        $this->assertSame(['stray'], $analysis['unmatched_hosts']);
    }

    public function test_a_server_whose_readings_stopped_says_when(): void
    {
        $server = $this->server($this->analyse(
            [$this->machine('quiet', 'p12')], [$this->disk('quiet', 150)],
            ['quiet' => $this->aged($this->samples(5, 4, 1.0, 0.1, 12000, 4000), 10)],
        ), 'quiet');

        $this->assertSame('unmeasured', $server['verdict']);
        $this->assertSame(['No readings in the last week; the last was on 20 Nov.'], $server['reasons']);
    }

    public function test_a_stopped_server_costs_its_disk_only_and_gets_no_size_verdict(): void
    {
        $analysis = $this->analyse([$this->machine('off', 'p1', 'stopped')], [$this->disk('off', 150)], []);
        $server = $this->server($analysis, 'off');

        $this->assertSame('stopped', $server['verdict']);
        $this->assertSame(30.0, $server['cost']['list']);
        $this->assertSame(15.0, $analysis['fleet']['monthly_paid']);
    }

    public function test_a_running_server_with_no_readings_is_unmeasured(): void
    {
        $server = $this->server($this->analyse([$this->machine('dark', 'p3')], [$this->disk('dark', 50)], []), 'dark');

        $this->assertSame('unmeasured', $server['verdict']);
        $this->assertFalse($server['measured']);
        $this->assertSame([], $server['disks']);
    }

    public function test_cost_counts_disk_beyond_the_package_and_extra_addresses(): void
    {
        $analysis = $this->analyse(
            [$this->machine('mail', 'p3', 'started', 3)],
            [$this->disk('mail', 100), $this->disk('mail', 75, 'not_built')],
            ['mail' => $this->samples(21, 1, 0.1, 0.02, 3000, 900)],
            0.0,
        );
        $server = $this->server($analysis, 'mail');

        // 12 + (100 - 25) x 0.2 + 2 extra addresses x 2. The disk that is not built is not counted.
        $this->assertSame(31.0, $server['cost']['list']);
        $this->assertSame(31.0, $server['cost']['paid']);
        $this->assertSame(75, $server['extra_disk_gb']);
        $this->assertCount(1, $analysis['unattached_disks']);
    }

    public function test_a_disk_attached_to_nothing_is_listed_and_costed(): void
    {
        $analysis = $this->analyse([], [['name' => 'orphan', 'size_gb' => 200, 'state' => 'built', 'machine_id' => null]], [], 0.5);

        $this->assertSame('orphan', $analysis['unattached_disks'][0]['name']);
        $this->assertSame(40.0, $analysis['fleet']['monthly_list']);
        $this->assertSame(20.0, $analysis['fleet']['monthly_paid']);
    }

    public function test_a_growing_disk_is_flagged_with_when_it_reaches_ninety_percent(): void
    {
        // 60 GB of 100 now, growing 1 GB a day: 30 GB to go to 90%, a little over four weeks.
        $samples = $this->samples(21, 4, 1.0, 0.1, 12000, 6000, fn (int $i) => [
            'disks' => json_encode([['mount' => '/', 'size_gb' => 100.0, 'used_gb' => 39.0 + $i / 48]]),
        ]);
        $server = $this->server($this->analyse([$this->machine('grow', 'p12')], [$this->disk('grow', 100)], ['grow' => $samples]), 'grow');
        $disk = $server['disks'][0];

        $this->assertSame(60.0, $disk['used_gb']);
        $this->assertSame(7.0, $disk['growth_gb_per_week']);
        $this->assertSame(4.3, $disk['weeks_to_90']);
        $this->assertTrue($disk['flag']);
    }

    public function test_a_steady_disk_is_not_flagged_and_a_nearly_full_one_is(): void
    {
        $steady = $this->server($this->analyse(
            [$this->machine('s', 'p12')], [$this->disk('s', 100)],
            ['s' => $this->samples(21, 4, 1.0, 0.1, 12000, 6000)],
        ), 's')['disks'][0];
        $this->assertFalse($steady['flag']);
        $this->assertSame(0.0, $steady['growth_gb_per_week']);
        $this->assertNull($steady['weeks_to_90']);

        $full = $this->server($this->analyse(
            [$this->machine('f', 'p12')], [$this->disk('f', 100)],
            ['f' => $this->samples(21, 4, 1.0, 0.1, 12000, 6000, ['disks' => [['mount' => '/', 'size_gb' => 100.0, 'used_gb' => 88.0]]])],
        ), 'f')['disks'][0];
        $this->assertTrue($full['flag']);
    }

    public function test_disk_growth_is_not_guessed_from_less_than_a_week(): void
    {
        $samples = $this->samples(3, 4, 1.0, 0.1, 12000, 6000, fn (int $i) => [
            'disks' => json_encode([['mount' => '/', 'size_gb' => 100.0, 'used_gb' => 40.0 + $i]]),
        ]);
        $disk = $this->server($this->analyse([$this->machine('g', 'p12')], [$this->disk('g', 100)], ['g' => $samples]), 'g')['disks'][0];

        $this->assertNull($disk['growth_gb_per_week']);
        $this->assertNull($disk['weeks_to_90']);
    }

    public function test_processor_time_ignores_a_reboot_and_a_long_gap(): void
    {
        $samples = $this->samples(21, 4, 1.0, 0.2, 12000, 6000);
        // A reboot: the counters start again from a small number half way through the last week.
        $restart = count($samples) - 10;
        for ($i = $restart, $total = 0, $idle = 0; $i < count($samples); $i++) {
            $total += 1000;
            $idle += 800;
            $samples[$i]['cpu_total'] = $total;
            $samples[$i]['cpu_idle'] = $idle;
        }
        $week = $this->server($this->analyse([$this->machine('r', 'p12')], [$this->disk('r', 150)], ['r' => $samples]), 'r')['week'];

        // Every usable interval is 20% busy; the one across the reboot would have read as nonsense.
        $this->assertSame(0.2, $week['cpu_busy_avg']);
        $this->assertSame(0.2, $week['cpu_busy_p95']);
    }

    public function test_the_trend_has_one_line_for_each_week_that_has_readings(): void
    {
        $server = $this->server($this->analyse(
            [$this->machine('t', 'p12')], [$this->disk('t', 150)],
            ['t' => $this->samples(20, 4, 2.0, 0.1, 12000, 6000)],
        ), 't');

        $this->assertCount(3, $server['trend']);
        $this->assertSame('30 Nov', $server['trend'][2]['week_ending']);
        $this->assertSame(0.5, $server['trend'][2]['load_per_cpu_p95']);
        $this->assertEquals(40.0, $server['trend'][2]['disk_used_gb']);
    }

    public function test_servers_are_listed_dearest_first_and_the_discount_is_kept_in_range(): void
    {
        $analysis = $this->analyse(
            [$this->machine('small', 'p3'), $this->machine('large', 'p36')],
            [$this->disk('small', 25), $this->disk('large', 300)],
            [],
            7.0,
        );

        $this->assertSame(['large', 'small'], array_column($analysis['servers'], 'name'));
        $this->assertSame(1.0, $analysis['discount']);
        $this->assertSame(0.0, $analysis['fleet']['monthly_paid']);
    }
}
