<?php

namespace Tests\Feature\Hosting;

use App\Services\Hosting\DomainHostingService;
use Tests\TestCase;

class ScanDomainsCommandTest extends TestCase
{
    public function test_it_reports_the_counts_and_passes_the_limit(): void
    {
        $service = $this->createMock(DomainHostingService::class);
        $service->expects($this->once())->method('scan')->with(7)
            ->willReturn(['looked' => 7, 'wordpress' => 2, 'krystal' => 1, 'unreachable' => 3]);
        $this->app->instance(DomainHostingService::class, $service);

        $this->artisan('hosting:scan-domains', ['--limit' => 7])
            ->expectsOutputToContain('Looked at 7 domains: 2 WordPress, 1 on Krystal, 3 unreachable.')
            ->assertExitCode(0);
    }

    public function test_a_negative_limit_looks_at_nothing(): void
    {
        $service = $this->createMock(DomainHostingService::class);
        $service->expects($this->once())->method('scan')->with(0)
            ->willReturn(['looked' => 0, 'wordpress' => 0, 'krystal' => 0, 'unreachable' => 0]);
        $this->app->instance(DomainHostingService::class, $service);

        $this->artisan('hosting:scan-domains', ['--limit' => -5])->assertExitCode(0);
    }
}
