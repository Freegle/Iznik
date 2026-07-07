<?php

namespace Tests\Unit\Commands\User;

use App\Services\UserModMailsService;
use Illuminate\Console\Command;
use Tests\TestCase;

class UpdateModMailsCommandTest extends TestCase
{
    public function test_dry_run_calls_service_without_writing(): void
    {
        $service = $this->createMock(UserModMailsService::class);
        $service->expects($this->once())
            ->method('updateModMails')
            ->with(true)
            ->willReturn(0);
        $service->expects($this->once())
            ->method('pruneOldEntries')
            ->with(true)
            ->willReturn(0);

        $this->app->instance(UserModMailsService::class, $service);

        $this->artisan('users:update-modmails --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(Command::SUCCESS);
    }
}
