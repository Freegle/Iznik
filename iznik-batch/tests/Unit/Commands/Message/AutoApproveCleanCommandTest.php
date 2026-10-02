<?php

namespace Tests\Unit\Commands\Message;

use App\Services\AutoApproveCleanService;
use Illuminate\Console\Command;
use Tests\TestCase;

class AutoApproveCleanCommandTest extends TestCase
{
    private function stats(array $overrides = []): array
    {
        return array_merge([
            'approved' => 0, 'held_quality' => 0, 'vetoed' => 0, 'skipped' => 0, 'errors' => 0,
        ], $overrides);
    }

    public function test_success_with_no_errors(): void
    {
        $service = $this->createMock(AutoApproveCleanService::class);
        $service->method('process')->willReturn($this->stats([
            'approved' => 5, 'held_quality' => 1, 'vetoed' => 2, 'skipped' => 3,
        ]));
        $this->app->instance(AutoApproveCleanService::class, $service);

        $this->artisan('messages:auto-approve-clean')
            ->expectsOutputToContain('Approved: 5, Held (quality): 1, Vetoed: 2, Skipped: 3, Errors: 0')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_failure_when_errors_present(): void
    {
        $service = $this->createMock(AutoApproveCleanService::class);
        $service->method('process')->willReturn($this->stats(['approved' => 1, 'errors' => 3]));
        $this->app->instance(AutoApproveCleanService::class, $service);

        $this->artisan('messages:auto-approve-clean')
            ->expectsOutputToContain('Errors: 3')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_dry_run_announces_mode_and_passes_flag(): void
    {
        $service = $this->createMock(AutoApproveCleanService::class);
        $service->expects($this->once())
            ->method('process')
            ->with(true)
            ->willReturn($this->stats());
        $this->app->instance(AutoApproveCleanService::class, $service);

        $this->artisan('messages:auto-approve-clean', ['--dry-run' => true])
            ->expectsOutputToContain('no changes will be made')
            ->expectsOutputToContain('[DRY RUN] Approved: 0')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_passes_false_when_not_dry_run(): void
    {
        $service = $this->createMock(AutoApproveCleanService::class);
        $service->expects($this->once())
            ->method('process')
            ->with(false)
            ->willReturn($this->stats());
        $this->app->instance(AutoApproveCleanService::class, $service);

        $this->artisan('messages:auto-approve-clean')
            ->assertExitCode(Command::SUCCESS);
    }
}
