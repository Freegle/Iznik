<?php

namespace App\Mail\Volunteering;

use App\Mail\Concerns\BulkRenderable;
use App\Mail\MjmlMailable;
use App\Mail\Traits\TrackableEmail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

class VolunteeringDigestMail extends MjmlMailable implements BulkRenderable
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
            // Keep the freegle.in PayPal short link — it is whitelisted in the Go
            // API's isValidRedirectURL (domain allow-list), so the tracked redirect
            // resolves it correctly. Don't replace the short link with a full URL.
            'donateUrl'      => $this->trackedUrl('https://freegle.in/paypal1510', 'donate_link', 'donate'),
        ]);
    }

    /**
     * Per-group volunteering digest. Members WITHOUT nearby jobs share one
     * compiled body; members WITH jobs see different job sets per user, so
     * they fall into a per-user-unique shape (full compile per user, but the
     * substitute step costs nothing on top of the existing per-send compile).
     */
    public function shapeKey(): string
    {
        if ($this->jobAds->isNotEmpty()) {
            return 'unique-vol-'.($this->userId ?? spl_object_id($this));
        }
        return 'volunteering-digest-'.sha1(
            $this->groupName.'|'.json_encode($this->volunteerings, JSON_THROW_ON_ERROR)
        );
    }

    public function bulkTemplate(): string
    {
        return 'emails.mjml.volunteering.digest';
    }

    public function bulkData(): array
    {
        $userSite = config('freegle.sites.user');

        // Mirror build(): each job gets a placeholder for its per-user tracked
        // URL. Within a "has-jobs" shape (unique per user), the user's actual
        // jobs go through bulkData this way too — no extra benefit but no
        // regression either, and the shape key keeps that bucket isolated.
        $jobAds = $this->jobAds->map(function ($job, $idx) {
            $clone = clone $job;
            $clone->tracked_url = '{{job_'.$idx.'_url}}';
            return $clone;
        });

        return [
            'groupName'      => $this->groupName,
            'volunteerings'  => $this->volunteerings,
            'userSite'       => $userSite,
            'unsubscribeUrl' => '{{unsubscribeUrl}}',
            'email'          => '{{recipientEmail}}',
            'jobAds'         => $jobAds,
            'jobsUrl'        => '{{jobsUrl}}',
            'donateUrl'      => '{{donateUrl}}',
        ];
    }

    public function mergeVars(): array
    {
        $userSite = config('freegle.sites.user');

        $vars = [
            'recipientEmail' => $this->recipientEmail,
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'jobsUrl' => $this->trackedUrl("{$userSite}/jobs", 'jobs_link', 'jobs'),
            'donateUrl' => $this->trackedUrl('https://freegle.in/paypal1510', 'donate_link', 'donate'),
        ];

        foreach ($this->jobAds as $idx => $job) {
            $vars["job_{$idx}_url"] = $this->trackedUrl(
                "{$userSite}/job/{$job->id}",
                'job_ad',
                'jobs'
            );
        }

        return $vars;
    }
}
