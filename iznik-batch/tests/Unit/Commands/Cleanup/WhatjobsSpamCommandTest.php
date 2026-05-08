<?php

namespace Tests\Unit\Commands\Cleanup;

use App\Services\WhatjobsSpamService;
use Illuminate\Console\Command;
use Tests\TestCase;

class WhatjobsSpamCommandTest extends TestCase
{
    public function test_dry_run_calls_service_without_deleting(): void
    {
        $service = $this->createMock(WhatjobsSpamService::class);
        $service->expects($this->once())
            ->method('deleteSpammyJobs')
            ->with(true)
            ->willReturn(['deleted' => 0, 'spam_hashes' => 0, 'samples' => []]);

        $this->app->instance(WhatjobsSpamService::class, $service);

        $this->artisan('cleanup:whatjobs-spam --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(Command::SUCCESS);
    }
}
