<?php

namespace Tests\Unit\Commands\Data;

use App\Services\AppVersionFetcherService;
use Illuminate\Console\Command;
use Tests\TestCase;

class FetchAppVersionsCommandTest extends TestCase
{
    public function test_dry_run_calls_service_without_writing(): void
    {
        $service = $this->createMock(AppVersionFetcherService::class);
        $service->expects($this->once())
            ->method('fetchAll')
            ->with(true)
            ->willReturn(['fetched' => [], 'failed' => [], 'writes' => []]);

        $this->app->instance(AppVersionFetcherService::class, $service);

        $this->artisan('data:fetch-app-versions --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(Command::SUCCESS);
    }
}
