<?php

namespace Tests\Unit\Commands\AI;

use App\Services\MicroVolunteeringScoreService;
use Illuminate\Console\Command;
use Tests\TestCase;

class MicroVolunteeringScoreCommandTest extends TestCase
{
    public function test_dry_run_does_not_score(): void
    {
        $service = $this->createMock(MicroVolunteeringScoreService::class);
        $service->expects($this->never())
            ->method('scoreAndPromote');

        $this->app->instance(MicroVolunteeringScoreService::class, $service);

        $this->artisan('microvolunteering:score --dry-run')
            ->expectsOutputToContain('Dry run — no changes made.')
            ->assertExitCode(Command::SUCCESS);
    }
}
