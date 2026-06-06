<?php

namespace App\Mail\Volunteering;

use App\Mail\MjmlMailable;
use App\Mail\Traits\TrackableEmail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

class VolunteeringDigestMail extends MjmlMailable
{
    use TrackableEmail;
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $groupName,
        public readonly array $volunteerings,
        public readonly string $unsubscribeUrl,
        public readonly Collection $jobAds = new Collection(),
        public readonly ?int $userId = null,
    ) {
        parent::__construct();

        $this->initTracking(
            'VolunteeringDigest',
            $this->recipientEmail,
            $this->userId,
            null,
            $this->getSubject(),
            ['group' => $this->groupName, 'vol_count' => count($this->volunteerings)]
        );
    }

    protected function getSubject(): string
    {
        return "[{$this->groupName}] Volunteer Opportunity Roundup";
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org'),
                $this->groupName
            ),
            to: [new Address($this->recipientEmail)],
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        $userSite = config('freegle.sites.user');

        $jobAds = $this->jobAds->map(function ($job) use ($userSite) {
            $job->tracked_url = $this->trackedUrl(
                "{$userSite}/job/{$job->id}",
                'job_ad',
                'jobs'
            );
            return $job;
        });

        return $this->mjmlView('emails.mjml.volunteering.digest', [
            'groupName'      => $this->groupName,
            'volunteerings'  => $this->volunteerings,
            'userSite'       => $userSite,
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'email'          => $this->recipientEmail,
            'jobAds'         => $jobAds,
            'jobsUrl'        => $this->trackedUrl("{$userSite}/jobs", 'jobs_link', 'jobs'),
            'donateUrl'      => $this->trackedUrl("{$userSite}/donate", 'donate_link', 'donate'),
        ]);
    }
}
