<?php

namespace App\Mail\Group;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class BoundaryErrorMail extends MjmlMailable
{
    public function __construct(
        public int $groupId,
        public string $groupName,
        public string $errorMessage,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        return "Invalid CGA/DPA for {$this->groupId} {$this->groupName}";
    }

    public function envelope(): Envelope
    {
        $geeksAddr = config('freegle.mail.geeks_addr', 'geeks@ilovefreegle.org');

        return new Envelope(
            from: new Address(
                config('freegle.mail.from_address', 'geeks@ilovefreegle.org')
            ),
            to: [new Address($geeksAddr)],
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        $geeksAddr = config('freegle.mail.geeks_addr', 'geeks@ilovefreegle.org');

        return $this->mjmlView('emails.mjml.group.boundary-error', [
            'groupId'      => $this->groupId,
            'groupName'    => $this->groupName,
            'errorMessage' => $this->errorMessage,
            'email'        => $geeksAddr,
        ]);
    }
}
