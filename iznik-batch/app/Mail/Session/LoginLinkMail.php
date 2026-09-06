<?php

namespace App\Mail\Session;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sign-in link email for passwordless deployments (freegle.auth.passwordless).
 *
 * Sent in place of ForgotPasswordMail when a member asks to sign in: the link
 * carries the same u/k credentials the API minted for a password reset, but
 * lands where the web app signs the member straight in. Freegle itself never
 * sends this - the switch is off by default.
 */
class LoginLinkMail extends MjmlMailable
{
    use TrackableEmail;
    use LoggableEmail;

    public function __construct(
        public int $userId,
        public string $email,
        public string $loginUrl,
    ) {
        parent::__construct();

        $this->initTracking(
            'LoginLink',
            $this->email,
            $this->userId,
            null,
            $this->getSubject()
        );
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
        return 'Your sign-in link for '.config('freegle.branding.name');
    }

    public function build(): static
    {
        return $this->mjmlView(
            'emails.mjml.session.login-link',
            array_merge([
                'loginUrl' => $this->loginUrl,
                'email' => $this->email,
                'siteName' => config('freegle.branding.name'),
            ], $this->getTrackingData())
        )->to($this->email)
            ->applyLogging('LoginLink');
    }

    /**
     * Transactional - they asked for it seconds ago - so it carries no List-Unsubscribe.
     */
    protected function unsubscribeType(): ?string
    {
        return null;
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->userId;
    }
}
