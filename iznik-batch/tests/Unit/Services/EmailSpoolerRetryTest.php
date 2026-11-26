<?php

namespace Tests\Unit\Services;

use App\Jobs\SpoolMail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\IsolatedSpoolDirectory;
use Tests\Support\RetryableTestMailable;
use Tests\Support\ThrowingTestMailable;
use Tests\TestCase;

/**
 * Covers the durable-retry hook added to EmailSpoolerService::spool().
 *
 * Uses the REAL spooler (via IsolatedSpoolDirectory) rather than the
 * Mail::send() test double, so the actual capture/build/catch path runs.
 */
class EmailSpoolerRetryTest extends TestCase
{
    use IsolatedSpoolDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedSpoolDirectory();
        RetryableTestMailable::reset();
    }

    protected function tearDown(): void
    {
        RetryableTestMailable::reset();
        $this->tearDownIsolatedSpoolDirectory();
        parent::tearDown();
    }

    public function test_render_failure_of_retryable_mailable_dispatches_retry_job_and_does_not_throw(): void
    {
        Queue::fake();
        RetryableTestMailable::$throwOnBuild = true;

        $mailable = new RetryableTestMailable(['id' => 42, 'recipient' => 'spool@example.com']);

        // Must NOT throw — the drop is converted into a durable retry.
        $result = $this->spooler->spool($mailable, 'spool@example.com', 'test_type');

        $this->assertSame('', $result, 'A retried render failure spools nothing inline.');

        Queue::assertPushed(SpoolMail::class, function (SpoolMail $job) {
            return $job->mailableClass === RetryableTestMailable::class
                && $job->descriptor === ['id' => 42, 'recipient' => 'spool@example.com']
                && $job->recipient === 'spool@example.com'
                && $job->emailType === 'test_type';
        });

        // Nothing should have been written to the pending spool.
        $this->assertFileDoesNotExist($this->testSpoolDir . '/pending/test-spooled-id.json');
    }

    public function test_render_failure_with_autoretry_disabled_rethrows_and_dispatches_nothing(): void
    {
        Queue::fake();
        RetryableTestMailable::$throwOnBuild = true;

        $mailable = new RetryableTestMailable(['id' => 7]);

        $this->expectException(\RuntimeException::class);

        try {
            $this->spooler->spool($mailable, 'spool@example.com', 'test_type', autoRetry: false);
        } finally {
            // The retry-job path must be bypassed when autoRetry is off, so the
            // queue stays empty even though we rethrew.
            Queue::assertNothingPushed();
        }
    }

    public function test_non_retryable_mailable_still_rethrows_on_render_failure(): void
    {
        Queue::fake();

        $mailable = new ThrowingTestMailable();

        $this->expectException(\RuntimeException::class);

        try {
            $this->spooler->spool($mailable, 'spool@example.com', 'test_type');
        } finally {
            Queue::assertNothingPushed();
        }
    }

    public function test_successful_retryable_mailable_spools_normally_without_dispatching(): void
    {
        Queue::fake();
        RetryableTestMailable::$throwOnBuild = false;

        $mailable = new RetryableTestMailable(['id' => 1], 'spool@example.com');

        $id = $this->spooler->spool($mailable, 'spool@example.com', 'test_type');

        $this->assertNotEmpty($id);
        $this->assertFileExists($this->testSpoolDir . '/pending/' . $id . '.json');
        Queue::assertNothingPushed();
    }
}
