<?php

namespace App\Mail\Chat;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Plain text email sent to support when a user refers a chat for help.
 *
 * V1 parity: ChatRoom::referToSupport() lines 2266-2284.
 */
class ReferToSupportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $userName,
        public readonly int $userId,
        public readonly int $chatId,
        public readonly string $otherUserName,
        public readonly int $otherUserId,
        public readonly string $replyToAddress,
        public readonly string $replyToName,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr'),
                config('freegle.branding.name')
            ),
            replyTo: [new Address($this->replyToAddress, $this->replyToName)],
            subject: "{$this->userName} (#{$this->userId}) asked for help with chat #{$this->chatId} with {$this->otherUserName} (#{$this->otherUserId})",
        );
    }

    public function build(): static
    {
        $modSite = config('freegle.sites.mod');
        $body = "Please review the chat at {$modSite}/modtools/support/refer/{$this->chatId} and then reply to this email to contact the mod who requested help.";

        return $this->text('emails.plain.refer-to-support', ['body' => $body]);
    }
}
