<?php

namespace App\Mail\Newsfeed;

use App\Mail\Contracts\DescribesMemberContent;
use App\Mail\MjmlMailable;
use App\Models\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use App\Services\UnsubscribeService;

/**
 * The newsfeed ("chitchat") digest email.
 *
 * Mirrors the legacy V1 PHP Newsfeed::digest() email: subject
 * is a snippet of the first item plus a count "from your neighbours[ in X, Y]".
 */
class NewsfeedDigestMail extends MjmlMailable implements DescribesMemberContent
{
    /**
     * @param  list<array{id?: int, type: string, text: string, author: string, authorid?: int, replies: array}>  $items
     * @param  list<string>  $locations  Poster location names for the subject clause.
     */
    public function __construct(
        public readonly User $user,
        public readonly string $recipientEmail,
        public readonly array $items,
        public readonly string $snippet,
        public readonly array $locations = [],
        public readonly ?string $readUrl = null,
        public readonly ?string $settingsUrl = null,
    ) {
        parent::__construct();
    }

    protected function unsubscribeType(): ?string
    {
        return UnsubscribeService::TYPE_NOTIFICATIONS;
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->user->id;
    }

    protected function getSubject(): string
    {
        $count = count($this->items);
        $plural = $count !== 1 ? 's' : '';

        // V1: '"<snippet>" (<n> conversation(s) from your neighbours[ in X, Y])'.
        $subject = '"' . $this->snippet . '" (' . $count . ' conversation' . $plural . ' from your neighbours';
        if (! empty($this->locations)) {
            $subject .= ' in ' . implode(', ', $this->locations);
        }
        $subject .= ')';

        // V1 collapses an empty snippet's doubled quotes.
        return str_replace('""', '"', $subject);
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

        return $this->mjmlView('emails.mjml.newsfeed.digest', [
            'items' => $this->items,
            'count' => count($this->items),
            'readUrl' => $this->readUrl ?: $userSite . '/chitchat?src=newsfeeddigest',
            'settingsUrl' => $this->settingsUrl ?: $userSite . '/settings?src=newsfeeddigest',
            'userSite' => $userSite,
            'email' => $this->recipientEmail,
        ]);
    }

    /**
     * One row per item (NewsfeedDigestService::buildItems()) carries its own newsfeed id and
     * author id; an item built some other way (e.g. a test fixture) without those keys simply
     * contributes nothing here, rather than being rejected.
     */
    public function about(): array
    {
        $newsfeed = [];
        $users = [];
        foreach ($this->items as $item) {
            if (isset($item['id'])) {
                $newsfeed[] = (int) $item['id'];
            }
            if (isset($item['authorid'])) {
                $users[] = (int) $item['authorid'];
            }
        }

        return [
            'chatmessages' => [],
            'messages' => [],
            'newsfeed' => array_values(array_unique($newsfeed)),
            'users' => array_values(array_unique($users)),
        ];
    }
}
