<?php

namespace App\Mail\Donation;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Models\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class DonationThankYou extends MjmlMailable
{
    use LoggableEmail;
    use TrackableEmail;

    public User $user;

    public string $userSite;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user)
    {
        parent::__construct();

        $this->user = $user;
        $this->userSite = config('freegle.sites.user');

        // Initialize email tracking.
        $userId = $this->user->exists ? $this->user->id : null;

        $this->initTracking(
            'DonationThankYou',
            $this->user->email_preferred,
            $userId,
            null,
            $this->getSubject()
        );
    }

    /**
     * Get the recipient's user ID for common header tracking.
     */
    protected function getRecipientUserId(): ?int
    {
        return $this->user->id ?? null;
    }

    /**
     * Build the message.
     */
    public function build(): static
    {
        return $this->to($this->user->email_preferred, $this->user->displayname)
            ->subject($this->getSubject())
            ->mjmlView('emails.mjml.donation.thank-you', array_merge([
                'user' => $this->user,
                'userSite' => $this->userSite,
                'continueUrl' => $this->trackedUrl($this->userSite, 'continue_button', 'continue'),
                'settingsUrl' => $this->trackedUrl($this->userSite . '/settings', 'footer_settings', 'settings'),
            ], $this->getTrackingData()), 'emails.text.donation.thank-you')
            ->applyLogging('DonationThankYou');
    }

    /**
     * Get the subject line.
     */
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
        return 'Thank you for your donation to Freegle!';
    }
}
