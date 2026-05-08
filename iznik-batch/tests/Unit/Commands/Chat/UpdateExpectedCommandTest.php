<?php

namespace Tests\Unit\Commands\Chat;

use App\Services\ChatExpectedService;
use Illuminate\Console\Command;
use Tests\TestCase;

class UpdateExpectedCommandTest extends TestCase
{
    public function test_dry_run_calls_service_without_writing(): void
    {
        $service = $this->createMock(ChatExpectedService::class);
        $service->expects($this->once())
            ->method('updateChatExpected')
            ->with(true)
            ->willReturn(['deleted_cleared' => 0, 'spam_cleared' => 0, 'waiting' => 0, 'received' => 0]);

        $this->app->instance(ChatExpectedService::class, $service);

        $this->artisan('chats:update-expected --dry-run')
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(Command::SUCCESS);
    }
}
