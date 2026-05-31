<?php

namespace Tests\Unit\Commands\Mail;

use App\Services\EmailSpoolerService;
use App\Services\ModNotifService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendModNotifsCommandTest extends TestCase
{
    public function test_dry_run_returns_success_without_sending(): void
    {
        $service = $this->createMock(ModNotifService::class);
        $service->method('getNotificationsToSend')->willReturn([]);
        $this->app->instance(ModNotifService::class, $service);

        $this->artisan('mail:mod-notifs', ['--dry-run' => true, '--force' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);
    }

    public function test_without_force_returns_success(): void
    {
        // Without --force the command respects the hour window.
        // We cannot control the clock in tests, so just verify exit code = 0.
        $service = $this->createMock(ModNotifService::class);
        $service->method('getNotificationsToSend')->willReturn([]);
        $this->app->instance(ModNotifService::class, $service);

        Mail::fake();

        $this->artisan('mail:mod-notifs')
            ->assertExitCode(0);
    }

    public function test_force_flag_skips_hour_window(): void
    {
        $service = $this->createMock(ModNotifService::class);
        $service->method('getNotificationsToSend')->willReturn([]);
        $this->app->instance(ModNotifService::class, $service);

        Mail::fake();

        $this->artisan('mail:mod-notifs', ['--force' => true])
            ->expectsOutputToContain('Moderator notifications to send: 0')
            ->assertExitCode(0);
    }

    public function test_sends_notification_in_normal_mode(): void
    {
        $notification = [
            'user_id'      => 99,
            'email'        => 'mod@example.com',
            'name'         => 'Test Mod',
            'text_summary' => '1 message to review',
            'html_summary' => '<p>1 message</p>',
            'total'        => 1,
            'subject'      => 'MODERATE: 1 thing to do',
        ];

        $service = $this->createMock(ModNotifService::class);
        $service->method('getNotificationsToSend')->willReturn([$notification]);
        $service->expects($this->once())->method('recordSent')->with(99, '1 message to review');
        $this->app->instance(ModNotifService::class, $service);

        Mail::fake();

        $this->artisan('mail:mod-notifs', ['--force' => true])
            ->expectsOutputToContain('Sent: 1')
            ->assertExitCode(0);
    }

    public function test_spool_failure_for_one_mod_does_not_abort_run(): void
    {
        // Two mods to notify. The spooler throws on the FIRST (e.g. a
        // transient MJML render error, which spool() re-throws because it is
        // not a permanent address failure). The command must skip that mod
        // and still notify the second — not abort the whole run.
        $notifications = [
            [
                'user_id' => 1, 'email' => 'mod1@example.com', 'name' => 'Mod One',
                'text_summary' => 'sum1', 'html_summary' => '<p>1</p>', 'total' => 1,
                'subject' => 'MODERATE: 1',
            ],
            [
                'user_id' => 2, 'email' => 'mod2@example.com', 'name' => 'Mod Two',
                'text_summary' => 'sum2', 'html_summary' => '<p>2</p>', 'total' => 1,
                'subject' => 'MODERATE: 2',
            ],
        ];

        $service = $this->createMock(ModNotifService::class);
        $service->method('getNotificationsToSend')->willReturn($notifications);
        // recordSent must fire only for the second mod (the first failed).
        $service->expects($this->once())->method('recordSent')->with(2, 'sum2');
        $this->app->instance(ModNotifService::class, $service);

        $calls = 0;
        $spooler = \Mockery::mock(EmailSpoolerService::class);
        $spooler->shouldReceive('spool')->andReturnUsing(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('simulated transient MJML render failure');
            }
            return 'spooled-id';
        });
        $this->app->instance(EmailSpoolerService::class, $spooler);

        $this->artisan('mail:mod-notifs', ['--force' => true])
            ->assertExitCode(0);

        $this->assertSame(2, $calls, 'Both mods should have been attempted');
    }
}
