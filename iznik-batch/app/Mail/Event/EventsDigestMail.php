<?php

namespace App\Mail\Event;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EventsDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $groupName,
        public readonly string $summary,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr'),
                $this->groupName
            ),
            subject: "[{$this->groupName}] Community Event Roundup",
        );
    }

    public function build(): static
    {
        return $this->text('emails.text.event.events-digest');
    }
}
