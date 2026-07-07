<?php

namespace Tests\Unit\Commands\Message;

use App\Services\MessageSpatialService;
use Tests\TestCase;

class UpdateSpatialIndexCommandTest extends TestCase
{
    public function test_dry_run_calls_service_without_writing(): void
    {
        $service = $this->createMock(MessageSpatialService::class);
        $service->expects($this->once())->method('updateSpatialIndex')->with(true)->willReturn([
            'total' => 0,
            'upserted_recent' => 0,
            'outcomes_updated' => 0,
            'removed_deleted' => 0,
            'removed_old' => 0,
            'removed_non_approved' => 0,
        ]);
        $this->app->instance(MessageSpatialService::class, $service);

        $this->artisan('messages:update-spatial-index', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);
    }

    public function test_command_runs_service_and_reports_results(): void
    {
        $service = $this->createMock(MessageSpatialService::class);
        $service->method('updateSpatialIndex')->willReturn([
            'total' => 42,
            'upserted_recent' => 42,
            'outcomes_updated' => 0,
            'removed_deleted' => 0,
            'removed_old' => 0,
            'removed_non_approved' => 0,
        ]);
        $this->app->instance(MessageSpatialService::class, $service);

        $this->artisan('messages:update-spatial-index')
            ->expectsOutputToContain('updated 42 entries')
            ->assertExitCode(0);
    }
}
