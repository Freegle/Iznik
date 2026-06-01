<?php

namespace App\Jobs;

use App\Mail\Contracts\RetryableMailable;
use App\Services\EmailSpoolerService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Durable retry for a mail send that failed to render/build (or hit a
 * transient error) on the cron hot path.
 *
 * Dispatched by {@see EmailSpoolerService::spool()} when a
 * {@see RetryableMailable}'s build throws a non-permanent error. Carries only
 * the mailable class plus a scalar descriptor (IDs) — never a built object —
 * so {@see handle()} can rebuild a fresh mailable from current DB state and
 * re-render it. This means a fix deployed after a render bug drains the
 * backlog automatically on the next retry tick.
 *
 * Retry policy is Laravel-native: {@see retryUntil()} keeps retrying for 24h
 * with capped backoff, after which the job lands in `failed_jobs` (surfaced by
 * `monitor:email-health` and re-runnable via `mail:retry-failed`). The 24h
 * window comfortably outlasts a deploy-a-fix cycle; drops, not latency, are the
 * thing we are protecting against.
 *
 * Recursion guard: handle() calls spool() with autoRetry: false, so a
 * still-broken render throws back into the queue's own retry machinery rather
 * than dispatching another SpoolMail.
 */
class SpoolMail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Capped backoff (seconds) between attempts: 1m, 5m, then 10m thereafter.
     * The 10m cap means a fix deployed mid-window drains within ~10 minutes
     * rather than waiting an hour, while still not hammering the renderer
     * during an outage.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300, 600];

    /**
     * @param string $mailableClass Fully-qualified RetryableMailable class name.
     * @param array<string, mixed> $descriptor IDs needed to rebuild the mailable.
     * @param string|null $recipient Recipient address (for the spool + logging).
     * @param string|null $emailType The spool email-type tag (e.g. digest_immediate).
     */
    public function __construct(
        public string $mailableClass,
        public array $descriptor,
        public ?string $recipient = null,
        public ?string $emailType = null,
    ) {
    }

    /**
     * Keep retrying for 24h, then dead-letter to failed_jobs.
     */
    public function retryUntil(): Carbon
    {
        return now()->addHours(24);
    }

    public function handle(EmailSpoolerService $spooler): void
    {
        $class = $this->mailableClass;

        if (! is_a($class, RetryableMailable::class, true)) {
            // The class was renamed/removed since this job was enqueued; there
            // is nothing safe to rebuild. Don't fail (would just churn) — log
            // and drop.
            Log::warning('SpoolMail: mailable class is not retryable; dropping', [
                'class' => $class,
                'recipient' => $this->recipient,
                'email_type' => $this->emailType,
            ]);

            return;
        }

        /** @var class-string<RetryableMailable> $class */
        $mailable = $class::rebuildFromDescriptor($this->descriptor);

        if ($mailable === null) {
            // No longer applicable (message/user deleted, recipient now has no
            // address, etc). This is a cancellation, not a drop — succeed so
            // the job leaves the queue cleanly.
            Log::info('SpoolMail: descriptor no longer applicable; cancelling retry', [
                'class' => $class,
                'descriptor' => $this->descriptor,
            ]);

            return;
        }

        // autoRetry: false — if the render is still broken this throws, and the
        // queue's retryUntil/backoff/failed_jobs handle it. We must NOT
        // dispatch another SpoolMail from here.
        $spooler->spool($mailable, $this->recipient, $this->emailType, autoRetry: false);
    }

    public function failed(\Throwable $e): void
    {
        // Reached only after the 24h retry window is exhausted. The job is now
        // in failed_jobs; monitor:email-health alerts on the backlog and
        // mail:retry-failed can re-drive it once the underlying bug is fixed.
        Log::error('SpoolMail: retry window exhausted; email parked in failed_jobs', [
            'class' => $this->mailableClass,
            'recipient' => $this->recipient,
            'email_type' => $this->emailType,
            'descriptor' => $this->descriptor,
            'error' => $e->getMessage(),
        ]);
    }
}
