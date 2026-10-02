<?php

namespace App\Mail\Partnerships;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells the Partnerships team that a council sponsorship is coming up for renewal, or has
 * ended without one.
 *
 * One mail per partnership rather than a digest: each one is a separate conversation with a
 * separate council, and a single mail per deal is what you want to forward, reply to and
 * chase against.
 */
class SponsorshipExpiringMail extends MjmlMailable
{
    use LoggableEmail;

    /**
     * Sent to the team's own address about their own work, so it carries no List-Unsubscribe.
     */
    protected function unsubscribeType(): ?string
    {
        return null;
    }

    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $fromEmail,
        public readonly string $partnershipName,
        public readonly string $authorityName,
        public readonly string $endDate,
        public readonly int $daysLeft,
        public readonly float $amount,
        public readonly int $groupCount,
        /** @var array<int, array{name: ?string, email: ?string, role: string}> Everyone at the council. */
        public readonly array $contacts,
        public readonly string $modToolsUrl,
        // The deal has already ended with nothing agreed to follow it.
        public readonly bool $ended = false,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromEmail, 'Freegle'),
            to: [new Address($this->recipientEmail, 'Freegle Partnerships')],
            subject: $this->getSubject(),
        );
    }

    protected function getSubject(): string
    {
        return $this->ended
            ? sprintf('Sponsorship ended without renewal: %s (ended %s)', $this->partnershipName, $this->endDate)
            : sprintf('Sponsorship renewal due: %s (ends %s)', $this->partnershipName, $this->endDate);
    }

    public function build(): static
    {
        return $this->mjmlView(
            'emails.mjml.partnerships.sponsorship-expiring',
            [
                'partnershipName' => $this->partnershipName,
                'authorityName' => $this->authorityName,
                'endDate' => $this->endDate,
                'daysLeft' => $this->daysLeft,
                'amount' => $this->amount,
                'groupCount' => $this->groupCount,
                'contacts' => $this->contacts,
                'modToolsUrl' => $this->modToolsUrl,
                'ended' => $this->ended,
                'email' => $this->recipientEmail,
            ],
            'emails.text.partnerships.sponsorship-expiring'
        )->applyLogging('SponsorshipExpiring');
    }
}
