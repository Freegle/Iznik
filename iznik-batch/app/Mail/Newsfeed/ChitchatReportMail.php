<?php

namespace App\Mail\Newsfeed;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * MJML email sent to ChitChat support when a user reports a newsfeed post.
 *
 * Matches the legacy Newsfeed::report() email from iznik-server.
 */
class ChitchatReportMail extends MjmlMailable
{
    use LoggableEmail;

    public function __construct(
        public string $reporterName,
        public int $reporterId,
        public string $reporterEmail,
        public int $newsfeedId,
        public string $reason,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.geeks_addr'),
                config('freegle.branding.name')
            ),
            subject: $this->getSubject(),
        );
    }

    protected function getSubject(): string
    {
        return "{$this->reporterName} #{$this->reporterId} ({$this->reporterEmail}) has reported a ChitChat thread";
    }

    public function build(): static
    {
        $userSite = config('freegle.sites.user');
        $threadUrl = "{$userSite}/chitchat/{$this->newsfeedId}";
        $recipients = array_map('trim', explode(',', config('freegle.mail.chitchat_support_addr')));

        return $this->mjmlView(
            'emails.mjml.newsfeed.chitchat-report',
            [
                'reporterName' => $this->reporterName,
                'reporterId' => $this->reporterId,
                'reporterEmail' => $this->reporterEmail,
                'newsfeedId' => $this->newsfeedId,
                'reason' => $this->reason,
                'threadUrl' => $threadUrl,
            ]
        )->to($recipients)
            ->applyLogging('ChitchatReport');
    }
}
