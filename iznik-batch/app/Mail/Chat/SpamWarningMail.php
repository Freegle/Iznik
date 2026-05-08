<?php

namespace App\Mail\Chat;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Models\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class SpamWarningMail extends MjmlMailable
{
    use LoggableEmail;

    public User $recipient;

    public string $spammerName;

    public ?string $messageSubject;

    public string $replyAddress;

    public string $replyName;

    public string $siteName;

    public function __construct(
        User $recipient,
        string $spammerName,
        ?string $messageSubject,
        string $replyTo,
        string $replyName
    ) {
        parent::__construct();

        $this->recipient = $recipient;
        $this->spammerName = $spammerName;
        $this->messageSubject = $messageSubject;
        $this->replyAddress = $replyTo;
        $this->replyName = $replyName;
        $this->siteName = config('freegle.branding.name', 'Freegle');
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->recipient->id ?? null;
    }

    public function build(): static
    {
        return $this->to($this->recipient->email_preferred, $this->recipient->displayname ?? $this->recipient->fullname)
            ->replyTo($this->replyAddress, $this->replyName)
            ->subject($this->getSubject())
            ->mjmlView('emails.mjml.chat.spam-warning', [
                'recipient'      => $this->recipient,
                'spammerName'    => $this->spammerName,
                'messageSubject' => $this->messageSubject,
                'siteName'       => $this->siteName,
            ], 'emails.text.chat.spam-warning')
            ->applyLogging('SpamWarning');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                $this->replyAddress,
                $this->replyName
            ),
            replyTo: [new Address($this->replyAddress, $this->replyName)],
            subject: $this->getSubject(),
        );
    }

    protected function getSubject(): string
    {
        return 'A warning from '.$this->siteName.' about '.$this->spammerName;
    }
}
