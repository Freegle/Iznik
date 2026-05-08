<?php

namespace Tests\Unit\Commands\Message;

use App\Services\MessageSearchService;
use Tests\TestCase;

class UpdateSearchIndexCommandTest extends TestCase
{
    public function test_dry_run_calls_service_without_indexing(): void
    {
        $service = $this->createMock(MessageSearchService::class);
        $service->expects($this->once())->method('indexUnindexedMessages')->with(true)->willReturn(0);
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
