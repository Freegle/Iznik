<?php

namespace App\Mail\Chat;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ChatReviewSummaryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly int $total,
        public readonly string $summary,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.support_addr'),
                config('freegle.branding.name')
            ),
            subject: "Summary of chat messages waiting for review ({$this->total} total)",
        );
    }

    public function build(): static
    {
        return $this->text('emails.text.chat.review-summary');
    }
}
