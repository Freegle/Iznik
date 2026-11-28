<?php

namespace Tests\Feature\Servers;

use App\Models\ServerJobAlert;
use App\Models\ServerSample;
use App\Monitoring\HostCommandRunner;
use App\Monitoring\PlatformStatusWriter;
use App\Services\Servers\ServerProbe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * servers:sample asks every monitored host for one reading and stores it. A fake runner stands in
 * for ssh, answering per target, so no host is contacted under test.
 */
class SampleServersCommandTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $answers = [];

    /** @var list<array{0: string, 1: string}> */
    public array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;
        $this->app->bind(HostCommandRunner::class, fn () => new class($test) implements HostCommandRunner
        {
            public function __construct(private SampleServersCommandTest $test) {}

            public function run(string $target, string $script): ?string
            {
                $this->test->calls[] = [$target, $script];

                return $this->test->answer($target);
            }
        });
    }

    public function answer(string $target): ?string
    {
        return $this->answers[$target] ?? null;
    }

    private function reading(string $host, float $load = 1.5): string
    {
        return implode("\n", [
            "HOST:{$host}",
            'CPUS:4',
            "LOAD:{$load} {$load} {$load} 1/100 999",
            'STAT:cpu  100 0 100 700 100 0 0 0 0 0',
            'MEM:MemTotal:       8000000 kB',
            'MEM:MemAvailable:    4000000 kB',
            'DISK:/dev/vda1 '.(100 * 1024 ** 3).' '.(25 * 1024 ** 3).' /',
        ]);
    }

    public function test_a_reading_is_stored_for_each_host_that_answers(): void
    {
        config(['freegle.monitoring.hosts' => 'root@one, root@two']);
        $this->answers = ['root@one' => $this->reading('one-'.uniqid()), 'root@two' => $this->reading('two-'.uniqid(), 0.2)];
        $before = ServerSample::count();

        $this->artisan('servers:sample')
            ->expectsOutputToContain('Sampled 2 host(s)')
            ->assertExitCode(0);

        $this->assertSame($before + 2, ServerSample::count());
        $this->assertSame(['root@one', 'root@two'], array_column($this->calls, 0));
        $this->assertSame(ServerProbe::SCRIPT, $this->calls[0][1]);

        $row = ServerSample::orderByDesc('id')->first();
        $this->assertStringStartsWith('two-', $row->host);
        $this->assertSame(4, $row->cpus);
        $this->assertSame(0.2, $row->load5);
        $this->assertSame(1000, $row->cpu_total);
        $this->assertSame(800, $row->cpu_idle);
        $this->assertEquals([['mount' => '/', 'size_gb' => 100, 'used_gb' => 25]], $row->disks);
        $this->assertTrue($row->sampled_at->gt(Carbon::now()->subMinute()));
    }

    public function test_a_host_that_does_not_answer_is_skipped_and_the_rest_are_stored(): void
    {
        $host = 'one-'.uniqid();
        config(['freegle.monitoring.hosts' => 'root@one,root@silent,root@garbled']);
        $this->answers = ['root@one' => $this->reading($host), 'root@garbled' => 'HOST:half-a-reading'];

        $this->artisan('servers:sample')
            ->expectsOutputToContain('2 did not answer (root@silent, root@garbled)')
            ->assertExitCode(0);

        $this->assertSame(1, ServerSample::where('host', $host)->count());
        $this->assertSame(0, ServerSample::where('host', 'half-a-reading')->count());
    }

    public function test_sizing_only_hosts_are_sampled_too_and_a_host_in_both_lists_is_sampled_once(): void
    {
        $extra = 'extra-'.uniqid();
        config(['freegle.monitoring.hosts' => 'root@one', 'freegle.servers.extra_hosts' => 'root@extra, root@one']);
        $this->answers = ['root@one' => $this->reading('one-'.uniqid()), 'root@extra' => $this->reading($extra)];

        $this->artisan('servers:sample')->expectsOutputToContain('Sampled 2 host(s)')->assertExitCode(0);

        $this->assertSame(['root@one', 'root@extra'], array_column($this->calls, 0));
        $this->assertSame(1, ServerSample::where('host', $extra)->count());
    }

    private function platformStatus(array $slugs, string $generatedAt): void
    {
        DB::table('config')->upsert(
            [['key' => PlatformStatusWriter::CONFIG_KEY, 'value' => json_encode([
                'info' => (object) array_fill_keys($slugs, ['error' => true]),
                'generated_at' => $generatedAt,
            ])]],
            ['key'],
            ['value'],
        );
    }

    public function test_the_late_job_alerts_showing_now_are_recorded_without_the_host_checks(): void
    {
        $slug = 'digest backlog '.uniqid();
        config(['freegle.monitoring.hosts' => 'root@one']);
        $this->answers = ['root@one' => $this->reading('one-'.uniqid())];
        $this->platformStatus([$slug, 'host:one'], Carbon::now()->subMinutes(5)->toIso8601String());

        $this->artisan('servers:sample')->expectsOutputToContain('1 late-job alert(s) showing')->assertExitCode(0);

        $this->assertSame(1, ServerJobAlert::where('slug', $slug)->count());
        $this->assertSame(0, ServerJobAlert::where('slug', 'host:one')->where('sampled_at', '>', Carbon::now()->subMinute())->count());
    }

    public function test_a_stale_or_missing_status_records_no_alerts(): void
    {
        $slug = 'old alert '.uniqid();
        config(['freegle.monitoring.hosts' => 'root@one']);
        $this->answers = ['root@one' => $this->reading('one-'.uniqid())];

        $this->platformStatus([$slug], Carbon::now()->subHours(2)->toIso8601String());
        $this->artisan('servers:sample')->expectsOutputToContain('0 late-job alert(s) showing')->assertExitCode(0);
        $this->assertSame(0, ServerJobAlert::where('slug', $slug)->count());

        DB::table('config')->where('key', PlatformStatusWriter::CONFIG_KEY)->delete();
        $this->artisan('servers:sample')->expectsOutputToContain('0 late-job alert(s) showing')->assertExitCode(0);
    }

    public function test_old_alerts_are_removed_with_old_readings(): void
    {
        $slug = 'ancient '.uniqid();
        config(['freegle.monitoring.hosts' => 'root@one', 'freegle.servers.sample_retention_days' => 30]);
        $this->answers = ['root@one' => $this->reading('one-'.uniqid())];
        ServerJobAlert::create(['slug' => $slug, 'sampled_at' => Carbon::now()->subDays(31)]);
        ServerJobAlert::create(['slug' => $slug, 'sampled_at' => Carbon::now()->subDays(29)]);

        $this->artisan('servers:sample')->assertExitCode(0);

        $this->assertSame(1, ServerJobAlert::where('slug', $slug)->count());
    }

    public function test_every_host_silent_is_a_failed_run(): void
    {
        config(['freegle.monitoring.hosts' => 'root@silent']);

        $this->artisan('servers:sample')->assertExitCode(1);
    }

    public function test_no_hosts_configured_does_nothing(): void
    {
        config(['freegle.monitoring.hosts' => '']);

        $this->artisan('servers:sample')
            ->expectsOutputToContain('nothing to sample')
            ->assertExitCode(0);

        $this->assertSame([], $this->calls);
    }

    public function test_readings_older_than_the_retention_setting_are_removed(): void
    {
        $host = 'old-'.uniqid();
        config(['freegle.monitoring.hosts' => 'root@one', 'freegle.servers.sample_retention_days' => 30]);
        $this->answers = ['root@one' => $this->reading($host)];

        $row = ['host' => $host, 'cpus' => 1, 'load1' => 0, 'load5' => 0, 'load15' => 0, 'cpu_total' => 1, 'cpu_idle' => 1,
            'mem_total_mb' => 1, 'mem_available_mb' => 1, 'swap_total_mb' => 0, 'swap_used_mb' => 0, 'disks' => []];
        ServerSample::create($row + ['sampled_at' => Carbon::now()->subDays(31)]);
        ServerSample::create($row + ['sampled_at' => Carbon::now()->subDays(29)]);

        $this->artisan('servers:sample')->assertExitCode(0);

        // The 29-day-old reading and the new one stay; the 31-day-old one goes.
        $this->assertSame(2, ServerSample::where('host', $host)->count());
        $this->assertSame(0, ServerSample::where('host', $host)->where('sampled_at', '<', Carbon::now()->subDays(30))->count());
    }
}
