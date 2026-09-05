<?php

namespace App\Mail\CommunityNews;

use App\Mail\MjmlMailable;
use App\Mail\Traits\TrackableEmail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;
use App\Services\UnsubscribeService;

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
    use TrackableEmail;

    /**
     * @param  array<int, array{title:string, blurb:string, url:?string, source:?string, image:?string}>  $items
     * @param  array{headline:string, story:string, name:string}|null  $story
     */
    public function __construct(
        public readonly int    $userId,
        public readonly string $recipientName,
        public readonly string $recipientEmail,
        public readonly string $areaName,
        public readonly string $intro,
        public readonly array  $items,
        public readonly string $askUrl,
        public readonly string $settingsUrl,
        public readonly ?array $story = null,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        return 'Community News near you';
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

    public function build(): static
    {
        $preview = Str::limit(trim(strip_tags($this->intro)), 90);

        // Engagement tracking: open pixel + per-link click redirects, keyed by
        // email_type=CommunityNews with the area in metadata. Item links get a
        // positional code (item_1…) so click analytics show WHICH story pulled.
        $this->initTracking(
            'CommunityNews',
            $this->recipientEmail,
            $this->userId ?: null,
            null,
            $this->getSubject(),
            ['area' => $this->areaName]
        );

        $items = array_values($this->items);
        foreach ($items as $i => $item) {
            if (!empty($item['url'])) {
                $items[$i]['url'] = $this->trackedUrl($item['url'], 'item_' . ($i + 1), 'item');
            }
        }

        return $this->mjmlView('emails.mjml.community-news.news', [
            'name'        => $this->recipientName,
            'email'       => $this->recipientEmail,
            'areaName'    => $this->areaName,
            'intro'       => $this->intro,
            'items'       => $items,
            'askUrl'     => $this->trackedUrl($this->askUrl, 'find_button', 'find'),
            'settingsUrl' => $this->trackedUrl($this->settingsUrl, 'footer_settings', 'settings'),
            'story'       => $this->story,
            'preview'     => $preview,
            'trackingPixelMjml' => $this->getTrackingPixelMjml(),
        ], 'emails.text.community-news.news');
    }
}
