<?php

namespace App\Mail\Donation;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * HTML daily donation summary sent to the fundraising address.
 *
 * V1 parity: donations_email.php
 */
class DonationSummaryMail extends MjmlMailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $htmlContent,
        public readonly float $total,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        $totalFormatted = number_format($this->total, 2);
        return "Donation total today £{$totalFormatted}";
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
        return $this->mjmlView('emails.mjml.donation.daily-summary', [
            'htmlContent' => $this->htmlContent,
            'total'       => $this->total,
            'email'       => $this->recipientEmail,
        ]);
    }
}
