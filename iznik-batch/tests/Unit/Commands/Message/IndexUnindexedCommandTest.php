<?php

namespace Tests\Unit\Commands\Message;

use App\Services\MessageIndexUnindexedService;
use Illuminate\Console\Command;
use Tests\TestCase;

class IndexUnindexedCommandTest extends TestCase
{
    public function test_dry_run_does_not_index(): void
    {
        $service = $this->createMock(MessageIndexUnindexedService::class);
        $service->expects($this->never())
            ->method('indexUnindexedMessages');

        $this->app->instance(MessageIndexUnindexedService::class, $service);

        $this->artisan('messages:index-unindexed --dry-run')
            ->expectsOutputToContain('Dry run — no changes made.')
            ->assertExitCode(Command::SUCCESS);
    }
}
