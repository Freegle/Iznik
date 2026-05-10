<?php

namespace App\Mail\Group;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Plain text email sent to group mods with their welcome mail for annual review.
 *
 * V1 parity: group_welcomereview.php
 */
class WelcomeReviewMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $groupName,
        public readonly string $welcomeContent,
        public readonly string $modSite,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.support_addr'),
                "{$this->groupName} Volunteers"
            ),
            subject: "Please review: Welcome to {$this->groupName}",
        );
    }

    public function build(): static
    {
        $body = "This is the welcome mail that gets sent to members who join {$this->groupName}.\r\n\r\n"
            . "We send you this once a year so you can check it's up-to-date. If you'd like to edit it, "
            . "you can do so at {$this->modSite}/modtools/settings/.\r\n\r\n"
            . "---\r\n\r\n"
            . $this->welcomeContent . "\r\n\r\n"
            . "---\r\n\r\n"
            . "Freegle";

        return $this->text('emails.plain.refer-to-support', ['body' => $body]);
    }
}
