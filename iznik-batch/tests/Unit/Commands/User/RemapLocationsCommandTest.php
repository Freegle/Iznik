<?php

namespace Tests\Unit\Commands\User;

use App\Services\UserLocationRemapService;
use Illuminate\Console\Command;
use Tests\TestCase;

class RemapLocationsCommandTest extends TestCase
{
    public function test_dry_run_does_not_remap(): void
    {
        $service = $this->createMock(UserLocationRemapService::class);
        $service->expects($this->never())
            ->method('remapLocations');

        $this->app->instance(UserLocationRemapService::class, $service);

        $this->artisan('users:remap-locations --dry-run')
            ->expectsOutputToContain('Dry run — no changes made.')
            ->assertExitCode(Command::SUCCESS);
    }
}
