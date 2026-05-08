<?php

namespace Tests\Unit\Commands\Jobs;

use App\Services\JobIllustrationsService;
use Illuminate\Console\Command;
use Tests\TestCase;

class GenerateIllustrationsCommandTest extends TestCase
{
    public function test_dry_run_calls_service_without_generating(): void
    {
        $service = $this->createMock(JobIllustrationsService::class);
        $service->expects($this->once())
            ->method('processIllustrations')
            ->with(true)
            ->willReturn(['remaining' => 0, 'would_fetch' => 0]);

        $this->app->instance(JobIllustrationsService::class, $service);

        $this->artisan('jobs:generate-illustrations --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(Command::SUCCESS);
    }
}
