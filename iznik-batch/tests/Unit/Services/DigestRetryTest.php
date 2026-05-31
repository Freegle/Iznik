<?php

namespace Tests\Unit\Services;

use App\Services\EmailSpoolerService;
use App\Services\UnifiedDigestService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the auto-retry safety net for immediate digests: a send that fails to
 * build/render is queued per-recipient (digest_retries) and drained by
 * mail:digest:retry with backoff + max-attempts give-up, instead of being
 * silently dropped when the per-group cursor advances. See the gomaaspromo /
 * NewhamFreegle heroImageUrl deploy-window incident (2026-05-31).
 */
class DigestRetryTest extends TestCase
{
    protected UnifiedDigestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UnifiedDigestService();
        DB::table('digest_retries')->truncate();
    }

    private function enqueue(int $u, int $m, int $g, string $err): void
    {
        $ref = new \ReflectionMethod(UnifiedDigestService::class, 'enqueueImmediateRetry');
        $ref->setAccessible(true);
        $ref->invoke($this->service, $u, $m, $g, $err);
    }

    private function seedRow(array $overrides = []): void
    {
        DB::table('digest_retries')->insert(array_merge([
            'userid' => 1,
            'msgid' => 2,
            'groupid' => 3,
            'emailtype' => 'digest_immediate',
            'attempts' => 1,
            'nextattempt' => now()->subMinute(),
            'created' => now(),
        ], $overrides));
    }

    // ---- resendImmediateForUser status branches ----

    public function test_resend_returns_gone_for_missing_message(): void
    {
        $user = $this->createTestUser();
        $this->assertSame('gone', $this->service->resendImmediateForUser($user->id, 999999999, 1));
    }

    public function test_resend_returns_own_for_posters_own_message(): void
    {
        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($poster, $group);

        $this->assertSame('own', $this->service->resendImmediateForUser($poster->id, $message->id, $group->id));
    }

    public function test_resend_spools_for_valid_recipient(): void
    {
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($poster, $group);

        $spooler = $this->mock(EmailSpoolerService::class);
        $spooler->shouldReceive('spool')->once();

        $this->assertSame('sent', $this->service->resendImmediateForUser($recipient->id, $message->id, $group->id));
    }

    public function test_resend_dry_run_does_not_spool(): void
    {
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($poster, $group);

        $spooler = $this->mock(EmailSpoolerService::class);
        $spooler->shouldReceive('spool')->never();

        $this->assertSame('sent', $this->service->resendImmediateForUser($recipient->id, $message->id, $group->id, true));
    }

    // ---- enqueueImmediateRetry upsert ----

    public function test_enqueue_inserts_then_upserts_without_duplicating(): void
    {
        $this->enqueue(10, 20, 30, 'first error');
        $this->enqueue(10, 20, 30, 'second error');

        $rows = DB::table('digest_retries')->where(['userid' => 10, 'msgid' => 20])->get();
        $this->assertCount(1, $rows, 'Repeated failures should upsert, not duplicate');
        $this->assertSame(2, (int) $rows->first()->attempts);
        $this->assertSame('second error', $rows->first()->lasterror);
    }

    // ---- command queue management ----

    public function test_command_deletes_row_on_successful_resend(): void
    {
        $this->seedRow();

        $mock = $this->mock(UnifiedDigestService::class);
        $mock->shouldReceive('resendImmediateForUser')->once()->andReturn('sent');

        $this->artisan('mail:digest:retry')->assertExitCode(0);

        $this->assertDatabaseMissing('digest_retries', ['userid' => 1, 'msgid' => 2]);
    }

    public function test_command_skips_rows_not_yet_due(): void
    {
        $this->seedRow(['nextattempt' => now()->addHour()]);

        $mock = $this->mock(UnifiedDigestService::class);
        $mock->shouldReceive('resendImmediateForUser')->never();

        $this->artisan('mail:digest:retry')->assertExitCode(0);

        $this->assertDatabaseHas('digest_retries', ['userid' => 1, 'msgid' => 2]);
    }

    public function test_command_backs_off_on_failure_below_max(): void
    {
        $this->seedRow(['attempts' => 1]);

        $mock = $this->mock(UnifiedDigestService::class);
        $mock->shouldReceive('resendImmediateForUser')->once()->andThrow(new \RuntimeException('still broken'));

        $this->artisan('mail:digest:retry')->assertExitCode(0);

        $row = DB::table('digest_retries')->where(['userid' => 1, 'msgid' => 2])->first();
        $this->assertNotNull($row, 'Row should remain for another attempt');
        $this->assertSame(2, (int) $row->attempts);
        $this->assertTrue(Carbon::parse($row->nextattempt)->greaterThan(now()), 'nextattempt should be pushed into the future');
    }

    public function test_command_gives_up_after_max_attempts(): void
    {
        $this->seedRow(['attempts' => UnifiedDigestService::RETRY_MAX_ATTEMPTS - 1]);

        $mock = $this->mock(UnifiedDigestService::class);
        $mock->shouldReceive('resendImmediateForUser')->once()->andThrow(new \RuntimeException('permanently broken'));

        $this->artisan('mail:digest:retry')->assertExitCode(0);

        $this->assertDatabaseMissing('digest_retries', ['userid' => 1, 'msgid' => 2]);
    }
}
