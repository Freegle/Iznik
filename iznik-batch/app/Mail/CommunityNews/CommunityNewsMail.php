<?php

namespace App\Mail\CommunityNews;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/**
 * The weekly Community News digest email for one area, to one member.
 *
 * Reuses the shared MJML partials (head/header/footer) so branding and the
 * unsubscribe/settings links match every other Freegle email. Opt-out is the
 * existing "Newsletters & stories" preference (users.newslettersallowed),
 * enforced by CommunityNewsEmailService when selecting recipients.
 */
class CommunityNewsMail extends MjmlMailable
{
    /**
     * @param  array<int, array{title:string, blurb:string, url:?string, source:?string}>  $items
     */
    public function __construct(
        public readonly int    $userId,
        public readonly string $recipientName,
        public readonly string $recipientEmail,
        public readonly string $areaName,
        public readonly string $intro,
        public readonly array  $items,
        public readonly string $findUrl,
        public readonly string $unsubscribeUrl,
        public readonly string $settingsUrl,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        return 'Community News for ' . $this->areaName;
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

    public function build(): static
    {
        $preview = Str::limit(trim(strip_tags($this->intro)), 90);

        return $this->mjmlView('emails.mjml.community-news.news', [
            'name'           => $this->recipientName,
            'email'          => $this->recipientEmail,
            'areaName'       => $this->areaName,
            'intro'          => $this->intro,
            'items'          => $this->items,
            'findUrl'        => $this->findUrl,
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'settingsUrl'    => $this->settingsUrl,
            'preview'        => $preview,
        ], 'emails.text.community-news.news');
    }
}
