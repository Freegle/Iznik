<?php

namespace App\Mail\Volunteering;

use App\Mail\Contracts\DescribesMemberContent;
use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Services\UnsubscribeService;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Is this volunteer opportunity still active?" renewal request.
 *
 * Ported from the legacy V1 PHP volunteerrenew mail template +
 * Volunteering::askRenew(). Sent to the owner of dateless opportunities that are
 * approaching expiry. One email per owner per cycle lists every opportunity that
 * needs confirming; each has its own auto-login link to /volunteering/{id}
 * where the owner can renew (Go API sets renewed=NOW(), expired=0).
 */
class VolunteeringRenewMail extends MjmlMailable implements DescribesMemberContent
{
    use LoggableEmail;
    use TrackableEmail;

    /**
     * @param array $opportunities One entry per opportunity:
     *                             ['id' => int, 'title' => string, 'groupName' => string, 'renewUrl' => string].
     */
    public function __construct(
        public readonly string $recipientEmail,
        public readonly array $opportunities,
        public readonly ?int $userId = null,
    ) {
        parent::__construct();

        $this->initTracking(
            'VolunteeringRenew',
            $this->recipientEmail,
            $this->userId,
            null,
            $this->getSubject(),
            [
                'volunteering_ids' => array_map(fn ($o) => (int) $o['id'], $this->opportunities),
                'vol_count' => count($this->opportunities),
            ]
        );
    }

    protected function getSubject(): string
    {
        if (count($this->opportunities) === 1) {
            // Matches the legacy V1 PHP Volunteering::askRenew(): "Regarding: {title}".
            return 'Regarding: ' . $this->opportunities[0]['title'];
        }

        return 'Are your ' . count($this->opportunities) . ' volunteer opportunities still active?';
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
        // Attach the standard X-Freegle-* tracking and RFC 8058 List-Unsubscribe
        // headers (the latter keyed off getRecipientUserId() below).
        $this->configureMessage();

        $opportunities = array_map(fn ($o) => [
            'id' => $o['id'],
            'title' => $o['title'],
            'groupName' => $o['groupName'],
            'renewUrl' => $this->trackedUrl($o['renewUrl'], 'renew_' . $o['id'], 'renew'),
        ], $this->opportunities);

        return $this->mjmlView('emails.mjml.volunteering.renew', array_merge([
            'opportunities'  => $opportunities,
            'email'          => $this->recipientEmail,
            'unsubscribeUrl' => config('freegle.sites.user') . '/unsubscribe',
        ], $this->getTrackingData()))
            ->applyLogging('VolunteeringRenew');
    }

    protected function unsubscribeType(): ?string
    {
        return UnsubscribeService::TYPE_VOLUNTEERING;
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->userId;
    }

    /**
     * Names the opportunities' owner - the recipient is always the owner here, so
     * there is no separate "author" to distinguish (plan section 11.8). There is
     * no bucket for a volunteering opportunity itself, only for the people named
     * in it; filter-spool removes this mail once the owner is now a spammer.
     */
    public function about(): array
    {
        return [
            'chatmessages' => [],
            'messages' => [],
            'newsfeed' => [],
            'users' => $this->userId !== null ? [$this->userId] : [],
        ];
    }
}
