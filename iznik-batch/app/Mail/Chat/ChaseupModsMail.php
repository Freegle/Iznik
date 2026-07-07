<?php

namespace App\Mail\Chat;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email sent to group mods when a member's User2Mod chat has had no reply.
 *
 * V1 parity: ChatRoom::chaseupMods() lines 2010-2076.
 */
class ChaseupModsMail extends MjmlMailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $groupName,
        public readonly string $memberName,
        public readonly string $memberEmail,
        public readonly string $textSummary,
        public readonly string $chatUrl,
        public readonly string $fromAddress,
        public readonly string $replyToAddress,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        return "Member conversation on {$this->groupName} with {$this->memberName} ({$this->memberEmail})";
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->memberName),
            replyTo: [new Address($this->replyToAddress)],
            to: [new Address($this->recipientEmail)],
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        return $this->mjmlView('emails.mjml.chat.chaseup-mods', [
            'groupName'   => $this->groupName,
            'memberName'  => $this->memberName,
            'memberEmail' => $this->memberEmail,
            'textSummary' => $this->textSummary,
            'chatUrl'     => $this->chatUrl,
            'email'       => $this->recipientEmail,
        ]);
    }
}
