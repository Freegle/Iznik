<?php

namespace App\Mail\Volunteering;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class VolunteeringDigestMail extends MjmlMailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $groupName,
        public readonly string $summary,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        return "[{$this->groupName}] Volunteer Opportunity Roundup";
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org'),
                $this->groupName
            ),
            to: [new Address($this->recipientEmail)],
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        return $this->mjmlView('emails.mjml.volunteering.digest', [
            'groupName' => $this->groupName,
            'summary'   => $this->summary,
            'email'     => $this->recipientEmail,
        ]);
    }
}
