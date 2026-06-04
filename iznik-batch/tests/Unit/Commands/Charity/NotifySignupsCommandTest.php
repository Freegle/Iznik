<?php

namespace Tests\Unit\Commands\Charity;

use App\Services\CharitySignupNotifyService;
use Illuminate\Console\Command;
use Tests\TestCase;

class NotifySignupsCommandTest extends TestCase
{
    public function test_reports_notified_count(): void
    {
        $service = $this->createMock(CharitySignupNotifyService::class);
        $service->method('process')->willReturn(['notified' => 3]);
        $this->app->instance(CharitySignupNotifyService::class, $service);

        $this->artisan('charity:notify-signups')
            ->expectsOutputToContain('New charity signups notified: 3')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_dry_run_announces_and_passes_flag(): void
    {
        $service = $this->createMock(CharitySignupNotifyService::class);
        $service->expects($this->once())
            ->method('process')
            ->with(true)
            ->willReturn(['notified' => 0]);
        $this->app->instance(CharitySignupNotifyService::class, $service);

        $this->artisan('charity:notify-signups', ['--dry-run' => true])
            ->expectsOutputToContain('no email will be sent')
            ->expectsOutputToContain('[DRY RUN] New charity signups notified: 0')
            ->assertExitCode(Command::SUCCESS);
    }
}
