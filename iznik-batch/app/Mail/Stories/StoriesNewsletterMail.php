<?php

namespace App\Mail\Stories;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class StoriesNewsletterMail extends MjmlMailable
{
    public function __construct(
        public readonly int    $userId,
        public readonly string $recipientName,
        public readonly string $recipientEmail,
        public readonly array  $stories,
        public readonly string $headerImageUrl,
        public readonly string $tellUrl,
        public readonly string $giveUrl,
        public readonly string $findUrl,
        public readonly string $previewText,
        public readonly string $unsubscribeUrl,
        public readonly string $settingsUrl,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        return 'Lovely stories from other freeglers!';
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->userId;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org'),
                config('freegle.branding.name', 'Freegle')
            ),
            to: [new Address($this->recipientEmail, $this->recipientName)],
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        return $this->mjmlView('emails.mjml.stories.newsletter', [
            'name'           => $this->recipientName,
            'email'          => $this->recipientEmail,
            'stories'        => $this->stories,
            'headerImageUrl' => $this->headerImageUrl,
            'tellUrl'        => $this->tellUrl,
            'giveUrl'        => $this->giveUrl,
            'findUrl'        => $this->findUrl,
            'previewText'    => $this->previewText,
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'settingsUrl'    => $this->settingsUrl,
        ], 'emails.text.stories.newsletter');
    }
}
