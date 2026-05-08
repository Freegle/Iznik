<?php

namespace Tests\Unit\Commands\Chat;

use App\Services\ChatExpectedService;
use Illuminate\Console\Command;
use Tests\TestCase;

class UpdateExpectedCommandTest extends TestCase
{
    public function test_dry_run_does_not_update(): void
    {
        $service = $this->createMock(ChatExpectedService::class);
        $service->expects($this->never())
            ->method('updateChatExpected');

        $this->app->instance(ChatExpectedService::class, $service);

        $this->artisan('chats:update-expected --dry-run')
            ->expectsOutputToContain('Dry run — no changes made.')
            ->assertExitCode(Command::SUCCESS);
    }
}
