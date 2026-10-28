<?php

namespace Tests\Feature\Ux;

use App\Services\Ux\UxExperimentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakesUxLoki;
use Tests\TestCase;

/**
 * The deploy note for a finished experiment (config/ux_cleanups.php): the hourly tick closes each listed experiment that
 * has a verdict, and leaves everything else alone. docs/developers/reference/ux-experiments.md, "Corpse control".
 */
class UxCleanupsTest extends TestCase
{
    use FakesUxLoki;

    private const PR = 'https://github.com/Freegle/Iznik/pull/4001';

    private UxExperimentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 10:00:00');
        $this->fakeLoki();
        foreach (['ux_experiment_reads', 'ux_experiments', 'ux_flow_outcome_daily'] as $t) {
            DB::table($t)->delete();
        }
        DB::table('config')->where('key', UxExperimentService::CONFIG_KEY)->delete();
        config()->set('ux_switches', []);
        config()->set('ux_cleanups', []);
        $this->service = app(UxExperimentService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function experiment(string $name, string $status, ?string $cleanup = null): int
    {
        $id = $this->service->create([
            'flow' => 'give', 'name' => $name, 'title' => 'T', 'hypothesis' => 'H', 'change' => 'C', 'expected' => 'E',
        ]);
        DB::table('ux_experiments')->where('id', $id)->update([
            'status' => $status, 'decided_at' => '2026-10-20 08:00:00', 'cleanup_pr_url' => $cleanup,
        ]);

        return $id;
    }

    private function note(string $name, string $pr = self::PR): array
    {
        return ['name' => $name, 'cleanup_pr' => $pr];
    }

    private function row(int $id): object
    {
        return DB::table('ux_experiments')->where('id', $id)->first();
    }

    public function test_it_closes_each_experiment_with_a_verdict(): void
    {
        $ids = [];
        foreach (['reverted', 'inconclusive', 'stopped', 'adopted'] as $status) {
            $ids[$status] = $this->experiment("e-$status", $status);
        }
        config()->set('ux_cleanups', array_map(fn ($s) => $this->note("e-$s"), array_keys($ids)));

        $lines = $this->service->applyCleanups();

        $this->assertCount(4, $lines);
        foreach (['reverted', 'inconclusive', 'stopped'] as $status) {
            $row = $this->row($ids[$status]);
            $this->assertSame($status, $row->status, 'the outcome is kept');
            $this->assertSame(self::PR, $row->cleanup_pr_url);
        }
        $this->assertSame('closed', $this->row($ids['adopted'])->status);
        $this->assertSame(self::PR, $this->row($ids['adopted'])->cleanup_pr_url);
    }

    public function test_it_leaves_draft_running_and_unknown_experiments_alone(): void
    {
        $draft = $this->experiment('e-draft', 'draft');
        $running = $this->experiment('e-running', 'running');
        config()->set('ux_cleanups', [$this->note('e-draft'), $this->note('e-running'), $this->note('e-unknown')]);

        $this->assertSame([], $this->service->applyCleanups());

        $this->assertSame('draft', $this->row($draft)->status);
        $this->assertSame('running', $this->row($running)->status);
        $this->assertEmpty($this->row($draft)->cleanup_pr_url);
        $this->assertEmpty($this->row($running)->cleanup_pr_url);
    }

    public function test_it_does_not_touch_one_that_is_already_closed(): void
    {
        $recorded = $this->experiment('e-recorded', 'reverted', 'https://github.com/Freegle/Iznik/pull/3000');
        $closed = $this->experiment('e-closed', 'closed', 'https://github.com/Freegle/Iznik/pull/3001');
        config()->set('ux_cleanups', [$this->note('e-recorded'), $this->note('e-closed')]);

        $this->assertSame([], $this->service->applyCleanups());

        $this->assertSame('https://github.com/Freegle/Iznik/pull/3000', $this->row($recorded)->cleanup_pr_url);
        $this->assertSame('https://github.com/Freegle/Iznik/pull/3001', $this->row($closed)->cleanup_pr_url);
    }

    public function test_it_refuses_a_name_whose_switch_is_still_listed(): void
    {
        $id = $this->experiment('e-live', 'reverted');
        config()->set('ux_switches', [['name' => 'e-live']]);
        config()->set('ux_cleanups', [$this->note('e-live')]);

        $this->assertSame([], $this->service->applyCleanups());

        $this->assertEmpty($this->row($id)->cleanup_pr_url);
    }

    public function test_it_is_idempotent(): void
    {
        $id = $this->experiment('e-once', 'reverted');
        config()->set('ux_cleanups', [$this->note('e-once')]);

        $this->assertCount(1, $this->service->applyCleanups());
        $updated = $this->row($id)->updated_at;
        Carbon::setTestNow('2026-10-23 11:00:00');

        $this->assertSame([], $this->service->applyCleanups());
        $this->assertSame($updated, $this->row($id)->updated_at);
    }

    public function test_a_bad_entry_does_not_stop_the_others(): void
    {
        $id = $this->experiment('e-good', 'inconclusive');
        config()->set('ux_cleanups', [
            'not an array',
            ['name' => 'e-good'],
            $this->note('bad name!'),
            $this->note('e-badpr', 'https://example.com/pull/1'),
            ['name' => ['x'], 'cleanup_pr' => self::PR],
            $this->note('e-good'),
        ]);

        $lines = $this->service->applyCleanups();

        $this->assertCount(1, $lines);
        $this->assertSame(self::PR, $this->row($id)->cleanup_pr_url);
    }

    public function test_a_close_that_fails_is_reported_and_the_rest_still_close(): void
    {
        $failing = $this->experiment('e-fails', 'reverted');
        $good = $this->experiment('e-closes', 'inconclusive');
        config()->set('ux_cleanups', [$this->note('e-fails'), $this->note('e-closes')]);

        $service = \Mockery::mock(UxExperimentService::class)->makePartial();
        $service->shouldReceive('close')->with($failing, self::PR, \Mockery::any())->andThrow(new \RuntimeException('boom'));
        $service->shouldReceive('close')->with($good, self::PR, \Mockery::any())->andReturnUsing(
            fn ($id, $pr, $now) => $this->service->close($id, $pr, $now)
        );

        $lines = $service->applyCleanups();

        $this->assertSame(['e-fails: cleanup failed, left alone (boom)', 'e-closes: closed, cleanup '.self::PR], $lines);
        $this->assertNull($this->row($failing)->cleanup_pr_url);
        $this->assertSame(self::PR, $this->row($good)->cleanup_pr_url);
    }

    public function test_the_hourly_tick_applies_the_notes(): void
    {
        $id = $this->experiment('e-tick', 'reverted');
        config()->set('ux_cleanups', [$this->note('e-tick')]);

        Artisan::call('ux:experiments-tick');

        $this->assertStringContainsString('e-tick: closed', Artisan::output());
        $this->assertSame(self::PR, $this->row($id)->cleanup_pr_url);
        $this->assertSame(0, $this->service->cleanupDue()->where('id', $id)->count(), 'no longer due');
    }

    public function test_the_shipped_notes_are_well_formed_and_name_no_listed_switch(): void
    {
        $shippedCleanups = require base_path('config/ux_cleanups.php');
        $shippedSwitches = array_column(require base_path('config/ux_switches.php'), 'name');
        $seen = [];

        foreach ($shippedCleanups as $entry) {
            $this->assertSame(['cleanup_pr', 'name'], collect(array_keys($entry))->sort()->values()->all());
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}\z/', $entry['name']);
            $this->assertMatchesRegularExpression(UxExperimentService::CLEANUP_PR_PATTERN, $entry['cleanup_pr'], $entry['name']);
            $this->assertNotContains($entry['name'], $shippedSwitches, "{$entry['name']} still has a switch in ux_switches.php");
            $this->assertArrayNotHasKey($entry['name'], $seen, "{$entry['name']} is listed twice");
            $seen[$entry['name']] = true;
        }
    }
}
