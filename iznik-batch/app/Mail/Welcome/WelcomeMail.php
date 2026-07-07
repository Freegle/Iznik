<?php

namespace App\Mail\Welcome;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Models\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class WelcomeMail extends MjmlMailable
{
    use LoggableEmail, TrackableEmail;

    private ?User $user = NULL;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public string $recipientEmail,
        public ?string $password = NULL,
        public ?int $userId = NULL
    ) {
        parent::__construct();

        if ($this->userId) {
            $this->user = User::find($this->userId);
        }

        $this->initTracking(
            'Welcome',
            $this->recipientEmail,
            $this->userId,
            NULL,
            $this->getSubject()
        );
    }

    /**
     * Get the recipient's user ID for common header tracking.
     */
    protected function getRecipientUserId(): ?int
    {
        return $this->userId;
    }

    /**
     * Get the message envelope.
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

    /**
     * Get the subject line.
     */
    protected function getSubject(): string
    {
        return '💚 Welcome to ' . config('freegle.branding.name') . '!';
    }

    /**
     * Build the message.
     */
    public function build(): static
    {
        $firstName = $this->user?->firstname ?? NULL;
        $userSite = config('freegle.sites.user');

        return $this->mjmlView(
            'emails.mjml.welcome.welcome',
            array_merge([
                'email' => $this->recipientEmail,
                'password' => $this->password,
                'firstName' => $firstName,
                'userSite' => $userSite,
                'giveUrl' => $this->trackedUrl("{$userSite}/give", 'cta_give', 'cta'),
                'findUrl' => $this->trackedUrl("{$userSite}/find", 'cta_find', 'cta'),
                'termsUrl' => $this->trackedUrl("{$userSite}/terms", 'rule_free', 'info'),
                'helpUrl' => $this->trackedUrl("{$userSite}/help", 'rule_nice', 'info'),
                'safetyUrl' => $this->trackedUrl("{$userSite}/safety", 'rule_safe', 'info'),
                'settingsUrl' => $this->trackedUrl("{$userSite}/settings", 'footer_settings', 'settings'),
                'heroImage' => $this->responsiveImage(config('freegle.images.welcome1'), [300, 600, 900], 600),
                'ruleFreeImage' => $this->responsiveImage(config('freegle.images.rule_free')),
                'ruleNiceImage' => $this->responsiveImage(config('freegle.images.rule_nice')),
                'ruleSafeImage' => $this->responsiveImage(config('freegle.images.rule_safe')),
            ], $this->getTrackingData()),
            'emails.text.welcome.welcome'
        )->to($this->recipientEmail)
            ->applyLogging('Welcome');
    }

}
