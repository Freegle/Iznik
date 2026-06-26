<?php

namespace App\Mail\Newsfeed;

use App\Mail\MjmlMailable;
use App\Models\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The newsfeed ("chitchat") digest email.
 *
 * Mirrors iznik-server Newsfeed::digest() email (Newsfeed.php:935-968): subject
 * is a snippet of the first item plus a count "from your neighbours[ in X, Y]".
 */
class NewsfeedDigestMail extends MjmlMailable
{
    /**
     * @param  list<array{type: string, text: string, author: string, replies: array}>  $items
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
}
