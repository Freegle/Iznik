<?php

namespace App\Mail\Event;

use App\Mail\MjmlMailable;
use App\Mail\Traits\TrackableEmail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class EventsDigestMail extends MjmlMailable
{
    use TrackableEmail;

    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $groupName,
        public readonly array $events,
        public readonly string $unsubscribeUrl,
        public readonly ?int $userId = null,
    ) {
        parent::__construct();

        $this->initTracking(
            'EventsDigest',
            $this->recipientEmail,
            $this->userId,
            null,
            $this->getSubject(),
            ['group' => $this->groupName, 'event_count' => count($this->events)]
        );
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
