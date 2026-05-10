<?php

namespace App\Mail\Volunteering;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VolunteeringDigestMail extends Mailable
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
            subject: "[{$this->groupName}] Volunteer Opportunity Roundup",
        );
    }

    public function build(): static
    {
        return $this->text('emails.text.volunteering.digest');
    }
}
