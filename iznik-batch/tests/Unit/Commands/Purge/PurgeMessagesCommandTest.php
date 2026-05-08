<?php

namespace Tests\Unit\Commands\Purge;

use App\Services\PurgeService;
use Tests\TestCase;

class PurgeMessagesCommandTest extends TestCase
{
    public function test_dry_run_calls_service_without_deleting(): void
    {
        $service = $this->createMock(PurgeService::class);
        $service->method('purgeOldMessagesHistory')->willReturn(0);
        $service->method('purgePendingMessages')->willReturn(0);
        $service->method('purgeOldDrafts')->willReturn(0);
        $service->method('purgeNonFreegleMessages')->willReturn(0);
        $service->method('purgeDeletedMessages')->willReturn(0);
        $service->method('purgeStrandedMessages')->willReturn(0);
        $service->method('purgeHtmlBody')->willReturn(0);
        $service->method('purgeMessageSource')->willReturn(0);
        $service->method('purgeOrphanedIsochrones')->willReturn(0);
        $service->method('purgeCompletedAdmins')->willReturn(0);
        $service->method('purgeUsersNearby')->willReturn(0);
        $service->method('purgeUnvalidatedEmails')->willReturn(0);
        $this->app->instance(PurgeService::class, $service);

        $this->artisan('purge:messages', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);
    }

    public function test_command_runs_service_and_reports_results(): void
    {
        $service = $this->createMock(PurgeService::class);
        $service->method('purgeOldMessagesHistory')->willReturn(5);
        $service->method('purgePendingMessages')->willReturn(2);
        $service->method('purgeOldDrafts')->willReturn(1);
        $service->method('purgeNonFreegleMessages')->willReturn(0);
        $service->method('purgeDeletedMessages')->willReturn(3);
        $service->method('purgeStrandedMessages')->willReturn(0);
        $service->method('purgeHtmlBody')->willReturn(10);
        $service->method('purgeMessageSource')->willReturn(4);
        $service->method('purgeOrphanedIsochrones')->willReturn(0);
        $service->method('purgeCompletedAdmins')->willReturn(0);
        $service->method('purgeUsersNearby')->willReturn(0);
        $service->method('purgeUnvalidatedEmails')->willReturn(0);
        $this->app->instance(PurgeService::class, $service);

        $this->artisan('purge:messages')
            ->expectsOutputToContain('Message purge complete')
            ->assertExitCode(0);
    }
}
