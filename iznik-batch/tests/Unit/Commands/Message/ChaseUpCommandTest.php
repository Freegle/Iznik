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
}
