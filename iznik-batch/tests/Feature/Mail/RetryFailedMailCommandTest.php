<?php

namespace Tests\Feature\Mail;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers mail:retry-failed, which re-queues parked SpoolMail jobs from
 * failed_jobs (used after deploying a render-bug fix). It must touch only
 * SpoolMail jobs and leave any other failed jobs alone.
 */
class RetryFailedMailCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('failed_jobs')->delete();
        DB::table('queue_jobs')->delete();
    }

    private function seedFailedJob(string $displayName): string
    {
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => $displayName,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => ['commandName' => $displayName],
                'attempts' => 1,
            ]),
            'exception' => 'simulated failure',
            'failed_at' => now(),
        ]);

        return $uuid;
    }

    public function test_reports_nothing_to_do_when_no_failed_mail_jobs(): void
    {
        $this->seedFailedJob('App\\Jobs\\SomeOtherJob');

        $this->artisan('mail:retry-failed')
            ->expectsOutputToContain('No failed SpoolMail jobs to retry.')
            ->assertExitCode(0);

        // The unrelated failed job is untouched.
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_requeues_only_spoolmail_failed_jobs(): void
    {
        $mailUuid = $this->seedFailedJob('App\\Jobs\\SpoolMail');
        $otherUuid = $this->seedFailedJob('App\\Jobs\\SomeOtherJob');

        $this->artisan('mail:retry-failed')
            ->expectsOutputToContain('Re-queueing 1 failed SpoolMail job(s)')
            ->assertExitCode(0);

        // SpoolMail job pulled out of failed_jobs and re-queued; the other stays.
        $this->assertNull(DB::table('failed_jobs')->where('uuid', $mailUuid)->first());
        $this->assertNotNull(DB::table('failed_jobs')->where('uuid', $otherUuid)->first());
        $this->assertGreaterThan(0, DB::table('queue_jobs')->count());
    }
}
