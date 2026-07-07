<?php

namespace Tests\Feature\Chat;

use App\Services\ChatProcessService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProcessIncomingChatCommandTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function test_dry_run_reports_pending_count(): void
    {
        $this->artisan('chats:process-incoming', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN]')
            ->assertExitCode(0);
    }

    /** @test */
    public function test_command_runs_with_mocked_service(): void
    {
        $mock = $this->createMock(ChatProcessService::class);
        $mock->method('processIncoming')->willReturn(3);
        $this->app->instance(ChatProcessService::class, $mock);

        $this->artisan('chats:process-incoming')
            ->expectsOutputToContain('3 message(s) processed')
            ->assertExitCode(0);
    }
}
