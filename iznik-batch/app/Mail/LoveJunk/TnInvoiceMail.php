<?php

namespace App\Mail\LoveJunk;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class TnInvoiceMail extends MjmlMailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $tnAmount,
        public readonly string $start,
        public readonly string $end,
        public readonly int $tnPercent,
        public readonly string $treasurerAddr,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        return 'Please raise an invoice';
    }

    public function envelope(): Envelope
    {
        // V1 (lovejunk_tn_invoice.php) CC'd the audit log address.
        $cc = config('freegle.mail.donation_cc_addr');

        return new Envelope(
            from: new Address(
                config('freegle.mail.geeks_addr', 'geeks@ilovefreegle.org'),
                config('freegle.branding.name', 'Freegle')
            ),
            to: [new Address($this->recipientEmail)],
            cc: $cc ? [new Address($cc)] : [],
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        return $this->mjmlView('emails.mjml.lovejunk.tn-invoice', [
            'tnAmount'      => $this->tnAmount,
            'start'         => $this->start,
            'end'           => $this->end,
            'tnPercent'     => $this->tnPercent,
            'treasurerAddr' => $this->treasurerAddr,
            'email'         => $this->recipientEmail,
        ]);
    }
}
