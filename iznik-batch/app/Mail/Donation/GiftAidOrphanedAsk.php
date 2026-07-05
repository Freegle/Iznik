<?php

namespace App\Mail\Donation;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Models\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One-off ask to donors whose past donations were orphaned by member email-address
 * changes (and so never acknowledged), inviting them to give Gift Aid consent so we can
 * reclaim the 25%. Sent FROM the fundraising address (info@ilovefreegle.org) and signed
 * by Jacky, since it is a personal-feeling apology-and-ask rather than a system notice.
 * Fixed copy - no per-donation details are shown.
 */
class GiftAidOrphanedAsk extends MjmlMailable
{
    use LoggableEmail;
    use TrackableEmail;

    public User $user;

    public string $giftAidUrl;

    public string $settingsUrl;

    public function __construct(User $user)
    {
        parent::__construct();

        $this->user = $user;
        $this->giftAidUrl = config('freegle.sites.user').'/giftaid';
        $this->settingsUrl = config('freegle.sites.user').'/settings';

        $this->initTracking(
            'GiftAidOrphanedAsk',
            $this->user->email_preferred,
            $this->user->id ?? null,
            null,
            $this->getSubject(),
            []
        );
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->user->id ?? null;
    }

    public function build(): static
    {
        return $this->to($this->user->email_preferred, $this->user->displayname)
            ->subject($this->getSubject())
            ->mjmlView('emails.mjml.donation.giftaid-orphaned-ask', array_merge([
                'user' => $this->user,
                'giftAidUrl' => $this->trackedUrl($this->giftAidUrl, 'giftaid_button', 'giftaid'),
                'settingsUrl' => $this->trackedUrl($this->settingsUrl, 'footer_settings', 'settings'),
            ], $this->getTrackingData()), 'emails.text.donation.giftaid-orphaned-ask')
            ->applyLogging('GiftAidOrphanedAsk');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.info_addr'),
                config('freegle.branding.name')
            ),
            subject: $this->getSubject(),
        );
    }

    protected function getSubject(): string
    {
        return 'Your past Freegle donations, and a Gift Aid question';
    }
}
