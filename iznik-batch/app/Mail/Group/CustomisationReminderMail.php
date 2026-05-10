<?php

namespace App\Mail\Group;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Monthly reminder to group mods about missing customisation attributes.
 * Migrated from iznik-server/scripts/cron/group_customisation.php
 */
class CustomisationReminderMail extends Mailable
{
    public function __construct(
        public readonly string $groupName,
        public readonly string $missing,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org'),
            subject: "Reminder - ways to make {$this->groupName} more welcoming",
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.text.group.customisation-reminder',
        );
    }
}
