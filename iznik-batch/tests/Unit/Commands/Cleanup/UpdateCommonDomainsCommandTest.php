<?php

namespace Tests\Unit\Commands\Cleanup;

use App\Services\CommonDomainsService;
use Illuminate\Console\Command;
use Tests\TestCase;

class UpdateCommonDomainsCommandTest extends TestCase
{
    public function test_dry_run_does_not_update(): void
    {
        $service = $this->createMock(CommonDomainsService::class);
        $service->expects($this->never())
            ->method('updateCommonDomains');

        $this->app->instance(CommonDomainsService::class, $service);

        $this->artisan('domains:update-common --dry-run')
            ->expectsOutputToContain('Dry run — no changes made.')
            ->assertExitCode(Command::SUCCESS);
    }
}
