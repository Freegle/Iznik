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
        $modSite = config('freegle.sites.mod', 'https://modtools.org');

        return $this->mjmlView('emails.mjml.newsfeed.mod-notif', [
            'posts'       => $this->posts,
            'count'       => count($this->posts),
            'chitchatUrl' => "{$modSite}/chitchat",
            'email'       => $this->recipientEmail,
        ]);
    }
}
