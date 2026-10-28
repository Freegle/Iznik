<?php

namespace Tests\Feature\Ux;

use App\Services\Ux\UxExperimentRefused;
use App\Services\Ux\UxExperimentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesUxLoki;
use Tests\TestCase;

/**
 * An experiment that ended without being adopted still leaves its variant code behind. The tick
 * says so at once, and `close` records the cleanup without changing the outcome.
 */
class UxExperimentCleanupDueTest extends TestCase
{
    use FakesUxLoki;

    private const PR = 'https://github.com/Freegle/Iznik/pull/9999';

    private UxExperimentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ux_cleanups', []);
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->fakeLoki();
        foreach (['ux_experiment_reads', 'ux_experiments', 'ux_flow_outcome_daily'] as $t) {
            DB::table($t)->delete();
        }
        DB::table('config')->where('key', UxExperimentService::CONFIG_KEY)->delete();
        $this->service = app(UxExperimentService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ended(string $status, string $name = 'join-what-happens-next', string $decided = '2026-10-20 08:00:00'): int
    {
        $id = $this->service->create([
            'flow' => 'give', 'name' => $name, 'title' => 'T', 'hypothesis' => 'H', 'change' => 'C', 'expected' => 'E',
        ]);
        DB::table('ux_experiments')->where('id', $id)->update(['status' => $status, 'decided_at' => $decided]);

        return $id;
    }

    private function tick(): string
    {
        Artisan::call('ux:experiments-tick');

        return Artisan::output();
    }

    public function test_the_tick_says_cleanup_is_due_at_once_for_every_ended_status(): void
    {
        $this->ended('reverted', 'join-what-happens-next');
        $this->ended('inconclusive', 'give-photos-carry-on');
        $this->ended('stopped', 'push-posts-title');

        $out = $this->tick();

        $this->assertStringContainsString('Cleanup due: join-what-happens-next was reverted on 2026-10-20 and its code is still in the codebase. Remove it and run ux:experiment close join-what-happens-next --cleanup-pr=<url>.', $out);
        $this->assertStringContainsString('Cleanup due: give-photos-carry-on was inconclusive on 2026-10-20', $out);
        $this->assertStringContainsString('Cleanup due: push-posts-title was stopped on 2026-10-20', $out);
    }

    public function test_an_ended_experiment_with_a_cleanup_recorded_is_not_due(): void
    {
        $id = $this->ended('reverted');
        $this->service->close($id, self::PR);

        $this->assertStringNotContainsString('Cleanup', $this->tick());
    }

    public function test_an_adopted_experiment_keeps_its_seven_day_rule(): void
    {
        $this->ended('adopted', 'fresh', '2026-10-18 00:00:00');
        $this->ended('adopted', 'old', '2026-10-10 00:00:00');

        $out = $this->tick();

        $this->assertStringNotContainsString('Cleanup overdue: fresh', $out);
        $this->assertStringContainsString('Cleanup overdue: old was adopted on 2026-10-10', $out);
    }

    public function test_a_draft_and_a_closed_experiment_are_never_due(): void
    {
        $this->service->create(['flow' => 'give', 'name' => 'draft-one', 'title' => 'T', 'hypothesis' => 'H', 'change' => 'C', 'expected' => 'E']);
        $this->ended('closed', 'done');

        $this->assertStringNotContainsString('Cleanup', $this->tick());
    }

    #[DataProvider('endedStatuses')]
    public function test_close_keeps_the_outcome_and_records_the_cleanup(string $status): void
    {
        $id = $this->ended($status);

        $exp = $this->service->close($id, self::PR);

        $this->assertSame($status, $exp->status);
        $this->assertSame(self::PR, $exp->cleanup_pr_url);
    }

    public static function endedStatuses(): array
    {
        return ['reverted' => ['reverted'], 'inconclusive' => ['inconclusive'], 'stopped' => ['stopped']];
    }

    public function test_close_needs_the_cleanup_pull_request_for_an_ended_experiment(): void
    {
        $id = $this->ended('reverted');

        foreach ([null, ''] as $none) {
            try {
                $this->service->close($id, $none);
                $this->fail('closed without a cleanup pull request');
            } catch (UxExperimentRefused $e) {
                $this->assertStringContainsString('cleanup pull request', $e->getMessage());
            }
        }
        $this->assertNull($this->service->find($id)->cleanup_pr_url);
    }

    public function test_close_still_turns_an_adopted_experiment_into_closed(): void
    {
        $id = $this->ended('adopted');

        $this->assertSame('closed', $this->service->close($id, self::PR)->status);
    }

    public function test_close_still_refuses_a_running_or_draft_experiment(): void
    {
        $id = $this->ended('running');

        $this->expectException(UxExperimentRefused::class);
        $this->service->close($id, self::PR);
    }

    public function test_start_refuses_an_experiment_whose_code_is_gone(): void
    {
        $id = $this->ended('reverted');
        $this->service->close($id, self::PR);

        try {
            $this->service->start($id);
            $this->fail('started an experiment whose code is gone');
        } catch (UxExperimentRefused $e) {
            $this->assertStringContainsString('code has been removed', $e->getMessage());
            $this->assertStringContainsString('new name', $e->getMessage());
            $this->assertStringContainsString('salt', $e->getMessage());
        }
        $this->assertSame('reverted', $this->service->find($id)->status);
    }

    public function test_start_still_works_for_an_ended_experiment_whose_code_is_in_place(): void
    {
        $id = $this->ended('reverted');

        $this->assertSame('running', $this->service->start($id)->status);
    }

    public function test_the_command_closes_an_ended_experiment_and_keeps_its_status(): void
    {
        $this->ended('inconclusive', 'cmd-ended');

        $this->artisan('ux:experiment close cmd-ended')->assertExitCode(1);
        $this->artisan('ux:experiment close cmd-ended --cleanup-pr='.self::PR)
            ->expectsOutputToContain('cmd-ended: inconclusive')
            ->assertExitCode(0);
    }
}
