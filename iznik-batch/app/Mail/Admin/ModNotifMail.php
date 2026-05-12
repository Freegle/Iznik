<?php

namespace App\Mail\Admin;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class ModNotifMail extends MjmlMailable
{
    public string $recipientName;

    public string $htmlSummary;

    public string $textSummary;

    public string $settingsUrl;

    private string $modNotifSubject;

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
        $this->htmlSummary = $htmlSummary;
        $this->textSummary = $textSummary;
        $this->modNotifSubject = $subject;
        $this->settingsUrl = 'https://' . config('freegle.sites.mod', 'modtools.org') . '/settings';
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
            'htmlSummary' => $this->htmlSummary,
            'settingsUrl' => $this->settingsUrl,
        ];

        return $this
            ->subject($this->getSubject())
            ->mjmlView('emails.mjml.admin.mod-notif', $data, 'emails.text.admin.mod-notif');
    }
}
