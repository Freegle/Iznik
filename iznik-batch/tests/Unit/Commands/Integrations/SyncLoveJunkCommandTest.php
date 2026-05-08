<?php

namespace Tests\Unit\Commands\Integrations;

use App\Services\LoveJunkService;
use Illuminate\Console\Command;
use Tests\TestCase;

class SyncLoveJunkCommandTest extends TestCase
{
    public function test_dry_run_does_not_sync(): void
    {
        $service = $this->createMock(LoveJunkService::class);
        $service->expects($this->never())
            ->method('sync');

        $this->app->instance(LoveJunkService::class, $service);

        $this->artisan('integrations:sync-lovejunk --dry-run')
            ->expectsOutputToContain('Dry run — no changes made.')
            ->assertExitCode(Command::SUCCESS);
    }
}
