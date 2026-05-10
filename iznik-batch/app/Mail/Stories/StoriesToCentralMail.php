<?php

namespace App\Mail\Stories;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class StoriesToCentralMail extends Mailable
{
    public function __construct(
        public readonly array $stories,
        public readonly string $previewText,
        public readonly string $voteUrl,
        public readonly string $emailSubject,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('freegle.mail.geeks_addr'), config('freegle.branding.name')),
            to: [new Address(config('freegle.mail.central_mail_to'))],
            subject: $this->emailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.stories.central',
            with: [
                'stories' => $this->stories,
                'previewtext' => $this->previewText,
                'vote' => $this->voteUrl,
            ],
        );
    }
}
