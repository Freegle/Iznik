<?php

namespace Tests\Unit\Commands\Message;

use App\Services\MessageSearchService;
use Tests\TestCase;

class UpdateSearchIndexCommandTest extends TestCase
{
    public function test_dry_run_returns_success_without_changes(): void
    {
        $service = $this->createMock(MessageSearchService::class);
        $service->expects($this->never())->method('indexUnindexedMessages');
        $this->app->instance(MessageSearchService::class, $service);

        $this->artisan('messages:update-index', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);
    }

    public function test_command_runs_service_and_reports_results(): void
    {
        $service = $this->createMock(MessageSearchService::class);
        $service->method('indexUnindexedMessages')->willReturn(5);
        $this->app->instance(MessageSearchService::class, $service);

        $this->artisan('messages:update-index')
            ->expectsOutputToContain('indexed: 5')
            ->assertExitCode(0);
    }
}
