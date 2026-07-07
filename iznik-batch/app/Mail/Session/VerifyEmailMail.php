<?php

namespace App\Mail\Session;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email verification sent when a user adds a new email address.
 *
 * V1 parity: User::verifyEmail() lines 3822-3896.
 */
class VerifyEmailMail extends MjmlMailable
{
    use TrackableEmail;
    use LoggableEmail;

    public function __construct(
        public readonly int $userId,
        public readonly string $email,
        public readonly string $confirmUrl,
    ) {
        parent::__construct();

        $this->initTracking(
            'VerifyEmail',
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

    public function getSubject(): string
    {
        return 'Please verify your email';
    }

    public function build(): static
    {
        return $this->mjmlView(
            'emails.mjml.session.verify-email',
            array_merge([
                'email' => $this->email,
                'confirmUrl' => $this->confirmUrl,
            ], $this->getTrackingData())
        )->to($this->email)
            ->applyLogging('VerifyEmail');
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->userId;
    }
}
