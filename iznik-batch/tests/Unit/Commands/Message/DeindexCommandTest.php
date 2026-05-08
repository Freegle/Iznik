<?php

namespace Tests\Unit\Commands\Message;

use App\Services\MessageDeindexService;
use Illuminate\Console\Command;
use Tests\TestCase;

class DeindexCommandTest extends TestCase
{
    public function test_dry_run_does_not_deindex(): void
    {
        $service = $this->createMock(MessageDeindexService::class);
        $service->expects($this->never())
            ->method('deindexOldMessages');

        $this->app->instance(MessageDeindexService::class, $service);

        $this->artisan('messages:deindex --dry-run')
            ->expectsOutputToContain('Dry run — no changes made.')
            ->assertExitCode(Command::SUCCESS);
    }
}
