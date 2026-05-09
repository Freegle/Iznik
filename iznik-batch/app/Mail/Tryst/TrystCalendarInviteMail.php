<?php

namespace App\Mail\Tryst;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TrystCalendarInviteMail extends Mailable
{
    public function __construct(
        public readonly string $title,
        public readonly string $calendarLink,
        public readonly int $recipientUserId,
        public readonly string $recipientName,
        public readonly string $recipientEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org'),
                config('freegle.branding.name', 'Freegle')
            ),
            to: [new Address($this->recipientEmail, $this->recipientName)],
            subject: "Please add to your calendar - {$this->title}",
        );
    }

    public function content(): Content
    {
        $unsubscribeUrl = config('freegle.sites.user', 'https://www.ilovefreegle.org')
            . '/unsubscribe/' . $this->recipientUserId;

        return new Content(
            view: 'emails.tryst.calendar-invite',
            with: [
                'title' => $this->title,
                'calendarLink' => $this->calendarLink,
                'unsubscribeUrl' => $unsubscribeUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
