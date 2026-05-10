<?php

namespace App\Mail\Tryst;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class TrystCalendarInviteMail extends MjmlMailable
{
    public function __construct(
        public readonly string $title,
        public readonly string $calendarLink,
        public readonly int $recipientUserId,
        public readonly string $recipientName,
        public readonly string $recipientEmail,
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

    protected function getRecipientUserId(): ?int
    {
        return $this->recipientUserId;
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
