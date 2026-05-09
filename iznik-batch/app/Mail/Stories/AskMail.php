<?php

namespace App\Mail\Stories;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AskMail extends Mailable
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $recipientEmail,
        public readonly string $storiesUrl,
        public readonly string $unsubscribeUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tell us your Freegle story!');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.stories.ask',
            with: [
                'name' => $this->recipientName,
                'email' => $this->recipientEmail,
                'storiesUrl' => $this->storiesUrl,
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ]
        );
    }
}
