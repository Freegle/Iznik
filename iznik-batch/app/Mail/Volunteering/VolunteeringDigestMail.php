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
        public readonly array $volunteerings,
        public readonly string $unsubscribeUrl,
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
        $userSite = config('freegle.sites.user');

        return $this->mjmlView('emails.mjml.volunteering.digest', [
            'groupName'      => $this->groupName,
            'volunteerings'  => $this->volunteerings,
            'userSite'       => $userSite,
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'email'          => $this->recipientEmail,
        ]);
    }
}
