<?php

namespace Tests\Unit\Commands\Message;

use App\Services\MessageSearchService;
use Tests\TestCase;

class DeindexOldMessagesCommandTest extends TestCase
{
    public function test_dry_run_returns_success_without_changes(): void
    {
        $service = $this->createMock(MessageSearchService::class);
        $service->expects($this->never())->method('deindexOldMessages');
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
