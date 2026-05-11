<?php

namespace App\Mail\Engage;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class EngageMail extends Mailable
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $recipientEmail,
        public readonly string $emailSubject,
        public readonly string $template,
        public readonly int $engageId,
        public readonly string $unsubscribeUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: config('freegle.mail.noreply_addr'),
            to: [$this->recipientEmail],
            subject: $this->emailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: "emails.engage.{$this->template}",
            with: [
                'name' => $this->recipientName,
                'email' => $this->recipientEmail,
                'engageId' => $this->engageId,
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ],
        );
    }
}
