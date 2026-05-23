<?php

namespace App\Mail\Alert;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AlertMail extends Mailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $recipientName,
        public readonly string $fromAddress,
        public readonly string $fromName,
        string $subject,
        public readonly string $htmlBody,
        public readonly string $textBody,
    ) {
        $this->subject = $subject;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            to: [new Address($this->recipientEmail, $this->recipientName)],
            subject: $this->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.alert.alert',
            with: [
                'subject' => $this->subject,
                'htmlBody' => $this->htmlBody,
                'textBody' => $this->textBody,
            ],
        );
    }
}
