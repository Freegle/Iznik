<?php

namespace App\Mail\Group;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class BoundaryErrorMail extends Mailable
{
    public function __construct(
        public int $groupId,
        public string $groupName,
        public string $errorMessage,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.from_address', 'geeks@ilovefreegle.org')
            ),
            to: [new Address(config('freegle.mail.geeks_addr', 'geeks@ilovefreegle.org'))],
            subject: "Invalid CGA/DPA for {$this->groupId} {$this->groupName}",
        );
    }

    public function content(): Content
    {
        return new Content(text: 'emails.plain.boundary-error');
    }
}
