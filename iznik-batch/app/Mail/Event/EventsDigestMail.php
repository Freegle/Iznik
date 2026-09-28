<?php

namespace App\Mail\Event;

use App\Mail\Contracts\DescribesMemberContent;
use App\Mail\MjmlMailable;
use App\Mail\Traits\TrackableEmail;
use App\Services\UnsubscribeService;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class EventsDigestMail extends MjmlMailable implements DescribesMemberContent
{
    use TrackableEmail;

    protected function unsubscribeType(): ?string
    {
        return UnsubscribeService::TYPE_EVENTS;
    }

    /**
     * @param array $events Deduplicated events across all the recipient's
     *                      event-enabled groups. Each carries a 'groups' array
     *                      of ['name' => , 'url' => ] pairs for the recipient's
     *                      groups it was posted on, and a 'userid' naming its
     *                      poster where known (see about()).
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

    /**
     * Each event names its poster's userid (since the additive fix alongside
     * this); an entry built without one (e.g. an older test fixture) simply
     * contributes nothing to users. There is no bucket in about() for a
     * community event itself, only for the people named in it - filter-spool
     * removes this mail once every poster it names is now a spammer, the same
     * as any other member-content mail (plan section 11.8).
     */
    public function about(): array
    {
        $users = [];
        foreach ($this->events as $event) {
            if (isset($event['userid'])) {
                $users[] = (int) $event['userid'];
            }
        }

        return [
            'chatmessages' => [],
            'messages' => [],
            'newsfeed' => [],
            'users' => array_values(array_unique($users)),
        ];
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
