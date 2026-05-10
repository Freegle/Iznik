<?php

namespace App\Mail\Stories;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class StoriesToCentralMail extends MjmlMailable
{
    public function __construct(
        public readonly array $stories,
        public readonly string $previewText,
        public readonly string $voteUrl,
        public readonly string $emailSubject,
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
                config('freegle.mail.geeks_addr', 'geeks@ilovefreegle.org'),
                config('freegle.branding.name', 'Freegle')
            ),
            to: [new Address(config('freegle.mail.central_mail_to', 'central@ilovefreegle.org'))],
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        $html = view('emails.stories.central', [
            'stories'     => $this->stories,
            'previewtext' => $this->previewText,
            'vote'        => $this->voteUrl,
        ])->render();

        return $this->html($html);
    }
}
