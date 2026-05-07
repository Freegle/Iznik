<?php

namespace Tests\Unit\Commands\Message;

use App\Services\AutoRepostService;
use Illuminate\Console\Command;
use Tests\TestCase;

class AutoRepostCommandTest extends TestCase
{
    public function test_success_with_no_errors(): void
    {
        $service = $this->createMock(AutoRepostService::class);
        $service->method('process')
            ->willReturn(['reposted' => 10, 'warned' => 3, 'skipped' => 1, 'errors' => 0]);

        $this->app->instance(AutoRepostService::class, $service);

        $this->artisan('messages:auto-repost')
            ->expectsOutputToContain('Reposted: 10, Warned: 3, Skipped: 1, Errors: 0')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_failure_when_errors_present(): void
    {
        $service = $this->createMock(AutoRepostService::class);
        $service->method('process')
            ->willReturn(['reposted' => 0, 'warned' => 0, 'skipped' => 0, 'errors' => 2]);

        $this->app->instance(AutoRepostService::class, $service);

        $this->artisan('messages:auto-repost')
            ->expectsOutputToContain('Errors: 2')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_dry_run_announces_mode(): void
    {
        $service = $this->createMock(AutoRepostService::class);
        $service->expects($this->once())
            ->method('process')
            ->with(true)
            ->willReturn(['reposted' => 0, 'warned' => 0, 'skipped' => 0, 'errors' => 0]);

        $this->app->instance(AutoRepostService::class, $service);

        $this->artisan('messages:auto-repost', ['--dry-run' => true])
            ->expectsOutputToContain('no changes will be made')
            ->expectsOutputToContain('[DRY RUN] Reposted: 0')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_dry_run_false_by_default(): void
    {
        $service = $this->createMock(AutoRepostService::class);
        $service->expects($this->once())
            ->method('process')
            ->with(false)
            ->willReturn(['reposted' => 0, 'warned' => 0, 'skipped' => 0, 'errors' => 0]);

        $this->app->instance(AutoRepostService::class, $service);

        $this->artisan('messages:auto-repost')
            ->assertExitCode(Command::SUCCESS);
    }
}
