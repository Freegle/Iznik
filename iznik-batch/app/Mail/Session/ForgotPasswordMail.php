<?php

namespace App\Mail\Session;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Forgot password email with auto-login link to settings page.
 *
 * Matches the legacy User::forgotPassword() email from iznik-server.
 */
class ForgotPasswordMail extends MjmlMailable
{
    use TrackableEmail;
    use LoggableEmail;

    public function __construct(
        public int $userId,
        public string $email,
        public string $resetUrl,
    ) {
        parent::__construct();

        $this->initTracking(
            'ForgotPassword',
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
        return 'Forgot your password?';
    }

    public function build(): static
    {
        return $this->mjmlView(
            'emails.mjml.session.forgot-password',
            array_merge([
                'resetUrl' => $this->resetUrl,
                'email' => $this->email,
            ], $this->getTrackingData())
        )->to($this->email)
            ->applyLogging('ForgotPassword');
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->userId;
    }
}
