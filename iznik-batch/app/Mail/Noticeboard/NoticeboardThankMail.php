<?php

namespace App\Mail\Noticeboard;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NoticeboardThankMail extends Mailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org'),
                config('freegle.branding.name', 'Freegle')
            ),
            to: [new Address($this->recipientEmail, $this->recipientName)],
            subject: 'Thanks for putting up a poster!',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.noticeboard.thanks',
            with: [
                'email' => $this->recipientEmail,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
