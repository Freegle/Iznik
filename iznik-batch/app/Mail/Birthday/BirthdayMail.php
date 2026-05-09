<?php

namespace App\Mail\Birthday;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class BirthdayMail extends Mailable
{
    public function __construct(
        public readonly string $groupName,
        public readonly string $groupNameShort,
        public readonly int $groupAge,
        public readonly int $groupId,
        public readonly string $recipientEmail,
        public readonly string $fromEmail,
        public readonly string $fromName,
        public readonly array $volunteers,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromEmail, $this->fromName),
            to: [new Address($this->recipientEmail)],
            replyTo: [new Address($this->fromEmail)],
            subject: 'Happy Birthday to all us freeglers!',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.birthday.birthday',
            with: [
                'groupName' => $this->groupName,
                'groupNameShort' => $this->groupNameShort,
                'groupAge' => $this->groupAge,
                'email' => $this->recipientEmail,
                'volunteers' => $this->volunteers,
            ],
        );
    }
}
