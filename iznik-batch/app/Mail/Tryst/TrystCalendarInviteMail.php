<?php

namespace App\Mail\Tryst;

use App\Mail\Contracts\DescribesMemberContent;
use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class TrystCalendarInviteMail extends MjmlMailable implements DescribesMemberContent
{
    /**
     * @param int|null $otherUserId The other party to the handover named in the title
     *                              (not the recipient), when known - older callers/tests
     *                              may omit it (plan section 11.8).
     */
    public function __construct(
        public readonly string $title,
        public readonly string $calendarLink,
        public readonly int $recipientUserId,
        public readonly string $recipientName,
        public readonly string $recipientEmail,
        public readonly ?int $otherUserId = null,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        return "Please add to your calendar - {$this->title}";
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org'),
                config('freegle.branding.name', 'Freegle')
            ),
            to: [new Address($this->recipientEmail, $this->recipientName)],
            subject: $this->getSubject(),
        );
    }

    /**
     * Transactional - a handover they arranged - so it carries no List-Unsubscribe.
     */
    protected function unsubscribeType(): ?string
    {
        return null;
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->recipientUserId;
    }

    /**
     * Names the other party to the handover this invite's title names - not the
     * recipient, who arranged it themselves (plan section 11.8). There is no
     * bucket for the tryst/handover itself, only for the people named in it;
     * filter-spool removes this mail once that other party is now a spammer.
     */
    public function about(): array
    {
        return [
            'chatmessages' => [],
            'messages' => [],
            'newsfeed' => [],
            'users' => $this->otherUserId !== null ? [$this->otherUserId] : [],
        ];
    }

    public function build(): static
    {
        $userSite = config('freegle.sites.user', 'https://www.ilovefreegle.org');

        return $this->mjmlView('emails.mjml.tryst.calendar-invite', [
            'title'          => $this->title,
            'calendarLink'   => $this->calendarLink,
            'email'          => $this->recipientEmail,
            'settingsUrl'    => $userSite . '/settings',
            'unsubscribeUrl' => $userSite . '/unsubscribe/' . $this->recipientUserId,
        ]);
    }
}
