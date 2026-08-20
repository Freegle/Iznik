<?php

namespace App\Mail\Chat;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Plain text email sent to the central spam team when a user reports a chat with
 * someone they share no Freegle group with, so it can't be routed to a
 * community's moderators.
 *
 * Discourse: https://discourse.ilovefreegle.org/t/reporting-chat-with-no-group/9828
 */
class ChatSpamReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $reporterName,
        public readonly int $reporterId,
        public readonly string $otherUserName,
        public readonly int $otherUserId,
        public readonly int $chatId,
        public readonly string $reason,
        public readonly string $comment,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr'),
                config('freegle.branding.name')
            ),
            subject: "{$this->reporterName} (#{$this->reporterId}) reported {$this->otherUserName} (#{$this->otherUserId}) - chat #{$this->chatId} (no group in common)",
        );
    }

    public function build(): static
    {
        $modSite = config('freegle.sites.mod');

        $lines = [];
        $lines[] = "This chat was reported, but the two people share no Freegle group, so it can't go to a community's volunteers - it has come to the central spam team.";
        $lines[] = "";
        $lines[] = "Reason: {$this->reason}";
        if ($this->comment !== '') {
            $lines[] = "Comment: {$this->comment}";
        }
        $lines[] = "";
        $lines[] = "Review the chat at {$modSite}/support/refer/{$this->chatId}";

        return $this->text('emails.plain.refer-to-support', ['body' => implode("\n", $lines)]);
    }
}
