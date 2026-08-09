<?php

namespace App\Mail\Partnerships;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells the Partnerships team that a council sponsorship is coming up for renewal.
 *
 * One mail per partnership rather than a digest: each one is a separate conversation with a
 * separate council, and a single mail per deal is what you want to forward, reply to and
 * chase against.
 */
class SponsorshipExpiringMail extends Mailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $fromEmail,
        public readonly string $partnershipName,
        public readonly string $authorityName,
        public readonly string $endDate,
        public readonly int $daysLeft,
        public readonly float $amount,
        public readonly int $groupCount,
        public readonly ?string $contactName,
        public readonly ?string $contactEmail,
        public readonly string $modToolsUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromEmail, 'Freegle'),
            to: [new Address($this->recipientEmail, 'Freegle Partnerships')],
            subject: sprintf('Sponsorship renewal due: %s (ends %s)', $this->partnershipName, $this->endDate),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.text.partnerships.sponsorship-expiring',
            with: [
                'partnershipName' => $this->partnershipName,
                'authorityName' => $this->authorityName,
                'endDate' => $this->endDate,
                'daysLeft' => $this->daysLeft,
                'amount' => $this->amount,
                'groupCount' => $this->groupCount,
                'contactName' => $this->contactName,
                'contactEmail' => $this->contactEmail,
                'modToolsUrl' => $this->modToolsUrl,
            ],
        );
    }
}
