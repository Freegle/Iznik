<?php

namespace App\Console\Commands\BulkOffer;

use App\Models\MessagesBulkOutreach;
use App\Services\Gmail\GmailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mime\Address;

/**
 * Send a concierge reply in an existing outreach mail thread. This is the
 * mailbox-transport equivalent of the chat "Send" action: the FSM brain composes
 * the reply text and calls this to deliver it, threaded onto the org's reply so
 * it lands in the same conversation.
 */
class ReplyGmailOutreachCommand extends Command
{
    protected $signature = 'bulkoffer:reply-outreach
                            {--thread= : Gmail thread id to reply in (required)}
                            {--body= : Reply text (required)}
                            {--live : Actually send when the mailbox is configured live}';

    protected $description = 'Send a concierge reply in an outreach mail thread (mailbox FSM transport)';

    public function handle(GmailService $gmail): int
    {
        $threadId = (string) $this->option('thread');
        $body = (string) $this->option('body');
        if ($threadId === '' || trim($body) === '') {
            $this->error('--thread and --body are required.');

            return self::FAILURE;
        }
        if (! $gmail->isDryRun() && ! $this->option('live')) {
            $this->error('Mailbox is live but --live not passed. Refusing to send.');

            return self::FAILURE;
        }

        try {
            $thread = $gmail->getThread($threadId);
        } catch (\Throwable $e) {
            $this->error("Could not load thread {$threadId}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $ctx = $this->replyContext($thread);
        if (! $ctx['to']) {
            $this->error('No inbound message to reply to in that thread.');

            return self::FAILURE;
        }

        $subject = $ctx['subject'];
        if (stripos($subject, 're:') !== 0) {
            $subject = 'Re: '.$subject;
        }

        $headers = [];
        if ($ctx['messageId']) {
            $headers['In-Reply-To'] = $ctx['messageId'];
            $headers['References'] = trim(($ctx['references'] ? $ctx['references'].' ' : '').$ctx['messageId']);
        }

        $text = $body;
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#333;">'
            .nl2br(htmlspecialchars($body, ENT_QUOTES)).'</div>';

        try {
            $gmail->send(
                to: Address::create($ctx['to']),
                subject: $subject,
                html: $html,
                text: $text,
                headers: $headers,
            );
        } catch (\Throwable $e) {
            $this->error("Send failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        // Record on the matching outreach row, if any (best-effort).
        DB::table('messages_bulk_outreach')
            ->where('gmail_thread_id', $threadId)
            ->update(['updated_at' => now()]);

        $this->info('Reply sent'.($gmail->isDryRun() ? ' (dry-run .eml)' : '').' in thread '.$threadId.'.');

        return self::SUCCESS;
    }

    /**
     * Reply target + threading headers from the latest inbound message.
     *
     * @return array{to:string,subject:string,messageId:string,references:string}
     */
    private function replyContext(array $thread): array
    {
        $mailbox = strtolower((string) config('services.gmail_outreach.mailbox', 'natalie-wagg@ilovefreegle.org'));
        $ctx = ['to' => '', 'subject' => '', 'messageId' => '', 'references' => ''];
        foreach (($thread['messages'] ?? []) as $m) {
            $h = [];
            foreach (($m['payload']['headers'] ?? []) as $hd) {
                if (isset($hd['name'])) {
                    $h[strtolower($hd['name'])] = $hd['value'] ?? '';
                }
            }
            if ($mailbox !== '' && str_contains(strtolower($h['from'] ?? ''), $mailbox)) {
                continue; // our own outgoing message
            }
            $ctx = [
                'to' => $h['reply-to'] ?? $h['from'] ?? '',
                'subject' => $h['subject'] ?? '',
                'messageId' => $h['message-id'] ?? '',
                'references' => $h['references'] ?? '',
            ];
        }

        return $ctx;
    }
}
