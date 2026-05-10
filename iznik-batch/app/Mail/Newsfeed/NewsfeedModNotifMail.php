<?php

namespace App\Mail\Newsfeed;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class NewsfeedModNotifMail extends MjmlMailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly int $count,
        public readonly string $summary,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        $plural = $this->count !== 1 ? 's' : '';
        return "{$this->count} chitchat post{$plural} from your members";
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

        return $this->mjmlView('emails.mjml.newsfeed.mod-notif', [
            'count'   => $this->count,
            'summary' => $this->summary,
            'modSite' => $modSite,
            'email'   => $this->recipientEmail,
        ]);
    }
}
