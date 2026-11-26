<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SpoolMail;
use App\Services\EmailSpoolerService;
use App\Services\LokiService;
use Illuminate\Mail\Mailable;
use Tests\Support\RetryableTestMailable;
use Tests\TestCase;

class SpoolMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RetryableTestMailable::reset();
    }

    protected function tearDown(): void
    {
        RetryableTestMailable::reset();
        parent::tearDown();
    }

    /**
     * A capturing spooler that records the arguments handle() passes to spool().
     */
    private function capturingSpooler(?\Throwable $throw = null): EmailSpoolerService
    {
        return new class ($throw) extends EmailSpoolerService {
            public array $calls = [];

            public function __construct(private ?\Throwable $throw)
            {
                parent::__construct(app(LokiService::class));
            }

            public function spool(Mailable $mailable, $to = null, ?string $emailType = null, bool $autoRetry = true): string
            {
                $this->calls[] = [
                    'mailable' => $mailable,
                    'to' => $to,
                    'emailType' => $emailType,
                    'autoRetry' => $autoRetry,
                ];

                if ($this->throw) {
                    throw $this->throw;
                }

                return 'captured-id';
            }
        };
    }

    public function test_handle_rebuilds_and_spools_with_autoretry_disabled(): void
    {
        $spooler = $this->capturingSpooler();

        $job = new SpoolMail(
            RetryableTestMailable::class,
            ['id' => 99, 'recipient' => 'job@example.com'],
            'job@example.com',
            'digest_immediate',
        );

        $job->handle($spooler);

        $this->assertCount(1, $spooler->calls);
        $call = $spooler->calls[0];
        $this->assertInstanceOf(RetryableTestMailable::class, $call['mailable']);
        $this->assertSame('job@example.com', $call['to']);
        $this->assertSame('digest_immediate', $call['emailType']);
        $this->assertFalse($call['autoRetry'], 'Retry job must call spool() with autoRetry disabled.');
        $this->assertSame(['id' => 99, 'recipient' => 'job@example.com'], RetryableTestMailable::$lastRebuildDescriptor);
    }

    public function test_handle_cancels_quietly_when_descriptor_no_longer_applicable(): void
    {
        RetryableTestMailable::$rebuildResult = 'null';
        $spooler = $this->capturingSpooler();

        $job = new SpoolMail(RetryableTestMailable::class, ['id' => 1], 'job@example.com', 'chat');

        $job->handle($spooler);

        $this->assertCount(0, $spooler->calls, 'A null rebuild is a cancellation, not a send.');
    }

    public function test_handle_propagates_spool_failure_so_queue_retries(): void
    {
        $spooler = $this->capturingSpooler(new \RuntimeException('still broken'));

        $job = new SpoolMail(RetryableTestMailable::class, ['id' => 1], 'job@example.com', 'chat');

        $this->expectException(\RuntimeException::class);
        $job->handle($spooler);
    }

    public function test_handle_drops_unknown_class_without_throwing(): void
    {
        $spooler = $this->capturingSpooler();

        $job = new SpoolMail(\App\Models\User::class, ['id' => 1], 'job@example.com', 'chat');

        // Not a RetryableMailable → log + return, never call spool, never throw.
        $job->handle($spooler);

        $this->assertCount(0, $spooler->calls);
    }

    public function test_retry_until_is_about_24_hours_out(): void
    {
        $job = new SpoolMail(RetryableTestMailable::class, ['id' => 1]);

        $until = $job->retryUntil();

        $this->assertEqualsWithDelta(
            now()->addHours(24)->timestamp,
            $until->timestamp,
            60,
            'Retry window should be ~24h to outlast a deploy-a-fix cycle.'
        );
    }
}
