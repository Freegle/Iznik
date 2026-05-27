<?php

namespace App\Services;

use App\Models\UserEmail;
use App\Services\Mail\Incoming\BounceService;
use App\Services\Mail\SmtpFailureClassifier;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\TextPart;

/**
 * File-based email spooler service.
 *
 * Writes emails to a spool directory for asynchronous processing.
 * This provides resilience and backlog monitoring capabilities.
 *
 * DESIGN: Uses a "capturing transport" approach to ensure ALL headers survive spooling.
 * When spooling, we run the mailable through Laravel's complete mail pipeline but
 * intercept at the transport layer, capturing the fully-built Symfony Email with
 * all headers applied (including those from withSymfonyMessage callbacks).
 */
class EmailSpoolerService
{
    protected string $spoolDir;
    protected string $pendingDir;
    protected string $sendingDir;
    protected string $failedDir;
    protected string $sentDir;
    protected LokiService $lokiService;

    public function __construct(?LokiService $lokiService = null)
    {
        $this->spoolDir = storage_path('spool/mail');
        $this->pendingDir = $this->spoolDir . '/pending';
        $this->sendingDir = $this->spoolDir . '/sending';
        $this->failedDir = $this->spoolDir . '/failed';
        $this->sentDir = $this->spoolDir . '/sent';
        $this->lokiService = $lokiService ?? app(LokiService::class);

        $this->ensureDirectoriesExist();
    }

    /**
     * Spool an email for later sending.
     *
     * Uses a capturing transport to build the complete message through Laravel's
     * mail pipeline, ensuring all withSymfonyMessage callbacks execute and all
     * headers are captured.
     */
    public function spool(Mailable $mailable, string|array|null $to = null, ?string $emailType = null): string
    {
        $id = $this->generateId();
        $filename = $id . '.json';

        // If the caller didn't pass $to explicitly, derive it from the
        // mailable's own ->to (set via Mail::to()->send() pattern) or from
        // its envelope() (the Mail::send($mailable) pattern, where the
        // mailable carries its own recipient). This lets every send call in
        // the codebase be converted to spool() mechanically without each
        // caller having to know whether the mailable self-addresses.
        if ($to === null || $to === [] || $to === '') {
            $to = $this->deriveRecipientsFromMailable($mailable);
        }

        // Normalize $to to array format with address/name structure.
        $toArray = is_string($to) ? [$to] : $to;
        $normalizedTo = array_map(function ($addr) {
            return is_array($addr) ? $addr : ['address' => $addr, 'name' => ''];
        }, $toArray);

        // Ensure recipient is set on the mailable (required for pipeline to work).
        if (empty($mailable->to)) {
            $mailable->to($to);
        }

        // Build the complete message using a capturing transport.
        // This runs all withSymfonyMessage callbacks and captures the final message.
        //
        // A malformed/non-ASCII recipient (e.g. "kojopoku6.com" with no @) throws
        // a permanent address error here, while building the message — before it is
        // ever written to the spool. The send-time catch in processSpool() records
        // a bounce for these, but it never runs because the message never reaches
        // the spool, so the exception used to escape uncaught: it killed the queue
        // task and escalated to Sentry, and the bad address was never flagged as
        // bouncing. Mirror the send-time / SafeMail behaviour here: a permanent
        // failure marks the recipient bouncing and is skipped; anything else
        // (e.g. MJML/infra build failures) re-throws unchanged.
        try {
            $email = $this->captureBuiltMessage($mailable);
        } catch (\Throwable $e) {
            if (!$this->isPermanentSmtpFailure($e->getMessage())) {
                throw $e;
            }

            $recipient = $this->extractOffendingRecipient($e->getMessage(), $normalizedTo);
            if ($recipient !== null) {
                app(SmtpFailureClassifier::class)
                    ->recordPermanentBounce($recipient, $e->getMessage());
            }

            Log::warning('Skipped permanent-failure recipient while spooling and marked as bouncing', [
                'mailable' => get_class($mailable),
                'recipient' => $recipient,
                'to' => array_column($normalizedTo, 'address'),
                'type' => $emailType,
                'error' => $e->getMessage(),
            ]);

            return '';
        }

        // Extract all data from the captured message.
        // IMPORTANT: Use the $to parameter as authoritative recipient, not the captured email.
        // This allows callers (like mail:test --send-to) to override the delivery address.
        $data = [
            'id' => $id,
            'to' => $normalizedTo,
            'from' => $this->extractAddresses($email->getFrom()),
            'cc' => $this->extractAddresses($email->getCc()),
            'bcc' => $this->extractAddresses($email->getBcc()),
            'reply_to' => $this->extractAddresses($email->getReplyTo()),
            'subject' => $email->getSubject(),
            'html' => $email->getHtmlBody(),
            'text' => $email->getTextBody(),
            'amp_html' => $this->extractAmpContent($email),
            'headers' => $this->extractCustomHeaders($email),
            'email_type' => $emailType,
            'mailable_class' => get_class($mailable),
            'created_at' => now()->toIso8601String(),
            'attempts' => 0,
            'last_attempt' => null,
            'last_error' => null,
        ];

        // Generate plain text if not present.
        if (empty($data['text']) && !empty($data['html'])) {
            $data['text'] = $this->htmlToPlainText($data['html']);
        }

        // Write atomically: temp file in the same dir, then rename. The
        // processor's glob() picks up *.json in pendingDir on each tick, and
        // a direct file_put_contents() to the destination path is NOT atomic
        // — if the processor's glob/read interleaves with the writer, it
        // sees a partial file and json_decode() returns null, which we used
        // to log as "Invalid spool file" and move to failed/ (one such case
        // 14 May 23:16:54 UTC after a container restart raced the spooler).
        // rename() within the same filesystem IS atomic, so the destination
        // path is either absent or a complete file — never partial.
        $path = $this->pendingDir . '/' . $filename;
        $tmp  = $path . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT));
        rename($tmp, $path);

        Log::info('Email spooled', [
            'id' => $id,
            'to' => array_column($data['to'], 'address'),
            'subject' => $data['subject'],
            'type' => $emailType,
            'has_amp' => !empty($data['amp_html']),
            'headers' => array_keys($data['headers']),
            // Key fields for Loki correlation and support tools.
            'trace_id' => $data['headers']['X-Freegle-Trace-Id'] ?? null,
            'user_id' => $data['headers']['X-Freegle-User-Id'] ?? null,
            'email_type' => $data['headers']['X-Freegle-Email-Type'] ?? null,
        ]);

        return $id;
    }

    /**
     * Build the complete message using a capturing transport.
     *
     * This sends the mailable through Laravel's complete mail pipeline,
     * but intercepts at the transport layer to capture the fully-built
     * Symfony Email with all headers applied.
     */
    /**
     * Derive a recipient list from a self-addressing mailable.
     *
     * Looks first at the mailable's public ->to array (set via Mail::to() or
     * MyMailable::to()), then falls back to running the mailable through a
     * lightweight capturing transport to pick up envelope()-declared
     * recipients. Used when spool() is called without an explicit $to so
     * direct-send call sites (Mail::send($mailable) pattern) can be converted
     * mechanically.
     *
     * @return list<string>
     */
    protected function deriveRecipientsFromMailable(Mailable $mailable): array
    {
        if (!empty($mailable->to)) {
            return array_values(array_filter(array_map(
                fn ($entry) => is_array($entry) ? ($entry['address'] ?? null) : (string) $entry,
                $mailable->to,
            )));
        }

        // Fall back to envelope() recipients. We run the mailable through the
        // capturing transport (same one spool() uses to materialise the
        // Symfony Email) and read getTo() off the captured message. We DON'T
        // cache the captured email here — spool() captures it again right
        // after — because doing so would require restructuring the method to
        // pass the cached value through, for one rarely-hit code path.
        try {
            $email = $this->captureBuiltMessage($mailable);
            $addrs = $email->getTo() ?? [];
            return array_values(array_map(fn ($a) => $a->getAddress(), $addrs));
        } catch (\Throwable $e) {
            // captureBuiltMessage failing here will fail again inside spool()
            // and be handled by the existing isPermanentSmtpFailure branch,
            // so just return [] and let that path do its job.
            return [];
        }
    }

    protected function captureBuiltMessage(Mailable $mailable): Email
    {
        // Create a transport that captures instead of sending.
        $capturedEmail = null;

        $transport = new class($capturedEmail) extends AbstractTransport {
            private mixed $capturedRef;

            public function __construct(&$captured)
            {
                parent::__construct();
                $this->capturedRef = &$captured;
            }

            protected function doSend(SentMessage $message): void
            {
                $original = $message->getOriginalMessage();
                if ($original instanceof Email) {
                    $this->capturedRef = $original;
                }
            }

            public function __toString(): string
            {
                return 'capture://';
            }
        };

        // Create a mailer with our capturing transport.
        $mailer = new Mailer(
            'capture',
            app('view'),
            $transport,
            app('events')
        );

        // Send through the pipeline - this runs all callbacks and builds the complete message.
        $mailable->send($mailer);

        if (!$capturedEmail instanceof Email) {
            throw new \RuntimeException('Failed to capture message from mailable');
        }

        return $capturedEmail;
    }

    /**
     * Extract addresses from Symfony Address objects.
     */
    protected function extractAddresses(array $addresses): array
    {
        return array_map(fn(Address $addr) => [
            'address' => $addr->getAddress(),
            'name' => $addr->getName(),
        ], $addresses);
    }

    /**
     * Extract custom headers, excluding standard ones that will be regenerated.
     */
    protected function extractCustomHeaders(Email $email): array
    {
        $headers = [];
        $excludeHeaders = [
            // These are regenerated during send.
            'date', 'message-id', 'mime-version',
            'content-type', 'content-transfer-encoding',
            // These are set from the extracted address/subject fields.
            'to', 'from', 'cc', 'bcc', 'reply-to', 'subject',
        ];

        foreach ($email->getHeaders()->all() as $header) {
            $nameLower = strtolower($header->getName());
            if (!in_array($nameLower, $excludeHeaders)) {
                // Use original case for header name.
                $headers[$header->getName()] = $header->getBodyAsString();
            }
        }

        return $headers;
    }

    /**
     * Extract AMP HTML content if present in the message body.
     *
     * AMP content is stored as a text/x-amp-html part in a multipart/alternative body.
     */
    protected function extractAmpContent(Email $email): ?string
    {
        $body = $email->getBody();

        if ($body instanceof AlternativePart) {
            foreach ($body->getParts() as $part) {
                if ($part instanceof TextPart) {
                    // AMP content has subtype 'x-amp-html'.
                    if ($part->getMediaSubtype() === 'x-amp-html') {
                        return $part->getBody();
                    }
                }
            }
        }

        return null;
    }

    /**
     * Reclaim files orphaned in sending/ by a previous process that died
     * mid-send (restart/OOM/crash). Safe to call only when no send is in
     * flight — i.e. at daemon startup. The spooler runs single-process
     * (numprocs=1), so at startup nothing is being sent. Files go back to
     * pending/ for a normal retry; nothing is dead-lettered, so an extended
     * smarthost outage never loses mail.
     */
    public function reclaimOrphanedSending(): int
    {
        $reclaimed = 0;

        foreach (glob($this->sendingDir . '/*.json') as $sendingPath) {
            $filename = basename($sendingPath);
            if (rename($sendingPath, $this->pendingDir . '/' . $filename)) {
                $reclaimed++;
            } else {
                Log::warning('Could not reclaim orphaned spool file', ['file' => $filename]);
            }
        }

        if ($reclaimed > 0) {
            Log::warning('Reclaimed orphaned spool files from sending/ on startup', [
                'count' => $reclaimed,
            ]);
        }

        return $reclaimed;
    }

    /**
     * Process pending emails from the spool.
     *
     * Retries indefinitely until the email is sent. Logs an alert if any
     * email has been stuck for 5+ minutes, but only alerts once per email
     * to avoid log flooding.
     *
     * @param int $limit Maximum emails to process.
     * @return array Stats about processed emails.
     */
    public function processSpool(int $limit = 100): array
    {
        $stats = [
            'processed' => 0,
            'sent' => 0,
            'retried' => 0,
            'stuck_alerts' => 0,
        ];

        $files = glob($this->pendingDir . '/*.json');
        $files = array_slice($files, 0, $limit);

        foreach ($files as $pendingPath) {
            $filename = basename($pendingPath);
            $sendingPath = $this->sendingDir . '/' . $filename;

            // Move to sending directory.
            if (!rename($pendingPath, $sendingPath)) {
                Log::warning('Could not move spool file to sending', ['file' => $filename]);
                continue;
            }

            $stats['processed']++;

            $data = json_decode(file_get_contents($sendingPath), true);
            if (!$data) {
                Log::error('Invalid spool file', ['file' => $filename]);
                // Move invalid files to failed - these can't be retried.
                rename($sendingPath, $this->failedDir . '/' . $filename);
                continue;
            }

            $data['attempts']++;
            $data['last_attempt'] = now()->toIso8601String();

            try {
                // Build and send the email using a unified approach.
                // We always work directly with the Symfony Email object to ensure
                // consistent handling of envelope addresses and body, whether AMP or not.
                Mail::html($data['html'], function ($message) use ($data) {
                    $symfonyMessage = $message->getSymfonyMessage();

                    // Set envelope addresses directly on Symfony Email.
                    // This ensures consistent behavior for both AMP and non-AMP emails.
                    $this->applyRecipientsToSymfonyMessage($symfonyMessage, $data);

                    // Apply custom headers.
                    $this->applyCustomHeaders($symfonyMessage, $data);

                    // Build the body - either with AMP or standard multipart/alternative.
                    if (!empty($data['amp_html'])) {
                        // DEBUG: Uncomment to save AMP HTML for validation testing.
                        // $ampFile = '/tmp/amp-email-' . ($data['id'] ?? uniqid()) . '.html';
                        // file_put_contents($ampFile, $data['amp_html']);
                        // Log::debug('AMP HTML saved for validation', ['file' => $ampFile]);

                        // AMP emails need multipart/alternative with text, AMP, and HTML parts.
                        $textPart = new TextPart($data['text'] ?? '', 'utf-8', 'plain');
                        $ampPart = new TextPart($data['amp_html'], 'utf-8', 'x-amp-html');
                        $htmlPart = new TextPart($data['html'], 'utf-8', 'html');
                        $alternativePart = new AlternativePart($textPart, $ampPart, $htmlPart);
                        $symfonyMessage->setBody($alternativePart);
                    } elseif (!empty($data['text'])) {
                        // Non-AMP: Build multipart/alternative with text and HTML parts.
                        // This ensures the plain text body is included for email clients
                        // that prefer or require it (e.g., TrashNothing parsing).
                        $textPart = new TextPart($data['text'], 'utf-8', 'plain');
                        $htmlPart = new TextPart($data['html'], 'utf-8', 'html');
                        $alternativePart = new AlternativePart($textPart, $htmlPart);
                        $symfonyMessage->setBody($alternativePart);
                    }
                    // If no text body, Mail::html() has already set HTML-only body.
                });

                // Move to sent directory.
                rename($sendingPath, $this->sentDir . '/' . $filename);
                $stats['sent']++;

                // Extract tracking data from headers.
                $traceId = $data['headers']['X-Freegle-Trace-Id'] ?? null;
                $userId = isset($data['headers']['X-Freegle-User-Id'])
                    ? (int) $data['headers']['X-Freegle-User-Id']
                    : null;
                $emailType = $data['headers']['X-Freegle-Email-Type'] ?? $data['email_type'] ?? 'unknown';
                $groupId = isset($data['headers']['X-Freegle-Group-Id'])
                    ? (int) $data['headers']['X-Freegle-Group-Id']
                    : null;

                // Log to Loki for support tools dashboards.
                $this->lokiService->logEmailSend(
                    $emailType,
                    $data['to'][0]['address'] ?? '',
                    $data['subject'] ?? '',
                    $userId,
                    $groupId,
                    $traceId,
                    [
                        'spool_id' => $data['id'],
                        'attempts' => $data['attempts'],
                        'mailable_class' => $data['mailable_class'] ?? null,
                        'has_amp' => !empty($data['amp_html']),
                    ]
                );

                Log::info('Spooled email sent', [
                    'id' => $data['id'],
                    'to' => array_column($data['to'], 'address'),
                    'attempts' => $data['attempts'],
                    // Key fields for Loki correlation and support tools.
                    'trace_id' => $traceId,
                    'user_id' => $userId,
                    'email_type' => $emailType,
                ]);
            } catch (\Exception $e) {
                $data['last_error'] = $e->getMessage();

                // Check if this is a permanent SMTP failure that will never succeed.
                if ($this->isPermanentSmtpFailure($e->getMessage())) {
                    // Record as a bounce so the user gets flagged as bouncing.
                    $this->recordSmtpBounce($data, $e->getMessage());

                    // Move to failed - no point retrying.
                    file_put_contents($sendingPath, json_encode($data, JSON_PRETTY_PRINT));
                    rename($sendingPath, $this->failedDir . '/' . $filename);
                    $stats['bounced'] = ($stats['bounced'] ?? 0) + 1;

                    Log::info('Email permanently failed - recorded as bounce', [
                        'id' => $data['id'],
                        'to' => array_column($data['to'], 'address'),
                        'error' => $e->getMessage(),
                        'attempts' => $data['attempts'],
                    ]);

                    continue;
                }

                // Transient failure - check if stuck.
                $createdAt = \Carbon\Carbon::parse($data['created_at']);
                $ageMinutes = now()->diffInMinutes($createdAt);
                $lastAlertedAt = isset($data['last_alerted_at'])
                    ? \Carbon\Carbon::parse($data['last_alerted_at'])
                    : null;

                // Alert if stuck for 5+ mins and haven't alerted in the last 5 mins.
                if ($ageMinutes >= 5) {
                    $shouldAlert = $lastAlertedAt === null
                        || now()->diffInMinutes($lastAlertedAt) >= 5;

                    if ($shouldAlert) {
                        $data['last_alerted_at'] = now()->toIso8601String();
                        $stats['stuck_alerts']++;

                        Log::error('Email stuck in spool for 5+ minutes - SMTP delivery issue', [
                            'id' => $data['id'],
                            'to' => array_column($data['to'], 'address'),
                            'age_minutes' => $ageMinutes,
                            'attempts' => $data['attempts'],
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Move back to pending for retry.
                file_put_contents($sendingPath, json_encode($data, JSON_PRETTY_PRINT));
                rename($sendingPath, $pendingPath);
                $stats['retried']++;
            }
        }

        return $stats;
    }

    /**
     * Get backlog statistics.
     */
    public function getBacklogStats(): array
    {
        $pendingFiles = glob($this->pendingDir . '/*.json');
        $sendingFiles = glob($this->sendingDir . '/*.json');
        $failedFiles = glob($this->failedDir . '/*.json');

        $oldestPending = null;
        $oldestAge = null;

        foreach ($pendingFiles as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && isset($data['created_at'])) {
                $created = \Carbon\Carbon::parse($data['created_at']);
                if ($oldestPending === null || $created < $oldestPending) {
                    $oldestPending = $created;
                }
            }
        }

        if ($oldestPending) {
            $oldestAge = now()->diffInMinutes($oldestPending);
        }

        return [
            'pending_count' => count($pendingFiles),
            'sending_count' => count($sendingFiles),
            'failed_count' => count($failedFiles),
            'oldest_pending_at' => $oldestPending?->toIso8601String(),
            'oldest_pending_age_minutes' => $oldestAge,
            'status' => $this->getHealthStatus(count($pendingFiles), $oldestAge),
        ];
    }

    /**
     * Clean up old sent emails.
     *
     * Uses DirectoryIterator instead of glob() to handle directories with
     * tens of thousands of files without loading all filenames into memory.
     *
     * @param int $daysToKeep Number of days to keep sent emails.
     * @return int Number of files deleted.
     */
    public function cleanupSent(int $daysToKeep = 7): int
    {
        $deleted = 0;
        $cutoff = now()->subDays($daysToKeep)->timestamp;

        if (!is_dir($this->sentDir)) {
            return 0;
        }

        $iterator = new \DirectoryIterator($this->sentDir);

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDot() || $fileInfo->getExtension() !== 'json') {
                continue;
            }

            if ($fileInfo->getMTime() < $cutoff) {
                @unlink($fileInfo->getPathname());
                $deleted++;
            }
        }

        if ($deleted > 0) {
            Log::info('Cleaned up old sent emails', ['deleted' => $deleted, 'days_kept' => $daysToKeep]);
        }

        return $deleted;
    }

    /**
     * Retry a failed email.
     */
    public function retryFailed(string $id): bool
    {
        $filename = $id . '.json';
        $failedPath = $this->failedDir . '/' . $filename;

        if (!file_exists($failedPath)) {
            return false;
        }

        $data = json_decode(file_get_contents($failedPath), true);
        if ($data) {
            $data['attempts'] = 0;
            $data['last_error'] = null;
            file_put_contents($failedPath, json_encode($data, JSON_PRETTY_PRINT));
        }

        return rename($failedPath, $this->pendingDir . '/' . $filename);
    }

    /**
     * Retry all failed emails.
     */
    public function retryAllFailed(): int
    {
        $count = 0;
        foreach (glob($this->failedDir . '/*.json') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data) {
                $data['attempts'] = 0;
                $data['last_error'] = null;
                file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
                rename($file, $this->pendingDir . '/' . basename($file));
                $count++;
            }
        }
        return $count;
    }

    /**
     * Determine if an SMTP error is permanent (will never succeed on retry).
     *
     * Delegates to SmtpFailureClassifier so direct-send paths (mail:mod-notifs,
     * mail:engage, mail:donations:*) classify failures consistently with the
     * spool processor.
     */
    protected function isPermanentSmtpFailure(string $errorMessage): bool
    {
        return app(SmtpFailureClassifier::class)->isPermanent($errorMessage);
    }

    /**
     * Record an SMTP-time bounce via the BounceService. Delegates to
     * SmtpFailureClassifier for the actual record-and-suspend logic so
     * direct-send paths share the same implementation.
     */
    protected function recordSmtpBounce(array $data, string $errorMessage): void
    {
        $recipientEmail = $data['to'][0]['address'] ?? null;
        if ($recipientEmail === null) {
            return;
        }

        $traceId = $data['headers']['X-Freegle-Trace-Id'] ?? null;
        app(SmtpFailureClassifier::class)
            ->recordPermanentBounce($recipientEmail, $errorMessage, $traceId);
    }

    /**
     * Pick which recipient to flag as bouncing after a permanent build failure.
     *
     * Symfony's RfcComplianceException names the offending address in its
     * message (e.g. Email "x" does not comply with addr-spec of RFC 2822).
     * Prefer that exact address so one bad recipient in a multi-recipient send
     * doesn't wrongly flag a valid co-recipient; fall back to the first
     * recipient when the message doesn't name one.
     */
    protected function extractOffendingRecipient(string $errorMessage, array $normalizedTo): ?string
    {
        if (preg_match('/Email "([^"]+)"/', $errorMessage, $m)) {
            return $m[1];
        }

        return $normalizedTo[0]['address'] ?? null;
    }

    protected function generateId(): string
    {
        return uniqid('mail_', true) . '_' . bin2hex(random_bytes(4));
    }

    protected function ensureDirectoriesExist(): void
    {
        foreach ([$this->pendingDir, $this->sendingDir, $this->failedDir, $this->sentDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    protected function getHealthStatus(int $pendingCount, ?int $oldestAgeMinutes): string
    {
        if ($pendingCount === 0) {
            return 'healthy';
        }

        if ($pendingCount > 1000 || ($oldestAgeMinutes !== null && $oldestAgeMinutes > 60)) {
            return 'critical';
        }

        if ($pendingCount > 100 || ($oldestAgeMinutes !== null && $oldestAgeMinutes > 15)) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * Convert HTML to plain text for email fallback.
     */
    protected function htmlToPlainText(string $html): string
    {
        // Remove style and script tags and their content.
        $text = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $text = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $text);

        // Convert <br> and </p> to newlines.
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/p>/i', "\n\n", $text);
        $text = preg_replace('/<\/div>/i', "\n", $text);
        $text = preg_replace('/<\/tr>/i', "\n", $text);
        $text = preg_replace('/<\/li>/i', "\n", $text);

        // Convert links to text with URL.
        $text = preg_replace('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>([^<]+)<\/a>/i', '$2 ($1)', $text);

        // Remove remaining HTML tags.
        $text = strip_tags($text);

        // Decode HTML entities.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace.
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Apply recipients directly to the Symfony Email object.
     *
     * This is needed when manipulating the Symfony message directly (e.g., for AMP)
     * because Laravel's Message wrapper may not sync recipients to the underlying
     * Symfony Email until after the callback completes.
     */
    protected function applyRecipientsToSymfonyMessage(Email $symfonyMessage, array $data): void
    {
        // Set To addresses.
        if (!empty($data['to'])) {
            $toAddresses = array_map(
                fn($addr) => new Address($addr['address'], $addr['name'] ?? ''),
                $data['to']
            );
            $symfonyMessage->to(...$toAddresses);
        }

        // Set From address.
        if (!empty($data['from'])) {
            $from = $data['from'][0] ?? $data['from'];
            if (is_array($from)) {
                $symfonyMessage->from(new Address($from['address'], $from['name'] ?? ''));
            }
        }

        // Set CC addresses.
        if (!empty($data['cc'])) {
            $ccAddresses = array_map(
                fn($addr) => new Address($addr['address'], $addr['name'] ?? ''),
                $data['cc']
            );
            $symfonyMessage->cc(...$ccAddresses);
        }

        // Set BCC addresses.
        if (!empty($data['bcc'])) {
            $bccAddresses = array_map(
                fn($addr) => new Address($addr['address'], $addr['name'] ?? ''),
                $data['bcc']
            );
            $symfonyMessage->bcc(...$bccAddresses);
        }

        // Set Reply-To addresses.
        if (!empty($data['reply_to'])) {
            $replyToAddresses = array_map(
                fn($addr) => new Address($addr['address'], $addr['name'] ?? ''),
                $data['reply_to']
            );
            $symfonyMessage->replyTo(...$replyToAddresses);
        }

        // Set subject.
        if (!empty($data['subject'])) {
            $symfonyMessage->subject($data['subject']);
        }
    }

    /**
     * Apply custom headers to the Symfony Email object.
     */
    protected function applyCustomHeaders(Email $symfonyMessage, array $data): void
    {
        if (empty($data['headers'])) {
            return;
        }

        $headers = $symfonyMessage->getHeaders();
        foreach ($data['headers'] as $name => $value) {
            $headers->addTextHeader($name, $value);
        }
    }
}
