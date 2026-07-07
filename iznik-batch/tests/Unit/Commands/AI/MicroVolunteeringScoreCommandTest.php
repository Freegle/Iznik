<?php

namespace Tests\Unit\Commands\AI;

use App\Services\MicroVolunteeringScoreService;
use Illuminate\Console\Command;
use Tests\TestCase;

class MicroVolunteeringScoreCommandTest extends TestCase
{
    public function test_dry_run_calls_service_without_writing(): void
    {
        $service = $this->createMock(MicroVolunteeringScoreService::class);
        $service->expects($this->once())
            ->method('scoreAndPromote')
            ->with('48 hours ago', true)
            ->willReturn(['scored' => 0, 'promoted' => 0]);

        $this->app->instance(MicroVolunteeringScoreService::class, $service);

        $this->artisan('microvolunteering:score --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(Command::SUCCESS);
    }
}
