<?php

namespace App\Mail\Chat;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ChatReviewPendingMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $groupName,
        public readonly int $count,
    ) {
    }

    public function envelope(): Envelope
    {
        $plural = $this->count !== 1 ? 's' : '';
        return new Envelope(
            from: new Address(
                config('freegle.mail.support_addr'),
                config('freegle.branding.name')
            ),
            subject: "{$this->count} message{$plural} between members waiting for your review on {$this->groupName}",
        );
    }

    public function build(): static
    {
        return $this->text('emails.text.chat.review-pending');
    }
}
