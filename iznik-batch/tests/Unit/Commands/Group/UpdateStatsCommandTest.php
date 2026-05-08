<?php

namespace Tests\Unit\Commands\Group;

use App\Services\GroupStatsService;
use Illuminate\Console\Command;
use Tests\TestCase;

class UpdateStatsCommandTest extends TestCase
{
    public function test_dry_run_calls_service_without_writing(): void
    {
        $service = $this->createMock(GroupStatsService::class);
        $service->expects($this->once())
            ->method('updateGroupStats')
            ->with(true)
            ->willReturn([
                'reposts_fixed' => 0,
                'polyindex_fixed' => 0,
                'activity_updated' => 0,
                'last_moderated_updated' => 0,
                'last_autoapprove_updated' => 0,
                'mod_counts_updated' => 0,
                'stats_outcomes_updated' => 0,
            ]);

        $this->app->instance(GroupStatsService::class, $service);

        $this->artisan('groups:update-stats --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(Command::SUCCESS);
    }
}
