<?php

namespace App\Mail\Donation;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Models\User;
use App\Services\DonateLinkService;
use App\Services\UnsubscribeService;
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
     * src= tag on donate links, so donations can be attributed to this email.
     */
    public const DONATE_SRC = 'donationask';

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
        // Our own Stripe page, not the PayPal shortlink — see DonateLinkService.
        $this->donateUrl = app(DonateLinkService::class)->url($user, null, self::DONATE_SRC);

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

    protected function unsubscribeType(): ?string
    {
        return UnsubscribeService::TYPE_ENGAGEMENT;
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
        $donateLinks = app(DonateLinkService::class)->amountLinks($this->user, self::DONATE_SRC);

        // Track each amount separately so we can see which one people pick.
        foreach ($donateLinks as $i => $link) {
            $donateLinks[$i]['url'] = $this->trackedUrl($link['url'], 'donate_' . $link['amount'], 'donate');
        }

        // Overwrite the public property rather than only passing a tracked copy
        // in the view data: public Mailable properties are shared with the
        // views too, and in the plain-text view they win, which would leave the
        // text part linking to an untracked URL.
        $this->donateUrl = $this->trackedUrl($this->donateUrl, 'donate_button', 'donate');

        return $this->to($this->user->email_preferred, $this->user->displayname)
            ->subject($this->getSubject())
            ->mjmlView('emails.mjml.donation.ask', array_merge([
                'user' => $this->user,
                'userSite' => $this->userSite,
                'itemSubject' => $this->itemSubject,
                'target' => $this->target,
                'donateLinks' => $donateLinks,
                'donateMarksUrl' => config('freegle.images.paymethods'),
                'donateUrl' => $this->donateUrl,
                'settingsUrl' => $this->trackedUrl($this->userSite . '/settings', 'footer_settings', 'settings'),
                // Without this the shared footer falls back to a bare, untracked
                // /unsubscribe, so the one footer link we most want to measure was
                // the only one we could not see. Matches what the digest passes.
                'unsubscribeUrl' => $this->trackedUrl($this->userSite . '/unsubscribe', 'footer_unsubscribe', 'unsubscribe'),
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
