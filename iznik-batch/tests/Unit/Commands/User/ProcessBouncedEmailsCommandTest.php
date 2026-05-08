<?php

namespace Tests\Unit\Commands\User;

use App\Services\Mail\Incoming\BounceService;
use App\Services\UserManagementService;
use Tests\TestCase;

class ProcessBouncedEmailsCommandTest extends TestCase
{
    public function test_dry_run_returns_success_without_changes(): void
    {
        $userService = $this->createMock(UserManagementService::class);
        $userService->expects($this->never())->method('processBouncedEmails');
        $bounceService = $this->createMock(BounceService::class);
        $bounceService->expects($this->never())->method('suspendBouncingUsers');
        $this->app->instance(UserManagementService::class, $userService);
        $this->app->instance(BounceService::class, $bounceService);

        $this->artisan('mail:bounced', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);
    }

    public function test_command_runs_service_and_reports_results(): void
    {
        $userService = $this->createMock(UserManagementService::class);
        $userService->method('processBouncedEmails')->willReturn([
            'processed' => 10,
            'marked_invalid' => 3,
        ]);
        $bounceService = $this->createMock(BounceService::class);
        $bounceService->method('suspendBouncingUsers')->willReturn(1);
        $this->app->instance(UserManagementService::class, $userService);
        $this->app->instance(BounceService::class, $bounceService);

        $this->artisan('mail:bounced')
            ->expectsOutputToContain('Processed: 10')
            ->expectsOutputToContain('Suspended: 1')
            ->assertExitCode(0);
    }
}
