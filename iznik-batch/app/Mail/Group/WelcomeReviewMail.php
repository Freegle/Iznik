<?php

namespace App\Mail\Group;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email sent to group mods with their welcome mail for annual review.
 *
 * V1 parity: group_welcomereview.php
 */
class WelcomeReviewMail extends MjmlMailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $groupName,
        public readonly string $welcomeContent,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        return "Please review: Welcome to {$this->groupName}";
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.support_addr', 'support@ilovefreegle.org'),
                "{$this->groupName} Volunteers"
            ),
            to: [new Address($this->recipientEmail)],
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        $modSite = config('freegle.sites.mod', 'https://modtools.org');

        return $this->mjmlView('emails.mjml.group.welcome-review', [
            'groupName'      => $this->groupName,
            'welcomeContent' => $this->welcomeContent,
            'modSite'        => $modSite,
            'email'          => $this->recipientEmail,
        ]);
    }
}
