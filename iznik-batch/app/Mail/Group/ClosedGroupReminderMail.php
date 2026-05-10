<?php

namespace App\Mail\Group;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Weekly reminder to mods that their group is currently closed.
 * Migrated from iznik-server/scripts/cron/groups_closed.php
 */
class ClosedGroupReminderMail extends Mailable
{
    public function __construct(
        public readonly string $groupName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: config('freegle.mail.geeks_addr', 'geeks@ilovefreegle.org'),
            subject: 'Reminder: Your Freegle group is currently closed',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.text.group.closed-reminder',
        );
    }
}
