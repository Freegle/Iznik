<?php

namespace App\Mail\Volunteering;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Is this volunteer opportunity still active?" renewal request.
 *
 * Ported from iznik-server mailtemplates/volunteerrenew.php +
 * Volunteering::askRenew(). Sent to the owner of a dateless opportunity that is
 * approaching expiry; the "Let us know" button is an auto-login link to
 * /volunteering/{id} where the owner can renew (Go API sets renewed=NOW(),
 * expired=0).
 */
class VolunteeringRenewMail extends MjmlMailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $title,
        public readonly string $renewUrl,
        public readonly string $groupName,
        public readonly ?int $userId = null,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        // Matches iznik-server Volunteering::askRenew(): "Regarding: {title}".
        return 'Regarding: ' . $this->title;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org'),
                config('freegle.site_name', 'Freegle')
            ),
            to: [new Address($this->recipientEmail)],
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        // Attach the standard X-Freegle-* tracking and RFC 8058 List-Unsubscribe
        // headers (the latter keyed off getRecipientUserId() below).
        $this->configureMessage();

        return $this->mjmlView('emails.mjml.volunteering.renew', [
            'title'          => $this->title,
            'renewUrl'       => $this->renewUrl,
            'groupName'      => $this->groupName,
            'email'          => $this->recipientEmail,
            'unsubscribeUrl' => config('freegle.sites.user') . '/unsubscribe',
        ]);
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->userId;
    }
}
