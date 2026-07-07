<?php

namespace Tests\Unit\Commands\Message;

use App\Services\MessageRemapSubjectsService;
use Illuminate\Console\Command;
use Tests\TestCase;

class RemapSubjectsCommandTest extends TestCase
{
    public function test_dry_run_calls_service_without_writing(): void
    {
        $service = $this->createMock(MessageRemapSubjectsService::class);
        $service->expects($this->once())
            ->method('remapSubjects')
            ->with(true)
            ->willReturn(['changed' => 0, 'checked' => 0, 'samples' => []]);

        $this->app->instance(MessageRemapSubjectsService::class, $service);

        $this->artisan('messages:remap-subjects --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(Command::SUCCESS);
    }
}
