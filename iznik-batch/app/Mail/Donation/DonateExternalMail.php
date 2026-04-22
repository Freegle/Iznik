<?php

namespace App\Mail\Donation;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Notification email sent to info@ilovefreegle.org when a donation needing a
 * manual thank-you is recorded (external bank transfer, PayPal, or Stripe).
 *
 * Matches the three legacy V1 paths:
 *   - donations.php PUT       → "via an external donation" (bank transfer)
 *   - donateipn.php (PayPal)  → "via PayPal Donate"
 *   - stripeipn.php           → "via Stripe"
 */
class DonateExternalMail extends MjmlMailable
{
    use LoggableEmail;

    public const SOURCE_EXTERNAL = 'external';
    public const SOURCE_PAYPAL = 'paypal';
    public const SOURCE_STRIPE = 'stripe';

    public function __construct(
        public string $userName,
        public int $userId,
        public string $userEmail,
        public float $amount,
        public string $source = self::SOURCE_EXTERNAL,
    ) {
        parent::__construct();
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

    /**
     * Human-readable channel phrase used in subject and body.
     */
    public function getChannelPhrase(): string
    {
        return match ($this->source) {
            self::SOURCE_PAYPAL => 'PayPal Donate',
            self::SOURCE_STRIPE => 'Stripe',
            default => 'an external donation',
        };
    }

    protected function getSubject(): string
    {
        $channel = $this->getChannelPhrase();

        return "{$this->userName} ({$this->userEmail}) donated £{$this->amount} via {$channel}. Please can you thank them?";
    }

    public function build(): static
    {
        $infoAddr = config('freegle.mail.info_addr');
        $ccAddr = config('freegle.mail.donation_cc_addr');

        $mailable = $this->mjmlView(
            'emails.mjml.donation.donate-external',
            [
                'userName' => $this->userName,
                'userId' => $this->userId,
                'userEmail' => $this->userEmail,
                'amount' => $this->amount,
                'channel' => $this->getChannelPhrase(),
            ]
        )->to($infoAddr)
            ->applyLogging('DonateExternal');

        if ($ccAddr) {
            $mailable->cc($ccAddr);
        }

        return $mailable;
    }
}
