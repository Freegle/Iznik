<?php

namespace App\Mail\Digest;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Auto-response sent when someone replies to a digest email.
 *
 * Digest emails are sent from noreply@ and contain multiple posts.
 * When a user replies to one, we send this friendly notice explaining
 * how to reply to specific posts using the buttons/links in the email.
 */
class DigestReplyNotice extends MjmlMailable
{
    use LoggableEmail;
    use TrackableEmail;

    public string $userSite;

    public function __construct(
        protected string $recipientEmail,
        protected ?string $recipientName = null,
        protected ?int $recipientUserId = null
    ) {
        parent::__construct();

        $this->userSite = config('freegle.sites.user');

        $this->initTracking(
            'DigestReplyNotice',
            $this->recipientEmail,
            $this->recipientUserId,
            null,
            $this->getSubject()
        );
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->recipientUserId;
    }

    public function build(): static
    {
        $settingsUrl = $this->trackedUrl($this->userSite . '/settings', 'footer_settings', 'settings');
        $browseUrl = $this->trackedUrl($this->userSite . '/browse', 'browse_cta', 'browse');

        return $this->mjmlView('emails.mjml.digest.reply-notice', array_merge([
            'recipientName' => $this->recipientName,
            'recipientEmail' => $this->recipientEmail,
            'settingsUrl' => $settingsUrl,
            'browseUrl' => $browseUrl,
            'userSite' => $this->userSite,
        ], $this->getTrackingData()), 'emails.text.digest.reply-notice')
            ->to($this->recipientEmail)
            ->applyLogging('DigestReplyNotice');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr'),
                config('freegle.branding.name')
            ),
            subject: $this->getSubject(),
        );
    }

    protected function getSubject(): string
    {
        return 'How to reply to posts on Freegle';
    }
}
