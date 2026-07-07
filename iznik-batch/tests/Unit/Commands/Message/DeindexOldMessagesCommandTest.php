<?php

namespace Tests\Unit\Commands\Message;

use App\Services\MessageSearchService;
use Tests\TestCase;

class DeindexOldMessagesCommandTest extends TestCase
{
    public function test_dry_run_calls_service_without_deleting(): void
    {
        $service = $this->createMock(MessageSearchService::class);
        $service->expects($this->once())->method('deindexOldMessages')->with(true)->willReturn(0);
        $this->app->instance(MessageSearchService::class, $service);

        $this->artisan('messages:deindex', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);
    }

    public function test_command_runs_service_and_reports_results(): void
    {
        $service = $this->createMock(MessageSearchService::class);
        $service->method('deindexOldMessages')->willReturn(12);
        $this->app->instance(MessageSearchService::class, $service);

        $this->artisan('messages:deindex')
            ->expectsOutputToContain('deleted: 12')
            ->assertExitCode(0);
    }
}
