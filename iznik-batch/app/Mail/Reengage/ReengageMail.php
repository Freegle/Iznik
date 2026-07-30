<?php

namespace App\Mail\Reengage;

use App\Mail\MjmlMailable;
use App\Mail\Traits\TrackableEmail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Email;

/**
 * One email in the first-week onboarding tip sequence. Every day shares the one
 * 'tip' template; the per-day copy (and the local-volunteer sign-off) is resolved
 * up front by ReengageContentService and passed in as $content, so the Mailable
 * itself stays dumb and serialisable.
 *
 * Tracked: participates in the shared email_tracking system (open pixel + wrapped
 * links) so the re-engagement flow's opens/clicks/effectiveness are visible in
 * the sysadmin dashboard, joined back to the reengage row via the tracking id.
 *
 * Sets the RFC 8058 List-Unsubscribe headers using the KEYED one-click URL (not
 * the session-only /unsubscribe/{id} form), so a mail-client one-click POST from
 * Gmail/Yahoo — which carries no Freegle session — actually unsubscribes the user.
 */
class ReengageMail extends MjmlMailable
{
    use TrackableEmail;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $recipientEmail,
        public readonly string $emailSubject,
        public readonly string $template,
        public readonly int $userId,
        public readonly array $content = [],
    ) {
        parent::__construct();

        // Real sends (userId > 0) are tracked; previews (userId 0) are not, to
        // keep sample renders out of the effectiveness stats.
        if ($this->userId > 0) {
            $this->initTracking(
                'Reengage',
                $this->recipientEmail,
                $this->userId,
                null,
                $this->emailSubject,
                ['template' => $this->template],
            );
        }
    }

    /** Tracking row id, so the reengage row can join to opens/clicks. */
    public function getTrackingId(): ?int
    {
        return $this->tracking?->id;
    }

    protected function getSubject(): string
    {
        return $this->emailSubject;
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

    public function getEmailType(): string
    {
        return 'Reengage';
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->userId > 0 ? $this->userId : null;
    }

    /**
     * Use the keyed one-click unsubscribe URL for the RFC 8058 header so a
     * mail-client one-click POST (no session) actually works. The parent builds
     * a session-only /unsubscribe/{id} URL which silently no-ops for those POSTs.
     */
    protected function addListUnsubscribeHeaders(Email $message): void
    {
        $keyedUrl = $this->content['unsubscribeUrl'] ?? null;

        if (! $keyedUrl) {
            // No keyed URL available — fall back to the parent behaviour rather
            // than emit a header at all.
            parent::addListUnsubscribeHeaders($message);

            return;
        }

        $unsubscribeEmail = config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org');
        $headers = $message->getHeaders();

        $headers->addTextHeader(
            'List-Unsubscribe',
            "<mailto:{$unsubscribeEmail}?subject=unsubscribe>, <{$keyedUrl}>"
        );
        $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
    }

    public function build(): static
    {
        // Do the click/open tracking here and pass it into the view as plain
        // strings. NEVER put $this (the Mailable) into the view data: a Mailable
        // is a large, self-referential object and extracting it into the Blade
        // scope segfaults the renderer under PHP 8.4. So pre-wrap the links with
        // tracked redirects and hand the open pixel over as ready MJML.
        $content = $this->content;

        // Wrap every clickable link the template actually renders so the
        // onboarding funnel's click-through is measurable (opens come from the
        // pixel). The shared tip template renders exactly three: the per-day
        // primary button ($ctaUrl -> give/find/browse) and the footer
        // settings/unsubscribe links. Matches the digest/chat tracking
        // convention (position + action).
        //
        // IMPORTANT: wrap only this LOCAL $content copy — never $this->content.
        // addListUnsubscribeHeaders() reads $this->content['unsubscribeUrl'] to
        // build the RFC 8058 keyed one-click List-Unsubscribe HEADER, which must
        // stay the direct keyed URL; a tracked redirect there would break the
        // no-session one-click POST from Gmail/Yahoo.
        if (! empty($content['ctaUrl']) && is_string($content['ctaUrl'])) {
            // Keep the give/find/browse distinction in the click's action code.
            $slug = basename((string) parse_url($content['ctaUrl'], PHP_URL_PATH));
            $content['ctaUrl'] = $this->trackedUrl($content['ctaUrl'], 'primary_cta', $slug !== '' ? $slug : 'cta');
        }

        $footerLinks = [
            'settingsUrl'    => 'settings',
            'unsubscribeUrl' => 'unsubscribe',
        ];
        foreach ($footerLinks as $key => $action) {
            if (! empty($content[$key]) && is_string($content[$key])) {
                $content[$key] = $this->trackedUrl($content[$key], "footer_{$action}", $action);
            }
        }

        // Track click-through on the individual item cards too.
        if (! empty($content['offers']) && is_array($content['offers'])) {
            foreach ($content['offers'] as $i => $offer) {
                if (! empty($offer['url']) && is_string($offer['url'])) {
                    $content['offers'][$i]['url'] = $this->trackedUrl($offer['url'], 'offer', 'offer');
                }
            }
        }

        $data = array_merge([
            'name'              => $this->recipientName,
            'email'             => $this->recipientEmail,
            'trackingPixelMjml' => $this->getTrackingPixelMjml(),
        ], $content);

        $this->mjmlView(
            "emails.mjml.reengage.{$this->template}",
            $data,
            "emails.text.reengage.{$this->template}",
        );

        return $this->configureMessage();
    }
}
