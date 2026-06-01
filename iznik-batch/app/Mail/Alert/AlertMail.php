<?php

namespace App\Mail\Alert;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * System alert email to group moderators / owners / group contact address.
 *
 * Mirrors iznik-server mailtemplates/alert.php (alert_tpl): standard Freegle
 * header/footer styling, the alert subject and HTML body, an optional "I got
 * this" confirmation button (askclick), a 1x1 web beacon for open tracking, and
 * the "sent to all groups" note for global alerts.
 */
class AlertMail extends MjmlMailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $recipientName,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly string $subjectLine,
        public readonly string $htmlBody,
        public readonly string $textBody,
        public readonly ?int $trackId = null,
        public readonly bool $askClick = false,
        public readonly bool $global = false,
        public readonly ?string $groupName = null,
        public readonly ?string $beaconBase = null,
        public readonly ?int $recipientUserId = null,
    ) {
        parent::__construct();
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->recipientUserId;
    }

    protected function getSubject(): string
    {
        return $this->subjectLine;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            to: [new Address($this->recipientEmail, $this->recipientName)],
            subject: $this->subjectLine,
        );
    }

    public function build(): static
    {
        $userSite = config('freegle.sites.user', 'https://www.ilovefreegle.org');
        $base = rtrim($this->beaconBase ?: $userSite, '/');

        // V1 beacon ('Read') and click-confirmation ('Clicked') endpoints,
        // keyed on the alerts_tracking row id.
        $beaconUrl = $this->trackId ? "{$base}/beacon/{$this->trackId}" : null;
        $clickUrl = ($this->askClick && $this->trackId) ? "{$base}/alert/viewed/{$this->trackId}" : null;

        return $this->mjmlView('emails.mjml.alert.alert', [
            'subject' => $this->subjectLine,
            'htmlBody' => $this->htmlBody,
            'toName' => $this->recipientName,
            'groupName' => $this->groupName,
            'global' => $this->global,
            'beaconUrl' => $beaconUrl,
            'clickUrl' => $clickUrl,
            'userSite' => $userSite,
            'email' => $this->recipientEmail,
        ]);
    }
}
