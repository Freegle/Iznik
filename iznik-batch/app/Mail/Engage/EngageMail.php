<?php

namespace App\Mail\Engage;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class EngageMail extends MjmlMailable
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $recipientEmail,
        public readonly string $emailSubject,
        public readonly string $template,
        public readonly int $engageId,
        public readonly string $unsubscribeUrl,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        return $this->emailSubject;
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
        $html = view("emails.engage.{$this->template}", [
            'name'           => $this->recipientName,
            'email'          => $this->recipientEmail,
            'engageId'       => $this->engageId,
            'unsubscribeUrl' => $this->unsubscribeUrl,
        ])->render();

        return $this->html($html);
    }
}
