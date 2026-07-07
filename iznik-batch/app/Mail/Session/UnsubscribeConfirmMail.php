<?php

namespace App\Mail\Session;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Unsubscribe confirmation email asking the user to confirm they want to leave Freegle.
 *
 * Matches the legacy User::confirmUnsubscribe() email from iznik-server.
 */
class UnsubscribeConfirmMail extends MjmlMailable
{
    use LoggableEmail;

    public function __construct(
        public int $userId,
        public string $email,
        public string $unsubUrl,
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
            replyTo: [new Address(config('freegle.mail.support_addr'))],
            subject: $this->getSubject(),
        );
    }

    protected function getSubject(): string
    {
        return 'Please confirm you want to leave Freegle';
    }

    public function build(): static
    {
        return $this->mjmlView(
            'emails.mjml.session.unsubscribe-confirm',
            [
                'unsubUrl' => $this->unsubUrl,
                'email' => $this->email,
            ]
        )->to($this->email)
            ->applyLogging('Unsubscribe');
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->userId;
    }
}
