<?php

namespace App\Mail\Group;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class ClosedGroupReminderMail extends MjmlMailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $groupName,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        return 'Reminder: Your Freegle group is currently closed';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.geeks_addr', 'geeks@ilovefreegle.org'),
                config('freegle.branding.name', 'Freegle')
            ),
            to: [new Address($this->recipientEmail)],
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        $modSite = config('freegle.sites.mod', 'https://modtools.org');

        return $this->mjmlView('emails.mjml.group.closed-reminder', [
            'groupName' => $this->groupName,
            'modSite'   => $modSite,
            'email'     => $this->recipientEmail,
        ]);
    }
}
