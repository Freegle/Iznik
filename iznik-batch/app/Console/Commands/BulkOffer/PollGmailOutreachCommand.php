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
 *  - any other reply marks the row Replied and is emitted (as JSON) for the FSM;
 *  - the thread's messages are marked read so they are not re-emitted next poll.
 *
 * Emits a JSON array of reply threads to stdout so the gmail FSM driver can act.
 */
class PollGmailOutreachCommand extends Command
{
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

            // Mark every message in the thread read so it isn't re-emitted.
            foreach (($thread['messages'] ?? []) as $m) {
                if (! empty($m['id'])) {
                    try {
                        $gmail->modifyMessageLabels($m['id'], remove: ['UNREAD']);
                    } catch (\Throwable $e) {
                        // best-effort
                    }
                }
            }

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
     * reply), reduced to {from, subject, body, to}.
     *
     * @return array<string,string>|null
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
}
