<?php

namespace App\Mail\Event;

use App\Mail\Concerns\BulkRenderable;
use App\Mail\MjmlMailable;
use App\Mail\Traits\TrackableEmail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class EventsDigestMail extends MjmlMailable implements BulkRenderable
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

    /**
     * All members of one group's events-digest send share the same events list
     * and group name. Per-recipient body variation: footer email and the
     * unsubscribe URL (which embeds the recipient's email).
     */
    public function shapeKey(): string
    {
        return 'events-digest-'.sha1(
            $this->groupName.'|'.json_encode($this->events, JSON_THROW_ON_ERROR)
        );
    }

    public function bulkTemplate(): string
    {
        return 'emails.mjml.event.events-digest';
    }

    public function bulkData(): array
    {
        return [
            'groupName'      => $this->groupName,
            'events'         => $this->events,
            'userSite'       => config('freegle.sites.user'),
            'unsubscribeUrl' => $this->ph('unsubscribeUrl'),
            'email'          => $this->ph('recipientEmail'),
        ];
    }

    public function mergeVars(): array
    {
        return [
            'recipientEmail' => $this->recipientEmail,
            'unsubscribeUrl' => $this->unsubscribeUrl,
        ];
    }
}
