<?php

namespace App\Mail\Housekeeper;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Plain-text report email for the Discourse housekeeping crons
 * (discourse:check-users, discourse:not-signed-up). V1 sent these as plain
 * text via Swift_Message; we wrap the body in a <pre> so the layout survives.
 */
class DiscourseReportMail extends Mailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $recipientName,
        public readonly string $fromEmail,
        public readonly string $emailSubject,
        public readonly string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromEmail, 'Freegle Geeks'),
            to: [new Address($this->recipientEmail, $this->recipientName)],
            subject: $this->emailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<pre style="font-family:monospace;white-space:pre-wrap">'.e($this->body).'</pre>',
        );
    }
}
