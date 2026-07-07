<?php

namespace Tests\Unit\Commands\Message;

use App\Services\MessageExpiryService;
use Illuminate\Console\Command;
use Tests\TestCase;

class ProcessExpiredMessagesCommandTest extends TestCase
{
    public function test_success_with_no_errors(): void
    {
        $service = $this->createMock(MessageExpiryService::class);
        $service->method('processDeadlineExpired')
            ->willReturn(['processed' => 3, 'emails_sent' => 3, 'errors' => 0]);

        $this->app->instance(MessageExpiryService::class, $service);

        $this->artisan('messages:process-expired')
            ->expectsOutputToContain('Deadline expired: 3 processed, 3 emails sent')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_failure_when_errors_present(): void
    {
        $service = $this->createMock(MessageExpiryService::class);
        $service->method('processDeadlineExpired')
            ->willReturn(['processed' => 0, 'emails_sent' => 0, 'errors' => 2]);

        $this->app->instance(MessageExpiryService::class, $service);

        $this->artisan('messages:process-expired')
            ->expectsOutputToContain('Errors: 2')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_dry_run_announces_mode(): void
    {
        $service = $this->createMock(MessageExpiryService::class);
        $service->expects($this->once())
            ->method('processDeadlineExpired')
            ->with(true)
            ->willReturn(['processed' => 0, 'emails_sent' => 0, 'errors' => 0]);

        $this->app->instance(MessageExpiryService::class, $service);

        $this->artisan('messages:process-expired', ['--dry-run' => true])
            ->expectsOutputToContain('no changes will be made')
            ->expectsOutputToContain('[DRY RUN] Deadline expired: 0')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_spatial_option_triggers_spatial_processing(): void
    {
        $service = $this->createMock(MessageExpiryService::class);
        $service->expects($this->once())
            ->method('processDeadlineExpired')
            ->with(false)
            ->willReturn(['processed' => 1, 'emails_sent' => 1, 'errors' => 0]);
        $service->expects($this->once())
            ->method('processExpiredFromSpatialIndex')
            ->with(false)
            ->willReturn(4);

        $this->app->instance(MessageExpiryService::class, $service);

        $this->artisan('messages:process-expired', ['--spatial' => true])
            ->expectsOutputToContain('Spatial index: 4 messages processed')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_no_spatial_by_default(): void
    {
        $service = $this->createMock(MessageExpiryService::class);
        $service->expects($this->once())
            ->method('processDeadlineExpired')
            ->willReturn(['processed' => 0, 'emails_sent' => 0, 'errors' => 0]);
        $service->expects($this->never())
            ->method('processExpiredFromSpatialIndex');

        $this->app->instance(MessageExpiryService::class, $service);

        $this->artisan('messages:process-expired')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_dry_run_false_by_default(): void
    {
        $service = $this->createMock(MessageExpiryService::class);
        $service->expects($this->once())
            ->method('processDeadlineExpired')
            ->with(false)
            ->willReturn(['processed' => 0, 'emails_sent' => 0, 'errors' => 0]);

        $this->app->instance(MessageExpiryService::class, $service);

        $this->artisan('messages:process-expired')
            ->assertExitCode(Command::SUCCESS);
    }
}
