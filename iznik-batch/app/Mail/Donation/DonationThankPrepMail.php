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
     * Transactional - an internal thank-you preparation report - so it carries no List-Unsubscribe.
     */
    protected function unsubscribeType(): ?string
    {
        return null;
    }

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

        // Normal case since the per-donation split: one card per email, with
        // the donor's name, email address and amount in the subject so each
        // donor is an identifiable thread in the thanker's inbox.
        if (count($this->cards) === 1) {
            $card     = $this->cards[0];
            $donation = $card['donation'] ?? [];
            $name     = trim((string) (($card['user']['displayName'] ?? '') ?: ($donation['payerName'] ?? '')));
            $email    = (string) (($card['aliases'][0] ?? '') ?: ($donation['payer'] ?? ''));
            if ($name === '' || $name === 'Unknown') {
                $name = $email !== '' ? $email : 'Unknown donor';
            }

            return "Donation thanks: {$name} ({$email}) - £{$totalFormatted}";
        }

        // Legacy multi-card digest shape (kept for safety; no live caller).
        $n = count($this->cards);
        return "Donations needing thanks: {$n} donors, £{$totalFormatted}";
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
