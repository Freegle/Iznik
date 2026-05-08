<?php

namespace Tests\Unit\Commands\Group;

use App\Services\GroupStatsService;
use Illuminate\Console\Command;
use Tests\TestCase;

class UpdateStatsCommandTest extends TestCase
{
    public function test_dry_run_does_not_update(): void
    {
        $service = $this->createMock(GroupStatsService::class);
        $service->expects($this->never())
            ->method('updateGroupStats');

        $this->app->instance(GroupStatsService::class, $service);

        $this->artisan('groups:update-stats --dry-run')
            ->expectsOutputToContain('Dry run — no changes made.')
            ->assertExitCode(Command::SUCCESS);
    }
}
