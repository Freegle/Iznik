<?php

namespace App\Mail\Chat;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class ChatReviewSummaryMail extends MjmlMailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly int $total,
        public readonly string $summary,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        return "Summary of chat messages waiting for review ({$this->total} total)";
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.support_addr'),
                config('freegle.branding.name')
            ),
            to: [new Address($this->recipientEmail)],
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        return $this->mjmlView('emails.mjml.chat.review-summary', [
            'total'   => $this->total,
            'summary' => $this->summary,
            'email'   => $this->recipientEmail,
        ]);
    }
}
