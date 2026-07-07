<?php

namespace App\Mail\Stories;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class AskMail extends MjmlMailable
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $recipientEmail,
        public readonly string $storiesUrl,
        public readonly string $unsubscribeUrl,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        return 'Tell us your Freegle story!';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org'),
                config('freegle.branding.name', 'Freegle')
            ),
            to: [new Address($this->recipientEmail)],
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        return $this->mjmlView('emails.mjml.stories.ask', [
            'name'           => $this->recipientName,
            'storiesUrl'     => $this->storiesUrl,
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'email'          => $this->recipientEmail,
        ]);
    }
}
