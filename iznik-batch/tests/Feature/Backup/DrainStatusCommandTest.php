<?php

namespace Tests\Feature\Backup;

use Carbon\Carbon;
use Tests\TestCase;

/**
 * The backup script calls this before it desyncs the node, to check that batch work really
 * is being held off. The exit code is a signal to log or alert on: a backup that did not
 * run is worse than one that ran alongside some batch work, so nothing here aborts a backup.
 */
class DrainStatusCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function configure(array $overrides = []): void
    {
        config()->set('freegle.backup.drain', array_merge([
            'enabled' => true,
            'start' => '03:50',
            'minutes' => 45,
            'always_run' => [],
        ], $overrides));
    }

    public function test_succeeds_and_says_when_the_window_ends(): void
    {
        $this->configure();
        Carbon::setTestNow(Carbon::parse('2026-09-18 04:00:00', config('app.timezone')));

        $this->artisan('backup:drain-status')
            ->expectsOutputToContain('held off until 04:35')
            ->assertExitCode(0);
    }

    public function test_fails_when_the_drain_is_not_in_force(): void
    {
        $this->configure();
        Carbon::setTestNow(Carbon::parse('2026-09-18 12:00:00', config('app.timezone')));

        $this->artisan('backup:drain-status')
            ->expectsOutputToContain('NOT being held off')
            ->assertExitCode(1);
    }

    public function test_fails_when_the_drain_is_switched_off_entirely(): void
    {
        $this->configure(['enabled' => false]);
        Carbon::setTestNow(Carbon::parse('2026-09-18 04:00:00', config('app.timezone')));

        $this->artisan('backup:drain-status')->assertExitCode(1);
    }
}
