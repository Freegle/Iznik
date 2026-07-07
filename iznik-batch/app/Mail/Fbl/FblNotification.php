<?php

namespace App\Mail\Fbl;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Models\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class FblNotification extends MjmlMailable
{
    use LoggableEmail;

    public function __construct(
        public User $user,
        public string $recipientEmail
    ) {
        parent::__construct();
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->user->id;
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
        return "We've turned off emails for you";
    }

    public function build(): static
    {
        $userSite = config('freegle.sites.user');
        $settingsUrl = $userSite . '/settings';

        return $this->mjmlView(
            'emails.mjml.fbl.notification',
            [
                'email' => $this->recipientEmail,
                'settingsUrl' => $settingsUrl,
                'unsubscribeUrl' => $settingsUrl,
            ],
            'emails.text.fbl.notification'
        )->to($this->recipientEmail)
            ->applyLogging('FblNotification');
    }
}
