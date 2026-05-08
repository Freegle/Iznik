<?php

namespace Tests\Unit\Commands\Jobs;

use App\Services\JobIllustrationsService;
use Illuminate\Console\Command;
use Tests\TestCase;

class GenerateIllustrationsCommandTest extends TestCase
{
    public function test_dry_run_does_not_generate(): void
    {
        $service = $this->createMock(JobIllustrationsService::class);
        $service->expects($this->never())
            ->method('processIllustrations');

        $this->app->instance(JobIllustrationsService::class, $service);

        $this->artisan('jobs:generate-illustrations --dry-run')
            ->expectsOutputToContain('Dry run — no changes made.')
            ->assertExitCode(Command::SUCCESS);
    }
}
