<?php

namespace App\Mail\Chat;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Plain text email sent to group mods when a member's User2Mod chat has had no reply.
 *
 * V1 parity: ChatRoom::chaseupMods() lines 2010-2076.
 */
class ChaseupModsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $groupName,
        public readonly string $memberName,
        public readonly string $memberEmail,
        public readonly string $textSummary,
        public readonly string $chatUrl,
        public readonly string $fromAddress,
        public readonly string $replyToAddress,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->memberName),
            replyTo: [new Address($this->replyToAddress)],
            subject: "Member conversation on {$this->groupName} with {$this->memberName} ({$this->memberEmail})",
        );
    }

    public function build(): static
    {
        $body = "We can't find a reply to this message from your member, so we're resending it in case you missed it.\r\n\r\n"
            . "If you've already replied, or if it doesn't need a reply, please ignore this.\r\n\r\n"
            . "From: {$this->memberName}\r\n\r\n"
            . $this->textSummary . "\r\n\r\n"
            . "View and reply at: {$this->chatUrl}\r\n\r\n"
            . "(You can also reply by email, but the button works better.)";

        return $this->text('emails.plain.refer-to-support', ['body' => $body]);
    }
}
