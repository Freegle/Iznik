<?php

namespace Tests\Unit\Commands\Cleanup;

use App\Services\CommonDomainsService;
use Illuminate\Console\Command;
use Tests\TestCase;

class UpdateCommonDomainsCommandTest extends TestCase
{
    public function test_dry_run_calls_service_without_writing(): void
    {
        $service = $this->createMock(CommonDomainsService::class);
        $service->expects($this->once())
            ->method('updateCommonDomains')
            ->with(true)
            ->willReturn(['emails_scanned' => 0, 'distinct_domains' => 0, 'domains_inserted' => 0, 'writes' => []]);

        $this->app->instance(CommonDomainsService::class, $service);

        $this->artisan('domains:update-common --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(Command::SUCCESS);
    }
}
