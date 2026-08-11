<?php

namespace App\Mail\Volunteering;

use App\Mail\MjmlMailable;
use App\Mail\Traits\TrackableEmail;
use App\Services\DonateLinkService;
use App\Services\UnsubscribeService;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

class VolunteeringDigestMail extends MjmlMailable
{
    use TrackableEmail;

    protected function unsubscribeType(): ?string
    {
        return UnsubscribeService::TYPE_VOLUNTEERING;
    }

    /**
     * @param array $volunteerings Deduplicated opportunities across all the
     *                             recipient's volunteering-enabled groups (plus
     *                             global opportunities). Each carries a 'groups'
     *                             array of ['name' => , 'url' => ] pairs for the
     *                             recipient's groups it was posted on (empty for
     *                             global opportunities).
     */
    public function __construct(
        public readonly string $recipientEmail,
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
            ['vol_count' => count($this->volunteerings)]
        );
    }

    protected function getSubject(): string
    {
        return 'Volunteer opportunities near you';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org'),
                config('freegle.site_name', 'Freegle')
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
            'volunteerings'  => $this->volunteerings,
            'userSite'       => $userSite,
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'email'          => $this->recipientEmail,
            'jobAds'         => $jobAds,
            'jobsUrl'        => $this->trackedUrl("{$userSite}/jobs", 'jobs_link', 'jobs'),
            // Our own Stripe donate page rather than the PayPal-only shortlink,
            // so Apple Pay / Google Pay / Link are on offer too. USER_SITE is on
            // the Go API's isValidRedirectURL allow-list, so the tracked
            // redirect still resolves. See DonateLinkService.
            'donateUrl'      => $this->trackedUrl(
                app(DonateLinkService::class)->urlForUserId(
                    $this->userId,
                    app(DonateLinkService::class)->defaultAmount(),
                    'volunteeringdigest'
                ),
                'donate_link',
                'donate'
            ),
            'donateMarksUrl' => config('freegle.images.paymethods'),
        ]);
    }
}
