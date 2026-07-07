<?php

namespace App\Mail\Event;

use App\Mail\MjmlMailable;
use App\Mail\Traits\TrackableEmail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class EventsDigestMail extends MjmlMailable
{
    use TrackableEmail;

    /**
     * @param array $events Deduplicated events across all the recipient's
     *                      event-enabled groups. Each carries a 'groups' array
     *                      of ['name' => , 'url' => ] pairs for the recipient's
     *                      groups it was posted on.
     */
    public function __construct(
        public readonly string $recipientEmail,
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
            ['event_count' => count($this->events)]
        );
    }

    protected function getSubject(): string
    {
        return 'Community events near you';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org'),
                config('freegle.site_name', 'Freegle')
            ),
            to: [new Address($this->recipientEmail)],
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        $userSite = config('freegle.sites.user');

        return $this->mjmlView('emails.mjml.event.events-digest', [
            'events'         => $this->events,
            'userSite'       => $userSite,
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'email'          => $this->recipientEmail,
        ]);
    }
}
