<?php

namespace App\Mail\Chat;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class ChatReviewPendingMail extends MjmlMailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $groupName,
        public readonly int $count,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        $plural = $this->count !== 1 ? 's' : '';
        return "{$this->count} message{$plural} between members waiting for your review on {$this->groupName}";
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.support_addr'),
                config('freegle.branding.name')
            ),
            to: [new Address($this->recipientEmail)],
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        $modSite = config('freegle.sites.mod', 'https://modtools.org');
        $mentorsAddr = config('freegle.mail.mentors_addr', 'mentors@ilovefreegle.org');

        return $this->mjmlView('emails.mjml.chat.review-pending', [
            'groupName'   => $this->groupName,
            'count'       => $this->count,
            'reviewUrl'   => $modSite . '/modtools/chats/review',
            'mentorsAddr' => $mentorsAddr,
            'email'       => $this->recipientEmail,
        ]);
    }
}
