<?php

namespace App\Mail\Birthday;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class BirthdayMail extends MjmlMailable
{
    public function __construct(
        public readonly string $groupName,
        public readonly string $groupNameShort,
        public readonly int $groupAge,
        public readonly int $groupId,
        public readonly string $recipientEmail,
        public readonly string $fromEmail,
        public readonly string $fromName,
        public readonly array $volunteers,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        return 'Happy Birthday to all us freeglers!';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromEmail, $this->fromName),
            to: [new Address($this->recipientEmail)],
            replyTo: [new Address($this->fromEmail)],
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        $html = view('emails.birthday.birthday', [
            'groupName'      => $this->groupName,
            'groupNameShort' => $this->groupNameShort,
            'groupAge'       => $this->groupAge,
            'email'          => $this->recipientEmail,
            'volunteers'     => $this->volunteers,
        ])->render();

        return $this->html($html);
    }
}
