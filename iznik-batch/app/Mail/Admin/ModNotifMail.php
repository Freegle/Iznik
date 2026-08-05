<?php

namespace App\Mail\Admin;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class ModNotifMail extends MjmlMailable
{
    public string $recipientName;

    public string $email;

    public string $htmlSummary;

    public string $textSummary;

    public string $settingsUrl;

    private string $modNotifSubject;

    /**
     * Transactional - a moderator notification - so it carries no List-Unsubscribe.
     */
    protected function unsubscribeType(): ?string
    {
        return null;
    }

    public function __construct(
        string $recipientName,
        string $recipientEmail,
        ?int $recipientUserId,
        string $subject,
        string $htmlSummary,
        string $textSummary
    ) {
        parent::__construct();

        $this->recipientName = $recipientName;
        $this->email = $recipientEmail;
        $this->htmlSummary = $htmlSummary;
        $this->textSummary = $textSummary;
        $this->modNotifSubject = $subject;
        $this->settingsUrl = config('freegle.sites.mod', 'https://modtools.org') . '/modtools/settings';
    }

    protected function getSubject(): string
    {
        return $this->modNotifSubject;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org'),
                'ModTools'
            ),
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        $data = [
            'recipientName' => $this->recipientName,
            'email' => $this->email,
            'htmlSummary' => $this->htmlSummary,
            'settingsUrl' => $this->settingsUrl,
            // The count-bearing subject (e.g. "MODERATE: 5 things to do") so the inbox
            // preview shows how much is waiting, not a generic line.
            'modNotifSubject' => $this->modNotifSubject,
        ];

        return $this
            ->subject($this->getSubject())
            ->mjmlView('emails.mjml.admin.mod-notif', $data, 'emails.text.admin.mod-notif');
    }
}
