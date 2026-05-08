<?php

namespace Tests\Unit\Commands\Message;

use App\Services\MessageIllustrationsService;
use Illuminate\Console\Command;
use Tests\TestCase;

class GenerateIllustrationsCommandTest extends TestCase
{
    public function test_dry_run_does_not_generate(): void
    {
        $service = $this->createMock(MessageIllustrationsService::class);
        $service->expects($this->never())
            ->method('processIllustrations');

        $this->app->instance(MessageIllustrationsService::class, $service);

        $this->artisan('messages:generate-illustrations --dry-run')
            ->expectsOutputToContain('Dry run — no changes made.')
            ->assertExitCode(Command::SUCCESS);
    }
}
