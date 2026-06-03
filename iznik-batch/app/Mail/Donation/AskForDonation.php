<?php

namespace App\Mail\Donation;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Models\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class AskForDonation extends MjmlMailable
{
    use LoggableEmail;
    use TrackableEmail;

    public User $user;

    public ?string $itemSubject;

    public string $userSite;

    public float $target;

    public string $donateUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, ?string $itemSubject = null)
    {
        parent::__construct();

        $this->user = $user;
        $this->itemSubject = $itemSubject;
        $this->userSite = config('freegle.sites.user');
        $this->target = config('freegle.donation.target', 2500);
        $this->donateUrl = config('freegle.donation.url', 'http://freegle.in/paypal1510');

        // Initialize email tracking.
        $userId = $this->user->exists ? $this->user->id : null;

        $this->initTracking(
            'AskForDonation',
            $this->user->email_preferred,
            $userId,
            null,
            $this->getSubject(),
            [
                'item_subject' => $this->itemSubject,
            ]
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
            ->mjmlView('emails.mjml.donation.ask', array_merge([
                'user' => $this->user,
                'userSite' => $this->userSite,
                'itemSubject' => $this->itemSubject,
                'target' => $this->target,
                'donateUrl' => $this->trackedUrl($this->donateUrl, 'donate_button', 'donate'),
                'settingsUrl' => $this->trackedUrl($this->userSite . '/settings', 'footer_settings', 'settings'),
                'continueUrl' => $this->trackedUrl($this->userSite, 'continue_button', 'continue'),
            ], $this->getTrackingData()), 'emails.text.donation.ask')
            ->applyLogging('AskForDonation');
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
        return $this->itemSubject
            ? "Regarding: {$this->itemSubject}"
            : "Thanks for freegling!";
    }
}
