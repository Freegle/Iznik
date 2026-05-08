<?php

namespace Tests\Unit\Commands\Integrations;

use App\Services\LoveJunkService;
use Illuminate\Console\Command;
use Tests\TestCase;

class SyncLoveJunkCommandTest extends TestCase
{
    public function test_dry_run_calls_service_without_syncing(): void
    {
        $service = $this->createMock(LoveJunkService::class);
        $service->expects($this->once())
            ->method('sync')
            ->with(true)
            ->willReturn(['sent' => 0, 'edited' => 0, 'completed_or_deleted' => 0, 'failed' => 0]);

        $this->app->instance(LoveJunkService::class, $service);

        $this->artisan('integrations:sync-lovejunk --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(Command::SUCCESS);
    }
}
