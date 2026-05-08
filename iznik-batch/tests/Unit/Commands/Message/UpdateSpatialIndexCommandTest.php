<?php

namespace Tests\Unit\Commands\Message;

use App\Services\MessageSpatialService;
use Tests\TestCase;

class UpdateSpatialIndexCommandTest extends TestCase
{
    public function test_dry_run_returns_success_without_changes(): void
    {
        $service = $this->createMock(MessageSpatialService::class);
        $service->expects($this->never())->method('updateSpatialIndex');
        $this->app->instance(MessageSpatialService::class, $service);

        $this->artisan('messages:update-spatial-index', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);
    }

    public function test_command_runs_service_and_reports_results(): void
    {
        $service = $this->createMock(MessageSpatialService::class);
        $service->method('updateSpatialIndex')->willReturn(42);
        $this->app->instance(MessageSpatialService::class, $service);

        $this->artisan('messages:update-spatial-index')
            ->expectsOutputToContain('42 row(s) processed')
            ->assertExitCode(0);
    }
}
