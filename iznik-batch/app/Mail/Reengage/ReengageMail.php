<?php

namespace App\Mail\Reengage;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One email in the localised re-engagement sequence. The template is one of
 * 'nearby' (stage 1), 'impact' (stage 2) or 'preferences' (stage 3). All the
 * per-user content is resolved up front by ReengageContentService and passed
 * in as $content, so the Mailable itself stays dumb and serialisable.
 *
 * Unlike the older EngageMail this sets the RFC 8058 List-Unsubscribe headers
 * (via configureMessage() + getRecipientUserId), which Gmail/Yahoo require for
 * bulk senders and which the win-back research flags as essential for a
 * re-engagement flow aimed at a lapsed, lower-engagement audience.
 */
class ReengageMail extends MjmlMailable
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $recipientEmail,
        public readonly string $emailSubject,
        public readonly string $template,
        public readonly int $userId,
        public readonly array $content = [],
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        return $this->emailSubject;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org'),
                config('freegle.branding.name', 'Freegle')
            ),
            to: [new Address($this->recipientEmail)],
            subject: $this->getSubject(),
        );
    }

    public function getEmailType(): string
    {
        return 'Reengage';
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->userId > 0 ? $this->userId : null;
    }

    public function build(): static
    {
        $data = array_merge([
            'name'  => $this->recipientName,
            'email' => $this->recipientEmail,
        ], $this->content);

        $this->mjmlView(
            "emails.mjml.reengage.{$this->template}",
            $data,
            "emails.text.reengage.{$this->template}",
        );

        return $this->configureMessage();
    }
}
