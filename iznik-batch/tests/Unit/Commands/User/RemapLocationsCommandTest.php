<?php

namespace Tests\Unit\Commands\User;

use App\Services\UserLocationRemapService;
use Illuminate\Console\Command;
use Tests\TestCase;

class RemapLocationsCommandTest extends TestCase
{
    public function test_dry_run_calls_service_without_writing(): void
    {
        $service = $this->createMock(UserLocationRemapService::class);
        $service->expects($this->once())
            ->method('remapLocations')
            ->with(true)
            ->willReturn(['changed' => 0, 'checked' => 0, 'samples' => []]);

        $this->app->instance(UserLocationRemapService::class, $service);

        $this->artisan('users:remap-locations --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(Command::SUCCESS);
    }
}
