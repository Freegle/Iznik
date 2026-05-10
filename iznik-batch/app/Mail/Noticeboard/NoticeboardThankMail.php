<?php

namespace App\Mail\Noticeboard;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class NoticeboardThankMail extends MjmlMailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $recipientName,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        return 'Thanks for putting up a poster!';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org'),
                config('freegle.branding.name', 'Freegle')
            ),
            to: [new Address($this->recipientEmail, $this->recipientName)],
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        return $this->mjmlView('emails.mjml.noticeboard.thanks', [
            'email' => $this->recipientEmail,
        ]);
    }
}
