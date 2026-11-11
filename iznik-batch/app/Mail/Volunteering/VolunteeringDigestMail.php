<?php

namespace App\Mail\Volunteering;

use App\Mail\Contracts\DescribesMemberContent;
use App\Mail\MjmlMailable;
use App\Mail\Traits\TrackableEmail;
use App\Models\User;
use App\Services\DonateLinkService;
use App\Services\UnsubscribeService;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

class VolunteeringDigestMail extends MjmlMailable implements DescribesMemberContent
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
     *                             global opportunities), and a 'userid' naming its
     *                             poster where known (see about()).
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

    /**
     * Each opportunity names its poster's userid (since the additive fix alongside
     * this); an entry built without one (e.g. an older test fixture, or an
     * opportunity posted before the column was backfilled) simply contributes
     * nothing to users. There is no bucket in about() for a volunteering
     * opportunity itself, only for the people named in it - filter-spool removes
     * this mail once every poster it names is now a spammer, the same as any
     * other member-content mail (plan section 11.8).
     */
    public function about(): array
    {
        $users = [];
        foreach ($this->volunteerings as $opp) {
            if (isset($opp['userid'])) {
                $users[] = (int) $opp['userid'];
            }
        }

        return [
            'chatmessages' => [],
            'messages' => [],
            'newsfeed' => [],
            'users' => array_values(array_unique($users)),
        ];
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

        // The landing page /job/<id> logs source, campaign, position and list length from the
        // query string, as the chat notification's job links carry them; without them a click
        // is logged as source=direct, the bucket scanners and old links fall into.
        $jobCount = $this->jobAds->count();
        $jobAds = $this->jobAds->values()->map(function ($job, $index) use ($userSite, $jobCount) {
            $job->tracked_url = $this->trackedUrl(
                "{$userSite}/job/{$job->id}?source=email&campaign=volunteering_digest" .
                "&position={$index}&list_length={$jobCount}",
                'job_ad_' . $index,
                'job_click'
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
            // redirect still resolves. See DonateLinkService. Null for a donor
            // who asked not to be asked.
            'donateUrl'      => $this->wantsDonationAsks() ? $this->trackedUrl(
                app(DonateLinkService::class)->urlForUserId(
                    $this->userId,
                    app(DonateLinkService::class)->defaultAmount(),
                    'volunteeringdigest'
                ),
                'donate_link',
                'donate'
            ) : NULL,
            'donateMarksUrl' => config('freegle.images.paymethods'),
        ]);
    }

    private function wantsDonationAsks(): bool
    {
        $user = $this->userId ? User::find($this->userId) : NULL;

        return !$user || $user->wantsDonationAsks();
    }
}
