<?php

namespace App\Mail\Group;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ModWelfareAlertMail extends Mailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $fromEmail,
        public readonly string $emailSubject,
        public readonly string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromEmail, config('freegle.branding.name', 'Freegle')),
            to: [new Address($this->recipientEmail)],
            subject: $this->emailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: '<pre style="font-family:sans-serif">' . e($this->body) . '</pre>');
    }
}
