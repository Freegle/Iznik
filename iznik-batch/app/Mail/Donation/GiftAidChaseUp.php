<?php

namespace App\Mail\Donation;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Models\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class GiftAidChaseUp extends MjmlMailable
{
    use LoggableEmail;
    use TrackableEmail;

    public User $user;

    public string $donationDate;

    public string $giftAidUrl;

    public string $settingsUrl;

    public function __construct(User $user, string $donationDate)
    {
        parent::__construct();

        $this->user = $user;
        $this->donationDate = $donationDate;
        $this->giftAidUrl = config('freegle.sites.user').'/giftaid';
        $this->settingsUrl = config('freegle.sites.user').'/settings';

        $this->initTracking(
            'GiftAidChaseUp',
            $this->user->email_preferred,
            $this->user->id ?? null,
            null,
            $this->getSubject(),
            ['donation_date' => $donationDate]
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
            ->mjmlView('emails.mjml.donation.giftaid-chaseup', array_merge([
                'user' => $this->user,
                'donationDate' => $this->donationDate,
                'giftAidUrl' => $this->trackedUrl($this->giftAidUrl, 'giftaid_button', 'giftaid'),
                'settingsUrl' => $this->trackedUrl($this->settingsUrl, 'footer_settings', 'settings'),
            ], $this->getTrackingData()), 'emails.text.donation.giftaid-chaseup')
            ->applyLogging('GiftAidChaseUp');
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

    protected function getSubject(): string
    {
        return 'Could Freegle collect Gift Aid on your donation?';
    }
}
