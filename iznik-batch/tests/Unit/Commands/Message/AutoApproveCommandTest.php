<?php

namespace Tests\Unit\Commands\Message;

use App\Services\AutoApproveService;
use Illuminate\Console\Command;
use Tests\TestCase;

class AutoApproveCommandTest extends TestCase
{
    public function test_success_with_no_errors(): void
    {
        $service = $this->createMock(AutoApproveService::class);
        $service->method('process')
            ->willReturn(['approved' => 5, 'skipped' => 2, 'errors' => 0]);

        $this->app->instance(AutoApproveService::class, $service);

        $this->artisan('messages:auto-approve')
            ->expectsOutputToContain('Approved: 5, Skipped: 2, Errors: 0')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_failure_when_errors_present(): void
    {
        $service = $this->createMock(AutoApproveService::class);
        $service->method('process')
            ->willReturn(['approved' => 1, 'skipped' => 0, 'errors' => 3]);

        $this->app->instance(AutoApproveService::class, $service);

        $this->artisan('messages:auto-approve')
            ->expectsOutputToContain('Errors: 3')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_dry_run_announces_mode(): void
    {
        $service = $this->createMock(AutoApproveService::class);
        $service->expects($this->once())
            ->method('process')
            ->with(true)
            ->willReturn(['approved' => 0, 'skipped' => 0, 'errors' => 0]);

        $this->app->instance(AutoApproveService::class, $service);

        $this->artisan('messages:auto-approve', ['--dry-run' => true])
            ->expectsOutputToContain('no changes will be made')
            ->expectsOutputToContain('[DRY RUN] Approved: 0')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_dry_run_passes_flag_to_service(): void
    {
        $service = $this->createMock(AutoApproveService::class);
        $service->expects($this->once())
            ->method('process')
            ->with(false)
            ->willReturn(['approved' => 0, 'skipped' => 0, 'errors' => 0]);

        $this->app->instance(AutoApproveService::class, $service);

        $this->artisan('messages:auto-approve')
            ->assertExitCode(Command::SUCCESS);
    }
}
