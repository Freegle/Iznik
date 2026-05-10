<?php

namespace App\Mail\Group;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Alert to geeks about groups with no recent messages.
 * Migrated from iznik-server/scripts/cron/groups_nomessages.php
 */
class AlertNoMessagesMail extends MjmlMailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly int $count,
        public readonly string $body,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        return "WARNING: {$this->count} groups not receiving messages on Iznik";
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.geeks_addr', 'geeks@ilovefreegle.org'),
                config('freegle.branding.name', 'Freegle')
            ),
            to: [new Address($this->recipientEmail)],
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        return $this->mjmlView('emails.mjml.group.alert-no-messages', [
            'count' => $this->count,
            'body'  => $this->body,
            'email' => $this->recipientEmail,
        ]);
    }
}
