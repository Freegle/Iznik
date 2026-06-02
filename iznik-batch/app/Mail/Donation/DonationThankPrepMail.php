<?php

namespace App\Mail\Donation;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Card-per-donation digest for the person composing thank-you replies.
 * Separate from {@see DonationSummaryMail} — that one is the simple finance
 * status table; this one carries the full donor context (history, GA,
 * memberships, mod notes, chat snippets, deep links into Modtools).
 */
class DonationThankPrepMail extends MjmlMailable
{
    /**
     * @param  array<int, array<string, mixed>>  $cards Per-donation context blocks
     */
    public function __construct(
        public readonly string $recipientEmail,
        public readonly array $cards,
        public readonly float $total,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        $totalFormatted = number_format($this->total, 2);
        $n = count($this->cards);
        $noun = $n === 1 ? 'donor' : 'donors';
        return "Donations needing thanks: {$n} {$noun}, £{$totalFormatted}";
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
        return $this->mjmlView('emails.mjml.donation.thank-prep', [
            'cards' => $this->cards,
            'total' => $this->total,
            'email' => $this->recipientEmail,
        ]);
    }
}
