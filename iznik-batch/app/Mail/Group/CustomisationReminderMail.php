<?php

namespace App\Mail\Group;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class CustomisationReminderMail extends MjmlMailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $groupName,
        public readonly string $missing,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        return "Reminder - ways to make {$this->groupName} more welcoming";
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
        $modSite = config('freegle.sites.mod', 'https://modtools.org');
        $mentorsAddr = config('freegle.mail.mentors_addr', 'mentors@ilovefreegle.org');

        return $this->mjmlView('emails.mjml.group.customisation-reminder', [
            'groupName'   => $this->groupName,
            'missing'     => $this->missing,
            'modSite'     => $modSite,
            'mentorsAddr' => $mentorsAddr,
            'email'       => $this->recipientEmail,
        ]);
    }
}
