<?php

namespace Tests\Unit\Commands\Message;

use App\Services\ChaseUpService;
use Illuminate\Console\Command;
use Tests\TestCase;

class ChaseUpCommandTest extends TestCase
{
    public function test_success_with_no_errors(): void
    {
        $service = $this->createMock(ChaseUpService::class);
        $service->method('tidyOutcomes')->willReturn(4);
        $service->method('processIntendedOutcomes')->willReturn(2);
        $service->method('notifyLanguishing')->willReturn(1);
        $service->method('process')
            ->willReturn(['chased' => 7, 'skipped' => 3, 'errors' => 0]);

        $this->app->instance(ChaseUpService::class, $service);

        $this->artisan('messages:chase-up')
            ->expectsOutputToContain('Tidied 4 outcomes')
            ->expectsOutputToContain('Processed 2 intended outcomes')
            ->expectsOutputToContain('Found 1 languishing posts')
            ->expectsOutputToContain('Chased: 7, Skipped: 3, Errors: 0')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_failure_when_errors_present(): void
    {
        $service = $this->createMock(ChaseUpService::class);
        $service->method('tidyOutcomes')->willReturn(0);
        $service->method('processIntendedOutcomes')->willReturn(0);
        $service->method('notifyLanguishing')->willReturn(0);
        $service->method('process')
            ->willReturn(['chased' => 0, 'skipped' => 0, 'errors' => 5]);

        $this->app->instance(ChaseUpService::class, $service);

        $this->artisan('messages:chase-up')
            ->expectsOutputToContain('Errors: 5')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_dry_run_announces_mode(): void
    {
        $service = $this->createMock(ChaseUpService::class);
        $service->expects($this->once())->method('tidyOutcomes')->with(true)->willReturn(0);
        $service->expects($this->once())->method('processIntendedOutcomes')->with(true)->willReturn(0);
        $service->expects($this->once())->method('notifyLanguishing')->with(true)->willReturn(0);
        $service->expects($this->once())->method('process')->with(true)
            ->willReturn(['chased' => 0, 'skipped' => 0, 'errors' => 0]);

        $this->app->instance(ChaseUpService::class, $service);

        $this->artisan('messages:chase-up', ['--dry-run' => true])
            ->expectsOutputToContain('no changes will be made')
            ->expectsOutputToContain('[DRY RUN] Chased: 0')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_dry_run_false_by_default(): void
    {
        $service = $this->createMock(ChaseUpService::class);
        $service->expects($this->once())->method('tidyOutcomes')->with(false)->willReturn(0);
        $service->expects($this->once())->method('processIntendedOutcomes')->with(false)->willReturn(0);
        $service->expects($this->once())->method('notifyLanguishing')->with(false)->willReturn(0);
        $service->expects($this->once())->method('process')->with(false)
            ->willReturn(['chased' => 0, 'skipped' => 0, 'errors' => 0]);

        $this->app->instance(ChaseUpService::class, $service);

        $this->artisan('messages:chase-up')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_all_four_service_methods_are_called(): void
    {
        $service = $this->createMock(ChaseUpService::class);
        $service->expects($this->once())->method('tidyOutcomes')->willReturn(0);
        $service->expects($this->once())->method('processIntendedOutcomes')->willReturn(0);
        $service->expects($this->once())->method('notifyLanguishing')->willReturn(0);
        $service->expects($this->once())->method('process')
            ->willReturn(['chased' => 0, 'skipped' => 0, 'errors' => 0]);

        $this->app->instance(ChaseUpService::class, $service);

        $this->artisan('messages:chase-up')->assertExitCode(Command::SUCCESS);
    }

    /**
     * The hourly run skips the languishing scan, which finds the same posts every time
     * and can raise at most one notification per person per day. The other two cheap
     * sub-passes must keep running hourly.
     */
    public function test_skip_languishing_leaves_the_other_passes_hourly(): void
    {
        $service = $this->createMock(ChaseUpService::class);
        $service->expects($this->once())->method('tidyOutcomes')->willReturn(1);
        $service->expects($this->once())->method('processIntendedOutcomes')->willReturn(2);
        $service->expects($this->never())->method('notifyLanguishing');
        $service->expects($this->once())->method('process')
            ->willReturn(['chased' => 3, 'skipped' => 0, 'errors' => 0]);

        $this->app->instance(ChaseUpService::class, $service);

        $this->artisan('messages:chase-up', ['--skip-languishing' => true])
            ->expectsOutputToContain('Tidied 1 outcomes')
            ->expectsOutputToContain('Processed 2 intended outcomes')
            ->expectsOutputToContain('Chased: 3')
            ->assertExitCode(Command::SUCCESS);
    }

    /** The daily run does the languishing scan and nothing else. */
    public function test_languishing_only_runs_just_that_scan(): void
    {
        $service = $this->createMock(ChaseUpService::class);
        $service->expects($this->never())->method('tidyOutcomes');
        $service->expects($this->never())->method('processIntendedOutcomes');
        $service->expects($this->once())->method('notifyLanguishing')->with(false)->willReturn(9);
        $service->expects($this->never())->method('process');

        $this->app->instance(ChaseUpService::class, $service);

        $this->artisan('messages:chase-up', ['--languishing-only' => true])
            ->expectsOutputToContain('Found 9 languishing posts')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_languishing_only_honours_dry_run(): void
    {
        $service = $this->createMock(ChaseUpService::class);
        $service->expects($this->once())->method('notifyLanguishing')->with(true)->willReturn(0);
        $service->expects($this->never())->method('process');

        $this->app->instance(ChaseUpService::class, $service);

        $this->artisan('messages:chase-up', ['--languishing-only' => true, '--dry-run' => true])
            ->expectsOutputToContain('no changes will be made')
            ->assertExitCode(Command::SUCCESS);
    }
}
