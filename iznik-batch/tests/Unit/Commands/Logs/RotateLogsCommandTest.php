<?php

namespace Tests\Unit\Commands\Logs;

use App\Services\LogRotationService;
use Illuminate\Console\Command;
use Tests\TestCase;

class RotateLogsCommandTest extends TestCase
{
    public function test_runs_prune_then_compress_and_succeeds(): void
    {
        $service = $this->createMock(LogRotationService::class);
        $service->expects($this->once())
            ->method('prune')
            ->with($this->isType('string'), 7, false)
            ->willReturn(['deleted' => 2, 'bytes' => 1024, 'files' => []]);
        $service->expects($this->once())
            ->method('compress')
            ->with($this->isType('string'), false)
            ->willReturn(['compressed' => 3, 'bytes_before' => 2048, 'bytes_after' => 512, 'files' => []]);

        $this->app->instance(LogRotationService::class, $service);

        $this->artisan('logs:rotate')
            ->expectsOutputToContain('pruned 2')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_days_option_is_passed_to_prune(): void
    {
        $service = $this->createMock(LogRotationService::class);
        $service->expects($this->once())
            ->method('prune')
            ->with($this->isType('string'), 14, false)
            ->willReturn(['deleted' => 0, 'bytes' => 0, 'files' => []]);
        $service->method('compress')
            ->willReturn(['compressed' => 0, 'bytes_before' => 0, 'bytes_after' => 0, 'files' => []]);

        $this->app->instance(LogRotationService::class, $service);

        $this->artisan('logs:rotate', ['--days' => 14])
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_dry_run_is_passed_through_to_service(): void
    {
        $service = $this->createMock(LogRotationService::class);
        $service->expects($this->once())
            ->method('prune')
            ->with($this->isType('string'), 7, true)
            ->willReturn(['deleted' => 0, 'bytes' => 0, 'files' => []]);
        $service->expects($this->once())
            ->method('compress')
            ->with($this->isType('string'), true)
            ->willReturn(['compressed' => 0, 'bytes_before' => 0, 'bytes_after' => 0, 'files' => []]);

        $this->app->instance(LogRotationService::class, $service);

        $this->artisan('logs:rotate', ['--dry-run' => true])
            ->assertExitCode(Command::SUCCESS);
    }
}
