<?php

namespace Tests\Feature\Monitor;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Covers the 24/7 mail-retry dead-letter check added to monitor:email-health:
 * SpoolMail jobs parked in failed_jobs should raise an alert at any hour.
 *
 * Run at night (03:00) and seed one outgoing email so the stall check passes
 * and the daytime-only checks are skipped — that isolates the dead-letter
 * check as the only thing that can fail.
 */
class EmailHealthDeadLetterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('email_tracking')->delete();
        DB::table('failed_jobs')->delete();

        // Night-time so only the stall + dead-letter checks run.
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 3, 0, 0));

        // One recent outgoing email so the stall check passes.
        DB::table('email_tracking')->insert([
            'tracking_id' => bin2hex(random_bytes(8)),
            'email_type' => 'ChatNotification',
            'recipient_email' => 'recipient_' . uniqid('', true) . '@test.com',
            'sent_at' => Carbon::now()->subMinutes(5),
            'created_at' => Carbon::now()->subMinutes(5),
            'updated_at' => Carbon::now()->subMinutes(5),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedFailedSpoolMailJob(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\SpoolMail',
                'data' => ['commandName' => 'App\\Jobs\\SpoolMail'],
            ]),
            'exception' => 'simulated render failure',
            'failed_at' => Carbon::now(),
        ]);
    }

    public function test_failed_spoolmail_job_triggers_dead_letter_alert(): void
    {
        $this->seedFailedSpoolMailJob();

        Log::spy();

        $this->artisan('monitor:email-health')
            ->expectsOutputToContain('Mail-retry dead-letter: 1 failed SpoolMail job(s)')
            ->assertExitCode(1);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) =>
                str_contains((string) $message, 'MAIL RETRY DEAD-LETTER'));
    }

    public function test_no_failed_jobs_does_not_trigger_dead_letter(): void
    {
        $this->artisan('monitor:email-health')
            ->expectsOutputToContain('Mail-retry dead-letter: 0 failed SpoolMail job(s)')
            ->assertExitCode(0);
    }
}
