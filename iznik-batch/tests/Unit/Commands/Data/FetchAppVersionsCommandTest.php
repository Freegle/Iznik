<?php

namespace Tests\Unit\Commands\Data;

use App\Services\AppVersionFetcherService;
use Illuminate\Console\Command;
use Tests\TestCase;

class FetchAppVersionsCommandTest extends TestCase
{
    public function test_dry_run_does_not_fetch_versions(): void
    {
        $service = $this->createMock(AppVersionFetcherService::class);
        $service->expects($this->never())
            ->method('fetchAll');

        $this->app->instance(AppVersionFetcherService::class, $service);

        $this->artisan('data:fetch-app-versions --dry-run')
            ->expectsOutputToContain('Dry run — no changes made.')
            ->assertExitCode(Command::SUCCESS);
    }
}
