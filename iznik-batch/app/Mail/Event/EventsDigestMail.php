<?php

namespace App\Mail\Event;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class EventsDigestMail extends MjmlMailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $groupName,
        public readonly array $events,
        public readonly string $unsubscribeUrl,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        return "[{$this->groupName}] Community Event Roundup";
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

        return $this->mjmlView('emails.mjml.event.events-digest', [
            'groupName'      => $this->groupName,
            'events'         => $this->events,
            'userSite'       => $userSite,
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'email'          => $this->recipientEmail,
        ]);
    }
}
