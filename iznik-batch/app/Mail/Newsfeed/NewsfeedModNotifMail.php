<?php

namespace App\Mail\Newsfeed;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class NewsfeedModNotifMail extends MjmlMailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly array $posts,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        $count = count($this->posts);
        $plural = $count !== 1 ? 's' : '';
        return "{$count} chitchat post{$plural} from your members";
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
        $userSite = config('freegle.sites.user', 'https://www.ilovefreegle.org');

        return $this->mjmlView('emails.mjml.newsfeed.mod-notif', [
            'posts'    => $this->posts,
            'count'    => count($this->posts),
            'userSite' => $userSite,
            'email'    => $this->recipientEmail,
        ]);
    }
}
