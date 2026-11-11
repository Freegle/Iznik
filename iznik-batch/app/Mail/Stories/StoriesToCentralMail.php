<?php

namespace App\Mail\Stories;

use App\Mail\Contracts\DescribesMemberContent;
use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class StoriesToCentralMail extends MjmlMailable implements DescribesMemberContent
{
    /**
     * Transactional - an internal report to volunteers - so it carries no List-Unsubscribe.
     */
    protected function unsubscribeType(): ?string
    {
        return null;
    }

    public function __construct(
        public readonly array $stories,
        public readonly string $previewText,
        public readonly string $voteUrl,
        public readonly string $emailSubject,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        return $this->emailSubject;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.geeks_addr', 'geeks@ilovefreegle.org'),
                config('freegle.branding.name', 'Freegle')
            ),
            to: [new Address(config('freegle.mail.central_mail_to', 'central@ilovefreegle.org'))],
            subject: $this->getSubject(),
        );
    }

    /**
     * Each candidate story names its author's userid (since the additive fix alongside
     * this); an entry built without one simply contributes nothing to users. As with
     * StoriesNewsletterMail, there is no bucket for the story itself, only for the
     * people it names (plan section 11.8).
     */
    public function about(): array
    {
        $users = [];
        foreach ($this->stories as $story) {
            if (isset($story['userid'])) {
                $users[] = (int) $story['userid'];
            }
        }

        return [
            'chatmessages' => [],
            'messages' => [],
            'newsfeed' => [],
            'users' => array_values(array_unique($users)),
        ];
    }

    public function build(): static
    {
        return $this->mjmlView('emails.mjml.stories.central', [
            'stories'     => $this->stories,
            'previewText' => $this->previewText,
            'voteUrl'     => $this->voteUrl,
        ]);
    }
}
