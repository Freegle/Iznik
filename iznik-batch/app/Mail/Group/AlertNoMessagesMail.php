<?php

namespace App\Mail\Group;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Alert to geeks about groups with no recent messages.
 * Migrated from iznik-server/scripts/cron/groups_nomessages.php
 */
class AlertNoMessagesMail extends Mailable
{
    public function __construct(
        public readonly int $count,
        public readonly string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: config('freegle.mail.geeks_addr', 'geeks@ilovefreegle.org'),
            subject: "WARNING: {$this->count} groups not receiving messages on Iznik",
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.text.group.alert-no-messages',
        );
    }
}
