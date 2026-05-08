<?php

namespace Tests\Unit\Commands\Message;

use App\Services\MessageRemapSubjectsService;
use Illuminate\Console\Command;
use Tests\TestCase;

class RemapSubjectsCommandTest extends TestCase
{
    public function test_dry_run_does_not_remap(): void
    {
        $service = $this->createMock(MessageRemapSubjectsService::class);
        $service->expects($this->never())
            ->method('remapSubjects');

        $this->app->instance(MessageRemapSubjectsService::class, $service);

        $this->artisan('messages:remap-subjects --dry-run')
            ->expectsOutputToContain('Dry run — no changes made.')
            ->assertExitCode(Command::SUCCESS);
    }
}
