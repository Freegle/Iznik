<?php

namespace Tests\Feature\Monitor;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Tests for monitor:email-health.
 *
 * The command runs 24/7. A hard outgoing-STALL check (zero emails in the stall
 * window) runs at all hours so a night-time smarthost/spooler stall escalates
 * immediately; the incoming check and the outgoing volume floor stay gated to
 * daytime so merely-low night-time volume doesn't false-alarm.
 *
 * We do NOT assert on the actual \Sentry\captureMessage call (it's a global
 * function that isn't loaded under test, and the command guards it with
 * function_exists). Instead we assert on the Log::warning lines and the
 * command exit code, which fully capture the failure behaviour.
 */
class EmailHealthCommandTest extends TestCase
{
    private int $incomingChatId;
    private int $incomingUserId;

    protected function setUp(): void
    {
        parent::setUp();

        // DatabaseTransactions rolls these back. Clear so "0 in window"
        // assertions are deterministic regardless of pre-existing test data.
        DB::table('email_tracking')->delete();
        DB::table('chat_messages')->delete();

        // Create parent rows needed by seedIncoming().
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);
        $this->incomingChatId = $room->id;
        $this->incomingUserId = $user1->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Insert an email_tracking row sent $minutesAgo minutes before "now".
     */
    private function seedOutgoing(int $minutesAgo): void
    {
        DB::table('email_tracking')->insert([
            'tracking_id' => bin2hex(random_bytes(8)),
            'email_type' => 'ChatNotification',
            'recipient_email' => 'recipient_' . uniqid('', true) . '@test.com',
            'sent_at' => Carbon::now()->subMinutes($minutesAgo),
            'created_at' => Carbon::now()->subMinutes($minutesAgo),
            'updated_at' => Carbon::now()->subMinutes($minutesAgo),
        ]);
    }

    /**
     * Insert an incoming (platform=0) chat message $minutesAgo before "now".
     */
    private function seedIncoming(int $minutesAgo): void
    {
        DB::table('chat_messages')->insert([
            'chatid' => $this->incomingChatId,
            'userid' => $this->incomingUserId,
            'type' => 'Default',
            'message' => 'Test incoming',
            'platform' => 0,
            'date' => Carbon::now()->subMinutes($minutesAgo),
        ]);
    }

    public function test_outgoing_stall_at_night_fails_and_logs_warning(): void
    {
        // 03:00 — outside the daytime window. No outgoing emails at all.
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 3, 0, 0));

        Log::spy();

        $this->artisan('monitor:email-health')
            ->expectsOutputToContain('Outside daytime window')
            ->assertExitCode(1);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) =>
                str_contains((string) $message, 'OUTGOING STALL')
            );
    }

    public function test_healthy_outgoing_at_night_succeeds(): void
    {
        // 03:00 — at night, only the stall check runs. One recent email means
        // the stall window is non-empty, so the command should succeed even
        // though night-time volume is far below the daytime floor.
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 3, 0, 0));

        $this->seedOutgoing(5);

        Log::spy();

        $this->artisan('monitor:email-health')
            ->expectsOutputToContain('Outside daytime window')
            ->expectsOutputToContain('Email health OK.')
            ->assertExitCode(0);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_daytime_low_volume_floor_still_fails(): void
    {
        // Noon — inside the daytime window. Threshold of 10/hour, but only a
        // few emails were sent. The stall check passes (window is non-empty)
        // and incoming is present, so the only failure is the volume floor.
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 12, 0, 0));

        config(['freegle.email_health.outgoing_min_per_hour' => 10]);

        // 3 outgoing in the last hour — above zero (stall passes) but below 10.
        $this->seedOutgoing(5);
        $this->seedOutgoing(10);
        $this->seedOutgoing(15);

        // Incoming present so the incoming check doesn't also fail.
        $this->seedIncoming(30);

        Log::spy();

        $this->artisan('monitor:email-health')
            ->assertExitCode(1);

        // The volume-floor failure must be logged.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) =>
                str_contains((string) $message, 'OUTGOING:')
                && str_contains((string) $message, 'threshold: 10')
            );
    }

    public function test_daytime_all_healthy_succeeds(): void
    {
        // Noon, low threshold, plenty of outgoing and incoming → success.
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 12, 0, 0));

        config(['freegle.email_health.outgoing_min_per_hour' => 2]);

        $this->seedOutgoing(5);
        $this->seedOutgoing(10);
        $this->seedOutgoing(15);
        $this->seedIncoming(30);

        Log::spy();

        $this->artisan('monitor:email-health')
            ->expectsOutputToContain('Email health OK.')
            ->assertExitCode(0);

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Seed a healthy baseline (daytime, plenty of outgoing + incoming) so the
     * only signal under test is the AMP-config check.
     */
    private function seedHealthyBaseline(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 12, 0, 0));
        config(['freegle.email_health.outgoing_min_per_hour' => 2]);
        $this->seedOutgoing(5);
        $this->seedOutgoing(10);
        $this->seedOutgoing(15);
        $this->seedIncoming(30);
    }

    public function test_amp_enabled_without_secret_warns_but_does_not_fail(): void
    {
        // AMP is enabled but the secret is unset: every email ships without AMP
        // and the AMP stats read zero. This must be surfaced as a warning, but
        // it is NOT an outage, so the command still succeeds.
        $this->seedHealthyBaseline();
        config(['freegle.amp.enabled' => true, 'freegle.amp.secret' => '']);

        Log::spy();

        $this->artisan('monitor:email-health')
            ->expectsOutputToContain('AMP CONFIG')
            ->assertExitCode(0);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) =>
                str_contains((string) $message, 'AMP CONFIG')
                && str_contains((string) $message, 'amp.secret is empty')
            );
    }

    public function test_amp_configured_reports_ok_and_no_warning(): void
    {
        // AMP enabled with a secret present: no AMP-config warning.
        $this->seedHealthyBaseline();
        config(['freegle.amp.enabled' => true, 'freegle.amp.secret' => 'a-real-secret']);

        Log::spy();

        $this->artisan('monitor:email-health')
            ->expectsOutputToContain('AMP config OK.')
            ->assertExitCode(0);

        Log::shouldNotHaveReceived('warning');
    }
}
