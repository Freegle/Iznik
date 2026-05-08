<?php

namespace Tests\Unit\Commands\Message;

use App\Services\MessageIllustrationsService;
use Illuminate\Console\Command;
use Tests\TestCase;

class GenerateIllustrationsCommandTest extends TestCase
{
    public function test_dry_run_calls_service_without_generating(): void
    {
        $service = $this->createMock(MessageIllustrationsService::class);
        $service->expects($this->once())
            ->method('processIllustrations')
            ->with(true)
            ->willReturn(['cleaned' => 0, 'cached_hits' => 0, 'would_fetch' => 0]);

        $this->app->instance(MessageIllustrationsService::class, $service);

        $this->artisan('messages:generate-illustrations --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(Command::SUCCESS);
    }
}
