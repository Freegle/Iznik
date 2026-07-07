<?php

namespace App\Mail\Message;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Models\Message;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class AutoRepostWarning extends MjmlMailable
{
    use TrackableEmail;
    use LoggableEmail;

    /**
     * Create a new message instance.
     *
     * V1: "Will Repost: {subject}" warning email sent ~24h before auto-repost.
     * Gives user chance to mark as TAKEN/RECEIVED, withdraw, or promise.
     */
    public function __construct(
        public int $messageId,
        public string $messageSubject,
        public string $messageType,
        public int $userId,
        public string $userName,
        public string $userEmail,
        public ?int $groupId = null,
    ) {
        parent::__construct();

        $this->initTracking(
            'AutoRepostWarning',
            $this->userEmail,
            $this->userId,
            $this->groupId,
            $this->getSubject(),
            ['message_id' => $this->messageId]
        );
    }

    public function build(): static
    {
        $userSite = config('freegle.sites.user');
        $isOffer = $this->messageType === Message::TYPE_OFFER;
        $outcomeType = $isOffer ? Message::OUTCOME_TAKEN : Message::OUTCOME_RECEIVED;

        return $this->to($this->userEmail, $this->userName)
            ->subject($this->getSubject())
            ->mjmlView('emails.mjml.message.autorepost-warning', array_merge([
                'userName' => $this->userName,
                'messageSubject' => $this->messageSubject,
                'outcomeType' => $outcomeType,
                'isOffer' => $isOffer,
                'completedUrl' => $this->trackedUrl(
                    "{$userSite}/mypost/{$this->messageId}/completed",
                    'completed_button',
                    'completed'
                ),
                'withdrawUrl' => $this->trackedUrl(
                    "{$userSite}/mypost/{$this->messageId}/withdraw",
                    'withdraw_button',
                    'withdraw'
                ),
                'promiseUrl' => $this->trackedUrl(
                    "{$userSite}/mypost/{$this->messageId}/promise",
                    'promise_button',
                    'promise'
                ),
                'settingsUrl' => $this->trackedUrl(
                    "{$userSite}/settings",
                    'footer_settings',
                    'settings'
                ),
                'email' => $this->userEmail,
            ], $this->getTrackingData()))
            ->applyLogging('AutoRepostWarning');
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
        return 'Will Repost: ' . $this->messageSubject;
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->userId;
    }
}
