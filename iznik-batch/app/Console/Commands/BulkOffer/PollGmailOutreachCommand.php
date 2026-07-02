<?php

namespace App\Console\Commands\BulkOffer;

use App\Models\MessagesBulkOutreach;
use App\Services\Gmail\GmailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Poll the outreach mailbox for replies to Sent outreach emails. This is the
 * mailbox-transport equivalent of helper/poll.sh: it turns inbound email into the
 * change signal + context the concierge FSM acts on.
 *
 * For each unread inbox thread that matches a Sent outreach row (by gmail_thread_id):
 *  - an unsubscribe (sent to the +unsub address, or body/subject says UNSUBSCRIBE)
 *    sets suppressed_until far in the future and marks the row Declined (PECR);
 *  - a delivery-failure bounce (mailer-daemon/postmaster, a DSN subject, or a
 *    multipart/report + message/delivery-status MIME part) marks the row Bounced
 *    and suppresses it far in the future, so a dead address is never re-contacted;
 *  - an auto-reply / out-of-office / auto-acknowledgement (RFC 3834
 *    Auto-Submitted header, Precedence: bulk/auto_reply/junk, X-Autoreply /
 *    X-Autorespond, or the usual OOO subject/body patterns) marks the row
 *    AutoAck - it is NOT a genuine human reply, so it is not emitted to the FSM;
 *  - any other reply marks the row Replied and is emitted (as JSON) for the FSM;
 *  - the thread's messages are marked read so they are not re-emitted next poll,
 *    regardless of which of the above applies.
 *
 * Emits a JSON array of reply threads to stdout so the gmail FSM driver can act.
 */
class PollGmailOutreachCommand extends Command
{
    /**
     * Subject substrings that indicate an auto-reply / OOO / auto-acknowledgement.
     * Mirrors App\Services\Mail\Incoming\ParsedEmail::isAutoReply() - kept as a
     * small, deliberate duplication here rather than reworking ParsedEmail (which
     * parses raw MIME, not the Gmail API thread/message JSON this command reads).
     */
    private const AUTO_REPLY_SUBJECT_PATTERNS = [
        'Auto Response', 'Autoresponder', 'If your enquiry is urgent',
        'Thankyou for your enquiry', 'Thanks for your email',
        'Thanks for contacting', 'Thank you for your enquiry',
        'Many thanks for your', 'Automatic reply', 'Automated reply',
        'Auto-Reply', 'Out of Office', 'maternity leave', 'paternity leave',
        'return to the office', 'due to return', 'annual leave',
        'on holiday', 'vacation reply', 'YOUR ORDER MANAGEMENT REQUEST',
    ];

    /** Body substrings that indicate an auto-reply / OOO / auto-acknowledgement. */
    private const AUTO_REPLY_BODY_PATTERNS = [
        'I aim to respond within', 'Our team aims to respond',
        'reply as soon as possible', 'with clients right now',
        'Automated response', 'Please note his new address',
        'THIS IS AN AUTO-RESPONSE MESSAGE', 'out of the office',
        'on annual leave', 'Thank you so much for your email enquiry',
        "Thanks for your email enquiry", "don't check this very often",
        'below to complete the verification process',
        'We respond to emails as quickly as we can',
        'this email address is no longer in use', 'away from the office',
        "I won't be able to check any emails until after",
        "I'm on leave at the moment",
        "We'll get back to you as soon as possible",
        'currently on leave', 'To complete this verification',
        'I am currently away from my computer, but will reply to your message as soon as I return',
        "E-mails to personal mailboxes aren't monitored",
        'I am currently unavailable',
        'We appreciate your patience while we get back to you.',
    ];

    /**
     * Subject substrings that indicate a delivery-failure bounce/DSN. Mirrors (a
     * subset of) App\Services\Mail\Incoming\MailParserService::isBounceSubject(),
     * per the same "small duplication over cross-service coupling" rationale.
     */
    private const BOUNCE_SUBJECT_PATTERNS = [
        'Delivery Status Notification', 'Undelivered', 'Mail delivery failed',
        'failure notice', 'Returned mail', 'Address not found',
    ];

    protected $signature = 'bulkoffer:poll-outreach
                            {--msgid= : Only poll replies for this bulk offer}
                            {--query=in:inbox is:unread newer_than:60d : Gmail search for candidate threads}';

    protected $description = 'Poll the outreach mailbox for replies to sent outreach emails (mailbox FSM transport)';

    public function handle(GmailService $gmail): int
    {
        $msgid = $this->option('msgid') !== null ? (int) $this->option('msgid') : null;

        // Map gmail_thread_id -> outreach row for the Sent rows we're watching.
        $watch = DB::table('messages_bulk_outreach')
            ->where('status', MessagesBulkOutreach::STATUS_SENT)
            ->whereNotNull('gmail_thread_id')
            ->when($msgid !== null, fn ($q) => $q->where('msgid', $msgid))
            ->get()
            ->keyBy('gmail_thread_id');
        if ($watch->isEmpty()) {
            $this->line('[]');

            return self::SUCCESS;
        }

        try {
            $threads = $gmail->listThreads((string) $this->option('query'));
        } catch (\Throwable $e) {
            Log::error('Outreach poll: listThreads failed', ['error' => $e->getMessage()]);
            $this->line('[]');

            return self::SUCCESS;
        }

        $unsubAddress = strtolower((string) config('services.gmail_outreach.unsub_address', 'natalie-wagg+unsub@ilovefreegle.org'));
        $replies = [];
        foreach ($threads as $stub) {
            $threadId = $stub['id'] ?? null;
            if (! $threadId || ! $watch->has($threadId)) {
                continue;
            }
            $row = $watch->get($threadId);

            try {
                $thread = $gmail->getThread($threadId);
            } catch (\Throwable $e) {
                Log::warning('Outreach poll: getThread failed', ['thread' => $threadId, 'error' => $e->getMessage()]);

                continue;
            }

            $latest = $this->latestInbound($thread);
            if (! $latest) {
                continue;
            }

            // Mark every message in the thread read so it isn't re-emitted, no
            // matter how it classifies below.
            foreach (($thread['messages'] ?? []) as $m) {
                if (! empty($m['id'])) {
                    try {
                        $gmail->modifyMessageLabels($m['id'], remove: ['UNREAD']);
                    } catch (\Throwable $e) {
                        // best-effort
                    }
                }
            }

            $headers = $latest['headers'] ?? [];

            if ($this->isUnsubscribe($latest, $unsubAddress)) {
                DB::table('messages_bulk_outreach')->where('id', $row->id)->update([
                    'status' => MessagesBulkOutreach::STATUS_DECLINED,
                    'suppressed_until' => now()->addYears(100)->toDateString(),
                    'replied_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->info("Outreach #{$row->id}: unsubscribe recorded, suppressed.");

                continue;
            }

            if ($this->isBounce($latest, $headers)) {
                DB::table('messages_bulk_outreach')->where('id', $row->id)->update([
                    'status' => MessagesBulkOutreach::STATUS_BOUNCED,
                    'suppressed_until' => now()->addYears(100)->toDateString(),
                    'updated_at' => now(),
                ]);
                $this->info("Outreach #{$row->id}: delivery-failure bounce, suppressed.");

                continue;
            }

            if ($this->isAutoReply($latest, $headers)) {
                DB::table('messages_bulk_outreach')->where('id', $row->id)->update([
                    'status' => MessagesBulkOutreach::STATUS_AUTO_ACK,
                    'updated_at' => now(),
                ]);
                $this->info("Outreach #{$row->id}: auto-reply/OOO detected, not a genuine reply.");

                continue;
            }

            DB::table('messages_bulk_outreach')->where('id', $row->id)->update([
                'status' => MessagesBulkOutreach::STATUS_REPLIED,
                'replied_at' => now(),
                'updated_at' => now(),
            ]);
            $replies[] = [
                'outreachid' => (int) $row->id,
                'msgid' => (int) $row->msgid,
                'orgname' => $row->orgname,
                'threadid' => $threadId,
                'from' => $latest['from'],
                'subject' => $latest['subject'],
                'body' => $latest['body'],
            ];
        }

        $this->line(json_encode($replies, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * The most recent message in the thread that wasn't sent by us (an inbound
     * reply), reduced to {from, to, subject, body} plus the handful of raw
     * headers/MIME signals the auto-reply and bounce classifiers need.
     *
     * @return array<string,mixed>|null
     */
    private function latestInbound(array $thread): ?array
    {
        $mailbox = strtolower((string) config('services.gmail_outreach.mailbox', 'natalie-wagg@ilovefreegle.org'));
        $best = null;
        foreach (($thread['messages'] ?? []) as $m) {
            $headers = $this->headers($m);
            $from = strtolower($headers['from'] ?? '');
            // Skip our own outgoing messages.
            if ($mailbox !== '' && str_contains($from, $mailbox)) {
                continue;
            }
            $best = [
                'from' => $headers['from'] ?? '',
                'to' => $headers['to'] ?? '',
                'subject' => $headers['subject'] ?? '',
                'body' => $this->plainBody($m['payload'] ?? []),
                'is_delivery_report' => $this->isDeliveryReportMime($m['payload'] ?? []),
                // Raw headers the isAutoReply()/isBounce() classifiers key off,
                // kept alongside (not instead of) the flattened fields above.
                'headers' => [
                    'auto-submitted' => $headers['auto-submitted'] ?? '',
                    'precedence' => $headers['precedence'] ?? '',
                    'x-autoreply' => $headers['x-autoreply'] ?? '',
                    'x-autorespond' => $headers['x-autorespond'] ?? '',
                    'return-path' => $headers['return-path'] ?? '',
                    'list-id' => $headers['list-id'] ?? '',
                ],
            ];
        }

        return $best;
    }

    /** Lower-cased header name => value for a Gmail message. */
    private function headers(array $message): array
    {
        $out = [];
        foreach (($message['payload']['headers'] ?? []) as $h) {
            if (isset($h['name'])) {
                $out[strtolower($h['name'])] = $h['value'] ?? '';
            }
        }

        return $out;
    }

    /** Extract a plain-text body from a (possibly multipart) Gmail payload. */
    private function plainBody(array $payload): string
    {
        $mime = $payload['mimeType'] ?? '';
        if ($mime === 'text/plain' && ! empty($payload['body']['data'])) {
            return $this->decode($payload['body']['data']);
        }
        foreach (($payload['parts'] ?? []) as $part) {
            $body = $this->plainBody($part);
            if ($body !== '') {
                return $body;
            }
        }
        // Fall back to any body data (e.g. text/html) if no text/plain found.
        if (! empty($payload['body']['data'])) {
            return trim(strip_tags($this->decode($payload['body']['data'])));
        }

        return '';
    }

    /**
     * Does this (possibly multipart) Gmail payload carry a delivery-status
     * report part (multipart/report + message/delivery-status), the MIME shape
     * of an RFC 3464 bounce/DSN? Mirrors the MIME check in
     * App\Services\Mail\Incoming\MailParserService::extractBounceInfo().
     */
    private function isDeliveryReportMime(array $payload): bool
    {
        $mime = strtolower((string) ($payload['mimeType'] ?? ''));
        if ($mime === 'multipart/report' || $mime === 'message/delivery-status') {
            return true;
        }
        foreach (($payload['parts'] ?? []) as $part) {
            if ($this->isDeliveryReportMime($part)) {
                return true;
            }
        }

        return false;
    }

    private function decode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }

    /** Is this reply an opt-out? Sent to the +unsub address, or says UNSUBSCRIBE. */
    private function isUnsubscribe(array $latest, string $unsubAddress): bool
    {
        if ($unsubAddress !== '' && str_contains(strtolower($latest['to'] ?? ''), $unsubAddress)) {
            return true;
        }
        $haystack = strtolower(($latest['subject'] ?? '').' '.($latest['body'] ?? ''));

        return str_contains($haystack, 'unsubscribe') || preg_match('/\bstop\b/', $haystack) === 1;
    }

    /**
     * Is this an auto-reply / out-of-office / auto-acknowledgement rather than a
     * genuine human reply? Mirrors
     * App\Services\Mail\Incoming\ParsedEmail::isAutoReply(): the RFC 3834
     * Auto-Submitted header (present and not "no"), common bulk/auto Precedence
     * values, the non-standard X-Autoreply/X-Autorespond headers some MTAs send,
     * and the legacy subject/body substring patterns.
     */
    private function isAutoReply(array $latest, array $headers): bool
    {
        $autoSubmitted = strtolower(trim($headers['auto-submitted'] ?? ''));
        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return true;
        }

        $precedence = strtolower(trim($headers['precedence'] ?? ''));
        if (in_array($precedence, ['auto_reply', 'bulk', 'junk'], true)) {
            return true;
        }

        if (trim($headers['x-autoreply'] ?? '') !== '' || trim($headers['x-autorespond'] ?? '') !== '') {
            return true;
        }

        $subject = $latest['subject'] ?? '';
        foreach (self::AUTO_REPLY_SUBJECT_PATTERNS as $pattern) {
            if (stripos($subject, $pattern) !== false) {
                return true;
            }
        }

        $body = $latest['body'] ?? '';
        foreach (self::AUTO_REPLY_BODY_PATTERNS as $pattern) {
            if (stripos($body, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this a delivery-failure bounce/DSN rather than a genuine human reply?
     * Mirrors App\Services\Mail\Incoming\MailParserService::extractBounceInfo():
     * a mailer-daemon/postmaster sender, a DSN-style subject, or a
     * multipart/report + message/delivery-status MIME part.
     */
    private function isBounce(array $latest, array $headers): bool
    {
        $from = strtolower($latest['from'] ?? '');
        if (str_contains($from, 'mailer-daemon') || str_contains($from, 'postmaster')) {
            return true;
        }

        $subject = $latest['subject'] ?? '';
        foreach (self::BOUNCE_SUBJECT_PATTERNS as $pattern) {
            if (stripos($subject, $pattern) !== false) {
                return true;
            }
        }

        return ! empty($latest['is_delivery_report']);
    }
}
