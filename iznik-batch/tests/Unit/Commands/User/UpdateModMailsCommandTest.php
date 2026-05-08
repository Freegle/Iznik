<?php

namespace Tests\Unit\Commands\User;

use App\Services\UserModMailsService;
use Illuminate\Console\Command;
use Tests\TestCase;

class UpdateModMailsCommandTest extends TestCase
{
    public function test_dry_run_does_not_update(): void
    {
        $service = $this->createMock(UserModMailsService::class);
        $service->expects($this->never())
            ->method('updateModMails');

        $this->app->instance(UserModMailsService::class, $service);

        $this->artisan('users:update-modmails --dry-run')
            ->expectsOutputToContain('Dry run — no changes made.')
            ->assertExitCode(Command::SUCCESS);
    }
}
