<?php

namespace App\Mail\Stories;

use App\Mail\Contracts\DescribesMemberContent;
use App\Mail\MjmlMailable;
use App\Services\UnsubscribeService;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class StoriesNewsletterMail extends MjmlMailable implements DescribesMemberContent
{
    public function __construct(
        public readonly int    $userId,
        public readonly string $recipientName,
        public readonly string $recipientEmail,
        public readonly array  $stories,
        public readonly string $headerImageUrl,
        public readonly string $tellUrl,
        public readonly string $giveUrl,
        public readonly string $askUrl,
        public readonly string $previewText,
        public readonly string $unsubscribeUrl,
        public readonly string $settingsUrl,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        return 'Lovely stories from other freeglers!';
    }

    protected function unsubscribeType(): ?string
    {
        return UnsubscribeService::TYPE_NEWSLETTER;
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->userId;
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
     * Each story names its author's userid (since the additive fix alongside this); an
     * entry built without one (e.g. an older test fixture) simply contributes nothing to
     * users. There is no bucket in about() for a users_stories row itself, only for the
     * people named in it - filter-spool removes this mail once every author it names is
     * now a spammer, the same as any other member-content mail (plan section 11.8).
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
        return $this->mjmlView('emails.mjml.stories.newsletter', [
            'name'           => $this->recipientName,
            'email'          => $this->recipientEmail,
            'stories'        => $this->stories,
            'headerImageUrl' => $this->headerImageUrl,
            'tellUrl'        => $this->tellUrl,
            'giveUrl'        => $this->giveUrl,
            'askUrl'        => $this->askUrl,
            'previewText'    => $this->previewText,
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'settingsUrl'    => $this->settingsUrl,
        ], 'emails.text.stories.newsletter');
    }
}
