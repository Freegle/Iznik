<?php

namespace App\Mail\Donation;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * HTML daily donation summary sent to the fundraising address.
 *
 * V1 parity: donations_email.php
 */
class DonationSummaryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $htmlContent,
        public readonly float $total,
    ) {
    }

    public function envelope(): Envelope
    {
        $totalFormatted = number_format($this->total, 2);

        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr'),
                'Freegle'
            ),
            subject: "Donation total today £{$totalFormatted}",
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->htmlContent);
    }
}
