<?php

namespace App\Mail\Charity;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class CharitySignupMail extends MjmlMailable
{
    use LoggableEmail;

    public function __construct(
        public int $charityId,
        public string $orgName,
        public string $orgType,
        public ?string $charityNumber,
        public string $contactEmail,
        public ?string $contactName,
        public ?string $website,
        public ?string $description,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr'),
                config('freegle.branding.name')
            ),
            subject: $this->getSubject(),
        );
    }

    protected function getSubject(): string
    {
        return "New Charity Partner signup: {$this->orgName}";
    }

    public function build(): static
    {
        $partnershipsAddr = config('freegle.mail.partnerships_addr');

        return $this->mjmlView(
            'emails.mjml.charity.charity-signup',
            [
                'charityId' => $this->charityId,
                'orgName' => $this->orgName,
                'orgType' => $this->orgType,
                'charityNumber' => $this->charityNumber,
                'contactEmail' => $this->contactEmail,
                'contactName' => $this->contactName,
                'website' => $this->website,
                'description' => $this->description,
            ]
        )->to($partnershipsAddr)
            ->applyLogging('CharitySignup');
    }
}
