<?php

namespace App\Services;

use App\Jobs\SpoolMail;
use App\Mail\Contracts\RetryableMailable;
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

    /**
     * Local record of messages SMTP has accepted but which have not yet been
     * filed into sent/. Deliberately a plain directory on local disk and NOT the
     * database: the case this exists for is the database being unavailable, so a
     * guard that needs the database would be absent exactly when it is needed.
     */
    protected string $ledgerDir;
    protected LokiService $lokiService;

    public function __construct(?LokiService $lokiService = null)
    {
        $this->spoolDir = storage_path('spool/mail');
        $this->pendingDir = $this->spoolDir . '/pending';
        $this->sendingDir = $this->spoolDir . '/sending';
        $this->failedDir = $this->spoolDir . '/failed';
        $this->sentDir = $this->spoolDir . '/sent';
        $this->ledgerDir = $this->spoolDir . '/ledger';
        $this->lokiService = $lokiService ?? app(LokiService::class);

        $this->ensureDirectoriesExist();
    }

    /**
     * Give this process a PRIVATE claim area, so several spool daemons can run
     * at once without reclaiming each other's in-flight mail.
     *
     * Claims move pending/ -> sending/. With one shared sending/ dir, a worker
     * restarting would run reclaimOrphanedSending() and move a LIVE peer's
     * in-flight file back to pending/, where a later pass would send it again -
     * a duplicate delivery, invisible because Message-ID is regenerated per
     * send. Scoping sendingDir to sending/w<id> makes that interleaving
     * unreachable: a worker can only ever reclaim its own predecessor's files.
     *
     * Inert when $id is null (the flat sending/ dir is used), so this is a
     * no-op for one-shot runs and while numprocs is still 1.
     */
    public function setWorkerId(?string $id): void
    {
        if ($id === null || $id === '') {
            return;
        }

        // Constrain to what we generate ourselves - this ends up in a path.
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $id);
        if ($safe === '') {
            return;
        }

        $this->sendingDir = $this->spoolDir . '/sending/w' . $safe;

        // ensureDirectoriesExist() already ran in the constructor, so this dir
        // has to be created explicitly here.
        if (!is_dir($this->sendingDir)) {
            mkdir($this->sendingDir, 0755, true);
        }
    }

    /**
     * Spool an email for later sending.
     *
     * Uses a capturing transport to build the complete message through Laravel's
     * mail pipeline, ensuring all withSymfonyMessage callbacks execute and all
     * headers are captured.
     */
    public function spool(Mailable $mailable, string|array|null $to = null, ?string $emailType = null, bool $autoRetry = true): string
    {
        $id = $this->generateId($this->resolvePriorityBand(get_class($mailable), $emailType));
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

        // Last line of defence against generating into a queue that cannot
        // drain. The per-recipient loops in the sending jobs already gate
        // before they build a Mailable, which is where the real saving is;
        // this catches the paths that do not, and any new one somebody adds
        // later without knowing about deferrals. It sits above
        // captureBuiltMessage() so the MJML render is still skipped.
        //
        // Returns '' rather than throwing, matching the existing
        // permanent-failure contract below - callers key off the truthiness
        // of the returned id, so throwing here would change the shape of
        // every caller's error handling.
        if ($this->isSuppressedForDeferral($normalizedTo, $emailType)) {
            return '';
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
            if ($this->isPermanentSmtpFailure($e->getMessage())) {
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

            // Transient / render / build failure (an MJML-server blip, a DB
            // hiccup, or a code bug like an undefined template key). The
            // message never reached the spool, so the send-time retry can't
            // help — and historically this silently DROPPED the recipient
            // (the failure mode that lost ~1,100 immediate digests during a
            // deploy window). If the mailable has opted into durable retry,
            // hand it to the queue instead: SpoolMail re-renders from fresh
            // DB state on a backoff schedule and only dead-letters to
            // failed_jobs after 24h, so a deployed fix drains it
            // automatically.
            //
            // autoRetry is false when SpoolMail itself calls spool(), so a
            // still-broken render rethrows into the queue's own retry
            // machinery rather than dispatching another job.
            if ($autoRetry && $mailable instanceof RetryableMailable) {
                $this->dispatchRetry($mailable, $normalizedTo, $emailType, $e);

                return '';
            }

            throw $e;
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
        // JSON_INVALID_UTF8_SUBSTITUTE: without it, a single malformed byte
        // anywhere in $data (a post subject/body with bad UTF-8) makes
        // json_encode() return FALSE; file_put_contents($tmp, false) then
        // writes a 0-byte file, which renames into pending/, fails to decode
        // ("Invalid spool file","bytes":0) and is dropped to failed/ with no
        // retry — a silent ~0.05% digest loss seen on the 2026-06-11 bulk run.
        // Substituting bad bytes with U+FFFD keeps the email deliverable.
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            // Should be unreachable now (SUBSTITUTE handles bad UTF-8), but if
            // encoding still fails for another reason, fail loudly instead of
            // writing a poison 0-byte spool file that dies undecodable.
            Log::error('EmailSpoolerService: json_encode failed, email not spooled', [
                'id' => $id,
                'to' => array_column($data['to'] ?? [], 'address'),
                'json_error' => json_last_error_msg(),
            ]);
            throw new \RuntimeException('Failed to encode spool payload: ' . json_last_error_msg());
        }
        // Verify the write LANDED before promoting it. The UTF-8 guard above
        // closes the json_encode-returned-false door onto a 0-byte spool file
        // (the 2026-06-11 loss), but not the other one: if the filesystem is
        // full or errors, file_put_contents writes short or fails, and an
        // unchecked rename() promotes that stub into pending/ where it decodes
        // to nothing and is dropped to failed/ with the mail gone. This host
        // has hit 99% disk before, so that door is reachable. Fail loudly and
        // leave nothing behind instead - the caller can retry a throw, but
        // nothing can recover an empty file that looks spooled.
        $written = file_put_contents($tmp, $json);
        if ($written === false || $written !== strlen($json)) {
            @unlink($tmp);
            Log::error('EmailSpoolerService: short/failed spool write, email not spooled', [
                'id' => $id,
                'to' => array_column($data['to'] ?? [], 'address'),
                'expected_bytes' => strlen($json),
                'written_bytes' => $written === false ? 'false' : $written,
            ]);
            throw new \RuntimeException('Failed to write spool payload for ' . $id);
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            Log::error('EmailSpoolerService: could not move spool file into pending', ['id' => $id]);
            throw new \RuntimeException('Failed to place spool file for ' . $id);
        }

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
     * Hand a failed render/build off to the queue for durable retry.
     *
     * Captures the mailable's scalar descriptor (IDs only) and dispatches a
     * SpoolMail job; the job rebuilds a fresh mailable from current DB state
     * and re-renders it. Storing IDs rather than the built message is what lets
     * a fix deployed after a render bug drain the backlog automatically.
     */
    protected function dispatchRetry(Mailable $mailable, array $normalizedTo, ?string $emailType, \Throwable $e): void
    {
        /** @var RetryableMailable $mailable */
        $recipient = $normalizedTo[0]['address'] ?? null;

        SpoolMail::dispatch(
            get_class($mailable),
            $mailable->mailDescriptor(),
            $recipient,
            $emailType,
        );

        Log::warning('Spool render/build failed; dispatched durable retry job', [
            'mailable' => get_class($mailable),
            'recipient' => $recipient,
            'type' => $emailType,
            'error' => $e->getMessage(),
        ]);
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
     * How stale a SIBLING worker's in-flight file must be before we treat it as
     * abandoned. A send is a handful of socket operations bounded by PHP's
     * default_socket_timeout (60s), so minutes - never half an hour. The gate
     * only has meaning because processSpool() touches the file at claim time:
     * rename() preserves mtime, so without that a message that queued for hours
     * would look "abandoned" the instant it was picked up.
     */
    private const SIBLING_RECLAIM_AFTER_SECONDS = 1800;

    /**
     * A message stranded in sending/ that SMTP already accepted. Files it into
     * sent/ rather than putting it back in pending/, so recovering from a dead
     * worker cannot deliver it a second time.
     *
     * Returns false when there is no ledger entry, which means the outcome is
     * genuinely unknown - the worker may have died mid-conversation - and the
     * caller should re-queue as before. Preferring a possible duplicate over a
     * possible loss is the right way round for that case; this method exists to
     * stop us guessing when we do not have to.
     */
    protected function fileAlreadyDelivered(string $sendingPath, string $filename): bool
    {
        $marker = $this->ledgerDir . '/' . $filename;
        if (!file_exists($marker)) {
            return false;
        }

        $raw = @file_get_contents($sendingPath);
        $gz = $raw === false ? false : gzencode($raw, 6);
        if ($gz !== false && @file_put_contents($this->sentDir . '/' . $filename . '.gz', $gz) !== false) {
            @unlink($sendingPath);
        } elseif (!@rename($sendingPath, $this->sentDir . '/' . $filename)) {
            return false;
        }

        @unlink($marker);

        Log::warning('Spool file was already accepted by SMTP - filed as sent, not re-queued', [
            'file' => $filename,
        ]);

        return true;
    }

    /**
     * Reclaim files orphaned in sending/ by a process that died mid-send
     * (restart/OOM/crash), so nothing is dead-lettered and an extended
     * smarthost outage never loses mail.
     *
     * Two passes, because with several workers "everything in sending/" is no
     * longer safe to take:
     *
     *  1. THIS worker's own claim area - taken unconditionally. Only our dead
     *     predecessor can have left files there, so there is no live owner and
     *     recovery is immediate.
     *  2. Sibling areas (sending/w*) and the legacy flat sending/ - taken only
     *     when untouched for SIBLING_RECLAIM_AFTER_SECONDS. This is the safety
     *     net for files own-dir reclaim can never reach: a worker that dies and
     *     never returns, or strands left behind when numprocs is reduced (drop
     *     4 -> 2 and w02/w03 would otherwise keep their mail forever).
     */
    public function reclaimOrphanedSending(): int
    {
        $reclaimed = 0;

        // Pass 1 - our own area, unconditional.
        foreach (glob($this->sendingDir . '/*.json') as $sendingPath) {
            $filename = basename($sendingPath);
            if ($this->fileAlreadyDelivered($sendingPath, $filename)) {
                continue;
            }
            if (rename($sendingPath, $this->pendingDir . '/' . $filename)) {
                $reclaimed++;
            } else {
                Log::warning('Could not reclaim orphaned spool file', ['file' => $filename]);
            }
        }

        if ($reclaimed > 0) {
            Log::warning('Reclaimed orphaned spool files from own sending area on startup', [
                'count' => $reclaimed,
                'dir' => $this->sendingDir,
            ]);
        }

        $reclaimed += $this->reclaimStaleSiblingSending();

        return $reclaimed;
    }

    /**
     * Age-gated sweep of OTHER workers' claim areas and the legacy flat
     * sending/ dir. Deliberately separate from the unconditional own-area pass
     * so it can also be run periodically, not just at startup - a file stranded
     * by a worker that never comes back should self-heal without a restart.
     */
    public function reclaimStaleSiblingSending(): int
    {
        $cutoff = time() - self::SIBLING_RECLAIM_AFTER_SECONDS;
        $reclaimed = 0;

        $candidates = array_merge(
            glob($this->spoolDir . '/sending/*.json') ?: [],
            glob($this->spoolDir . '/sending/w*/*.json') ?: []
        );

        foreach ($candidates as $sendingPath) {
            // Never touch our own area here - pass 1 owns it outright.
            if (dirname($sendingPath) === $this->sendingDir) {
                continue;
            }

            $mtime = @filemtime($sendingPath);
            if ($mtime === false || $mtime > $cutoff) {
                continue;
            }

            $filename = basename($sendingPath);
            if ($this->fileAlreadyDelivered($sendingPath, $filename)) {
                continue;
            }
            if (@rename($sendingPath, $this->pendingDir . '/' . $filename)) {
                $reclaimed++;
                Log::warning('Reclaimed stale spool file from another worker area', [
                    'file' => $filename,
                    'from' => dirname($sendingPath),
                    'idle_seconds' => time() - $mtime,
                ]);
            }
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
            'invalid' => 0,
        ];

        // Strict priority: every URGENT message is taken before any HIGH, and so
        // on down. Bucketing is done on the FILENAME prefix alone - no file is
        // opened to decide its order, which matters because this list routinely
        // runs to tens of thousands of entries during a digest run. glob()
        // returns names sorted and uniqid() is monotonic, so FIFO still holds
        // within each band, and anything unbanded (written before banding, or a
        // band we no longer recognise) is bucketed as BAND_DEFAULT.
        //
        // Strict, not weighted, by explicit choice: the lowest band therefore
        // waits until everything above it is clear, which during a large digest
        // backlog can be hours.
        $buckets = [];
        foreach (glob($this->pendingDir . '/*.json') as $path) {
            $buckets[self::bandFromFilename($path)][] = $path;
        }

        $files = [];
        foreach (self::VALID_BANDS as $band) {
            if (count($files) >= $limit) {
                break;
            }
            if (!empty($buckets[$band])) {
                $files = array_merge(
                    $files,
                    array_slice($buckets[$band], 0, $limit - count($files))
                );
            }
        }

        foreach ($files as $pendingPath) {
            $filename = basename($pendingPath);
            $sendingPath = $this->sendingDir . '/' . $filename;

            // Move to sending directory. Another processor (a second
            // mail:spool:process run or a supervisor worker) may claim this file
            // between the glob() above and here, in which case rename() fails on
            // a now-missing source. Suppress the warning so the source-missing
            // case takes this graceful skip instead of bubbling up as an
            // ErrorException via Laravel's error handler.
            if (!@rename($pendingPath, $sendingPath)) {
                Log::warning('Could not move spool file to sending', ['file' => $filename]);
                continue;
            }

            // Stamp the claim time. rename() preserves mtime, so without this a
            // message that sat in pending/ for hours arrives in sending/ already
            // older than SIBLING_RECLAIM_AFTER_SECONDS and a peer could sweep it
            // out from under us mid-send.
            @touch($sendingPath);

            $stats['processed']++;

            // A spool file is written atomically (temp file + rename), so a
            // complete file in pending/ is always valid JSON. A decode failure
            // here is therefore almost always a transient read (interrupted or
            // partial filesystem read) rather than real corruption — observed
            // from welcome emails that landed in failed/ with attempts:0 yet
            // were perfectly valid JSON afterwards. Re-read once before
            // condemning, and if it still fails record WHY (json error + byte
            // count) so the failure is diagnosable instead of a silent discard.
            $raw = file_get_contents($sendingPath);
            $data = json_decode($raw, true);
            if (!$data) {
                clearstatcache(true, $sendingPath);
                usleep(50000);
                $raw = file_get_contents($sendingPath);
                $data = json_decode($raw, true);
            }
            if (!$data) {
                $stats['invalid']++;
                Log::error('Invalid spool file', [
                    'file' => $filename,
                    'json_error' => json_last_error_msg(),
                    'bytes' => strlen($raw),
                ]);
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
                    // Coalesce nulls to empty strings: TextPart's constructor
                    // rejects null and a spooled mailable that produced only one
                    // of html/text would otherwise crash the whole processSpool
                    // iteration.
                    $textBody = $data['text'] ?? '';
                    $htmlBody = $data['html'] ?? '';

                    if (!empty($data['amp_html'])) {
                        // DEBUG: Uncomment to save AMP HTML for validation testing.
                        // $ampFile = '/tmp/amp-email-' . ($data['id'] ?? uniqid()) . '.html';
                        // file_put_contents($ampFile, $data['amp_html']);
                        // Log::debug('AMP HTML saved for validation', ['file' => $ampFile]);

                        // AMP emails need multipart/alternative with text, AMP, and HTML parts.
                        $textPart = new TextPart($textBody, 'utf-8', 'plain');
                        $ampPart = new TextPart($data['amp_html'], 'utf-8', 'x-amp-html');
                        $htmlPart = new TextPart($htmlBody, 'utf-8', 'html');
                        $alternativePart = new AlternativePart($textPart, $ampPart, $htmlPart);
                        $symfonyMessage->setBody($alternativePart);
                    } elseif ($textBody !== '' && $htmlBody !== '') {
                        // Non-AMP: Build multipart/alternative with text and HTML parts.
                        // This ensures the plain text body is included for email clients
                        // that prefer or require it (e.g., TrashNothing parsing).
                        $textPart = new TextPart($textBody, 'utf-8', 'plain');
                        $htmlPart = new TextPart($htmlBody, 'utf-8', 'html');
                        $alternativePart = new AlternativePart($textPart, $htmlPart);
                        $symfonyMessage->setBody($alternativePart);
                    } elseif ($textBody !== '' && $htmlBody === '') {
                        // Text-only mailable (rare — e.g. plain support refer).
                        // Mail::html() has already set an empty HTML body; replace
                        // with the text part so the recipient sees something.
                        $symfonyMessage->setBody(new TextPart($textBody, 'utf-8', 'plain'));
                    }
                    // If text is empty, Mail::html() has already set HTML-only body.
                });

                // SMTP has accepted the message. Record that on local disk BEFORE
                // filing it into sent/, because everything between here and the
                // rename is where a duplicate is born: if the worker dies or is
                // restarted in that window the file is still sitting in sending/,
                // reclaimOrphanedSending() puts it back in pending/, and it is
                // delivered a second time. That is what produced 14 identical
                // password-reset mails and 6 welcome mails on 2026-08-24 - neither
                // has a per-message marker of its own, so nothing upstream could
                // have caught it.
                //
                // A marker file is cheap and atomic; the filing below is a read,
                // a gzip and a write, so the window this closes is the large one.
                @touch($this->ledgerDir . '/' . $filename);

                // Compress into the sent directory instead of a plain move.
                // Nothing ever reads sent/ back programmatically — it is
                // write-only and pruned after 7 days (see cleanupSent()) — so
                // each message is stored gzipped: JSON wrapping HTML/AMP/text
                // compresses ~85-90%, turning a ~52G/7-day archive into ~6-8G.
                // A human debugging a send can still `zcat` the file. The .gz
                // is created fresh here so its mtime drives retention exactly as
                // the plain .json did. On any read/gzip/write error we fall back
                // to an uncompressed move so a sent record is never lost nor
                // stranded in sending/.
                $raw = @file_get_contents($sendingPath);
                $gz = $raw === false ? false : gzencode($raw, 6);
                if ($gz !== false && @file_put_contents($this->sentDir . '/' . $filename . '.gz', $gz) !== false) {
                    @unlink($sendingPath);
                } else {
                    rename($sendingPath, $this->sentDir . '/' . $filename);
                }
                // Filed successfully, so the ledger entry has done its job. Dropping
                // it here keeps the ledger to just the unresolved cases rather than a
                // copy of every send.
                @unlink($this->ledgerDir . '/' . $filename);

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

                // No recipient at all: Symfony throws "An email must have a
                // To, Cc, or Bcc header" before any SMTP conversation starts,
                // so this is not an SMTP failure and isPermanentSmtpFailure()
                // below never matches it - the message goes back to pending and
                // retries forever. Four MatchedPosts mails spooled 2026-08-06/08
                // with an empty `to` had accumulated 449k-559k attempts each by
                // 2026-08-18, and because the batch is array_slice(glob(...))
                // they sorted first and burned a slot in EVERY pass. Nothing can
                // add a recipient to an already-spooled message, so fail it here.
                // Not routed through recordSmtpBounce(): there is no address to
                // mark as bouncing, and doing so would read $data['to'][0].
                if (empty($data['to']) && empty($data['cc']) && empty($data['bcc'])) {
                    file_put_contents($sendingPath, json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
                    rename($sendingPath, $this->failedDir . '/' . $filename);
                    $stats['invalid']++;

                    Log::error('Spooled email has no recipient - moved to failed, never retryable', [
                        'id' => $data['id'],
                        'mailable_class' => $data['mailable_class'] ?? null,
                        'subject' => $data['subject'] ?? null,
                        'attempts' => $data['attempts'],
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                // Check if this is a permanent SMTP failure that will never succeed.
                if ($this->isPermanentSmtpFailure($e->getMessage())) {
                    // Record as a bounce so the user gets flagged as bouncing.
                    $this->recordSmtpBounce($data, $e->getMessage());

                    // Move to failed - no point retrying.
                    file_put_contents($sendingPath, json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
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
                file_put_contents($sendingPath, json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
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
        // Union across every claim area: once workers use sending/w<id>, a glob
        // of this worker's own dir alone would report 0 in-flight, which is the
        // only in-flight signal there is when diagnosing a stall.
        $sendingFiles = array_merge(
            glob($this->spoolDir . '/sending/*.json') ?: [],
            glob($this->spoolDir . '/sending/w*/*.json') ?: []
        );
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

        // Ledger markers normally live for the microseconds between SMTP
        // accepting a message and it being filed into sent/. One survives only
        // when a worker died in that window AND nothing later reclaimed the
        // file - a marker with no spool file behind it. Prune on the same clock
        // as sent/ so they cannot accumulate silently.
        if (is_dir($this->ledgerDir)) {
            foreach (new \DirectoryIterator($this->ledgerDir) as $marker) {
                if ($marker->isDot() || !$marker->isFile()) {
                    continue;
                }
                if ($marker->getMTime() < $cutoff) {
                    @unlink($marker->getPathname());
                }
            }
        }

        if (!is_dir($this->sentDir)) {
            return 0;
        }

        $iterator = new \DirectoryIterator($this->sentDir);

        foreach ($iterator as $fileInfo) {
            // Sent records are stored gzipped (.gz); accept legacy plain .json
            // too so a mixed directory during rollout is still pruned.
            if ($fileInfo->isDot() || !in_array($fileInfo->getExtension(), ['json', 'gz'], true)) {
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
            file_put_contents($failedPath, json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
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
                file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
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

    /**
     * Priority bands, drained strictly highest-first by processSpool().
     *
     * Encoded in the FILENAME (mail_p<band>_...) rather than read from the file,
     * because processSpool() must order tens of thousands of pending files per
     * tick and cannot afford to open them. `p` is deliberately not a hex digit,
     * so a file written before this existed (mail_<hex>...) can never be
     * mistaken for a banded one - it falls to BAND_DEFAULT instead.
     */
    public const BAND_URGENT  = 1;   // chat, welcome, session, donation - a person is waiting
    public const BAND_HIGH    = 3;   // immediate digests - users expect these promptly
    public const BAND_DEFAULT = 5;   // daily digests, engage, and ANYTHING UNRECOGNISED
    public const BAND_LOW     = 9;   // community events, newsletters, chase-ups

    private const VALID_BANDS = [self::BAND_URGENT, self::BAND_HIGH, self::BAND_DEFAULT, self::BAND_LOW];

    /**
     * email_type is the authoritative signal where callers set it. Types not
     * listed here fall through to the namespace map, then to BAND_DEFAULT.
     */
    private const BAND_BY_EMAIL_TYPE = [
        'chat'             => self::BAND_URGENT,
        'welcome'          => self::BAND_URGENT,
        'digest_immediate' => self::BAND_HIGH,
        'digest_daily'     => self::BAND_DEFAULT,
        'engage'           => self::BAND_DEFAULT,
        'reengage'         => self::BAND_DEFAULT,
    ];

    /**
     * Fallback by mailable namespace, for the many callers that pass no
     * email_type at all. Only namespaces we deliberately want OFF the default
     * are listed - everything else is intentionally absent so it defaults.
     */
    private const BAND_BY_NAMESPACE = [
        'Chat'          => self::BAND_URGENT,
        'Welcome'       => self::BAND_URGENT,
        'Session'       => self::BAND_URGENT,
        'Donation'      => self::BAND_URGENT,
        'Alert'         => self::BAND_URGENT,
        'Event'         => self::BAND_LOW,
        'CommunityNews' => self::BAND_LOW,
        'Stories'       => self::BAND_LOW,
        'Volunteering'  => self::BAND_LOW,
        'Newsfeed'      => self::BAND_LOW,
        'Noticeboard'   => self::BAND_LOW,
        'Birthday'      => self::BAND_LOW,
    ];

    /** Specific classes that sit off their namespace's band. */
    private const BAND_BY_CLASS_FRAGMENT = [
        'ChaseUp'            => self::BAND_LOW,
        'AutoRepostWarning'  => self::BAND_LOW,
    ];

    /**
     * Decide which band a mailable belongs in.
     *
     * NEVER throws and ALWAYS returns a valid band: an unrecognised mailable -
     * including any added in future with no entry here - gets BAND_DEFAULT, so
     * new mail keeps flowing at normal priority rather than being dropped,
     * starved at the bottom, or promoted above chat. Adding a class to the maps
     * above is an optimisation, never a requirement.
     */
    public function resolvePriorityBand(?string $mailableClass, ?string $emailType): int
    {
        try {
            if ($emailType !== null && isset(self::BAND_BY_EMAIL_TYPE[$emailType])) {
                return self::BAND_BY_EMAIL_TYPE[$emailType];
            }

            if ($mailableClass !== null && $mailableClass !== '') {
                foreach (self::BAND_BY_CLASS_FRAGMENT as $fragment => $band) {
                    if (str_contains($mailableClass, $fragment)) {
                        return $band;
                    }
                }

                // App\Mail\<Namespace>\<Class>
                $parts = explode('\\', $mailableClass);
                if (count($parts) >= 2) {
                    $namespace = $parts[count($parts) - 2];
                    if (isset(self::BAND_BY_NAMESPACE[$namespace])) {
                        return self::BAND_BY_NAMESPACE[$namespace];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Classification must never stop a mail being spooled.
            Log::warning('Could not resolve spool priority band, using default', [
                'mailable_class' => $mailableClass,
                'email_type' => $emailType,
                'error' => $e->getMessage(),
            ]);
        }

        return self::BAND_DEFAULT;
    }

    /**
     * Read the band back out of a spool filename. Anything that does not carry
     * a recognised mail_p<band>_ prefix - files written before banding existed,
     * or a band value we no longer use - is treated as BAND_DEFAULT.
     */
    public static function bandFromFilename(string $filename): int
    {
        if (preg_match('/^mail_p(\d+)_/', basename($filename), $m)) {
            $band = (int) $m[1];
            if (in_array($band, self::VALID_BANDS, true)) {
                return $band;
            }
        }

        return self::BAND_DEFAULT;
    }

    protected function generateId(int $band = self::BAND_DEFAULT): string
    {
        if (!in_array($band, self::VALID_BANDS, true)) {
            $band = self::BAND_DEFAULT;
        }

        // uniqid() is microtime-derived and monotonic, so files sort
        // chronologically WITHIN a band - FIFO is preserved per band.
        return 'mail_p' . $band . '_' . uniqid('', true) . '_' . bin2hex(random_bytes(4));
    }

    protected function ensureDirectoriesExist(): void
    {
        foreach ([$this->pendingDir, $this->sendingDir, $this->failedDir, $this->sentDir, $this->ledgerDir] as $dir) {
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
    /**
     * Whether every recipient of this message is behind a provider that is
     * currently refusing our mail.
     *
     * All of them, not any: a message addressed to a member and cc'd to a
     * mod should still go if the mod can receive it. In practice batch mail
     * is single-recipient, so this is nearly always one address.
     */
    private function isSuppressedForDeferral(array $normalizedTo, ?string $emailType): bool
    {
        if ($normalizedTo === []) {
            return FALSE;
        }

        $suppressions = app(\App\Services\Mail\MailSuppressionService::class);

        foreach ($normalizedTo as $addr) {
            $address = is_array($addr) ? ($addr['address'] ?? '') : (string) $addr;

            if (!$suppressions->isSuppressed($address)) {
                return FALSE;
            }
        }

        Log::info('EmailSpoolerService: not spooling, recipient provider is deferring our mail', [
            'email_type' => $emailType,
            'recipients' => count($normalizedTo),
        ]);

        return TRUE;
    }
}
